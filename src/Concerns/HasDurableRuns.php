<?php

declare(strict_types=1);

namespace Laragentic\Concerns;

use Illuminate\Support\Str;
use Laragentic\Exceptions\RunCancelledException;
use Laragentic\Loops\LoopResult;
use Laragentic\Loops\LoopStep;
use Laragentic\Runs\AgentCheckpoint;
use Laragentic\Runs\AgentRun;
use Laragentic\Runs\AgentToolCall;
use Laragentic\Runs\RunStatus;
use Laragentic\Signals\AskHumanSignal;
use Laragentic\Exceptions\MaxIterationsExceededException;

/**
 * Adds durable run semantics to any agent that also uses a loop trait.
 *
 * Mix this trait into an agent alongside ReActLoop to get:
 *
 *   • Run IDs — every execution gets a UUID stored in `agent_runs`
 *   • Checkpoints — each iteration's state is saved to `agent_checkpoints`
 *   • Resume — restart from the last checkpoint after a crash/deploy
 *   • Cancellation — poll the DB between iterations; throw on cancel
 *   • Idempotency — tool calls are stored and replayed on retry
 *
 * Usage:
 *
 *     class MyAgent implements Agent, HasTools
 *     {
 *         use Promptable, ReActLoop, HasDurableRuns;
 *
 *         public function tools(): iterable { ... }
 *     }
 *
 *     // Start a new run
 *     $result = (new MyAgent)->durableReactLoop('Summarise this report', runId: 'run_xyz');
 *
 *     // Resume after a crash (same run_id)
 *     $result = (new MyAgent)->durableReactLoop('Summarise this report', runId: 'run_xyz');
 *
 *     // Cancel from outside (e.g. HTTP endpoint)
 *     MyAgent::cancelRun('run_xyz');
 *
 * @requires ExecutesLoopTools  (provided by ReActLoop via HasIterationCallbacks)
 * @requires HasCallbacks        (provided by ExecutesLoopTools)
 */
trait HasDurableRuns
{
    // ─── Configuration ───────────────────────────────────────────────

    /**
     * How often (iterations) to poll the DB for a cancellation signal.
     *
     * null = read from config('agentic.runs.cancellation_poll_every', 1).
     * Set via withCancellationPoll() to override config per-instance.
     */
    protected ?int $cancellationPollEvery = null;

    /**
     * Whether to use idempotency for tool calls.
     *
     * null = read from config('agentic.runs.tool_idempotency', true).
     * Set to false via withoutToolIdempotency() for read-only tools.
     */
    protected ?bool $durableToolIdempotency = null;

    // ─── Fluent Configuration ────────────────────────────────────────

    public function withCancellationPoll(int $everyNIterations): static
    {
        $this->cancellationPollEvery = max(1, $everyNIterations);

        return $this;
    }

    public function withoutToolIdempotency(): static
    {
        $this->durableToolIdempotency = false;

        return $this;
    }

    // ─── Internal Config Resolution ──────────────────────────────────

    /**
     * Resolve runtime values for config-driven properties.
     *
     * Called once at the top of durableReactLoop(). If a property has
     * not been overridden via a fluent setter (i.e. it is still null),
     * the value is read from config with a sensible fallback.
     */
    private function resolveDurableConfig(): void
    {
        $this->cancellationPollEvery ??= max(1, (int) config('agentic.runs.cancellation_poll_every', 1));
        $this->durableToolIdempotency ??= (bool) config('agentic.runs.tool_idempotency', true);
    }

    // ─── Public API ──────────────────────────────────────────────────

    /**
     * Execute the ReAct loop with full durability: persistence, resume,
     * cancellation, and tool-call idempotency.
     *
     * Passing a `$runId` for the first time creates a new run.
     * Passing the same `$runId` again resumes from the last checkpoint.
     * Omitting `$runId` auto-generates a UUID.
     *
     * When resuming a paused run after an AskHumanSignal, pass the
     * human's answer via `$humanReply`. It is appended to the prompt
     * that was saved at pause time so the LLM sees both the pending
     * question and the answer on the next iteration.
     *
     * @throws RunCancelledException          If the run is cancelled mid-loop.
     * @throws MaxIterationsExceededException If max iterations are reached and throw_on_max_iterations is true.
     */
    public function durableReactLoop(
        string $prompt,
        ?string $runId = null,
        ?string $humanReply = null,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): LoopResult {
        $this->resolveDurableConfig();

        $run = $this->initDurableRun(
            runId: $runId,
            loopType: 'react',
            initialPrompt: $prompt,
        );

        // ── Resume State ─────────────────────────────────────────────
        $iteration     = 0;
        $steps         = [];
        $currentPrompt = $prompt;
        $response      = null;

        if ($run->hasCheckpoints()) {
            /** @var AgentCheckpoint $last */
            $last          = $run->latestCheckpoint();
            $iteration     = $last->iteration;
            $currentPrompt = $last->next_prompt ?? $prompt;

            // When resuming a paused AskHuman run, append the human's
            // reply so the LLM sees both the outstanding question and
            // the answer on the very next iteration.
            if ($humanReply !== null) {
                $currentPrompt .= "\n\nHuman reply: " . $humanReply;
            }

            // Reconstruct LoopStep history so allToolCalls() works correctly
            foreach ($run->checkpoints as $cp) {
                $steps[] = $this->checkpointToLoopStep($cp);
            }
        }

        $maxIterations = $this->resolveMaxIterations();

        $run->markRunning();
        $this->fireCallbacks('loopStart', $currentPrompt);

        // ── Main Loop ────────────────────────────────────────────────
        while ($iteration < $maxIterations) {
            $iteration++;

            // Poll for external cancellation
            if ($iteration % $this->cancellationPollEvery === 0) {
                if ($run->freshStatus() === RunStatus::Cancelled) {
                    throw new RunCancelledException($run->run_id);
                }
            }

            $this->fireCallbacks('iterationStart', $iteration);

            // ── THOUGHT ─────────────────────────────────────────────
            $this->fireCallbacks('beforeThought', $currentPrompt, $iteration);

            $response = $this->prompt(
                prompt: $currentPrompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $this->fireCallbacks('afterThought', $response, $iteration);

            // ── TERMINATE? ──────────────────────────────────────────
            if ($this->loopShouldTerminate($response)) {
                // Scan SDK-resolved tool results for AskHumanSignal
                foreach ($response->toolResults as $toolResult) {
                    $resultStr = (string) $toolResult->result;

                    if (AskHumanSignal::isSignal($resultStr)) {
                        $signal = AskHumanSignal::fromString($resultStr);

                        $this->saveCheckpoint($run, $iteration, $currentPrompt, $response, [], null, null);

                        $steps[] = new LoopStep(
                            iteration: $iteration,
                            response: $response,
                        );

                        $run->markPaused();

                        $this->fireCallbacks('iterationEnd', $iteration, $response);
                        $this->fireCallbacks('askHuman', $signal, $iteration);

                        return new LoopResult(
                            response: $response,
                            iterations: $iteration,
                            steps: $steps,
                            askHumanSignal: $signal,
                        );
                    }
                }

                $this->fireSdkResolvedToolCallbacks($response, $iteration);

                $this->saveCheckpoint($run, $iteration, $currentPrompt, $response, [], null, null);

                $steps[] = new LoopStep(
                    iteration: $iteration,
                    response: $response,
                );

                $run->markCompleted($response->text);

                $this->fireCallbacks('iterationEnd', $iteration, $response);
                $this->fireCallbacks('loopComplete', $response, $iteration);

                return new LoopResult(
                    response: $response,
                    iterations: $iteration,
                    steps: $steps,
                );
            }

            // ── ACTION ──────────────────────────────────────────────
            $toolCallRecords = $this->executeDurableToolCalls($run, $response, $iteration);

            // Poll cancellation after potentially long-running tool calls
            if ($run->freshStatus() === RunStatus::Cancelled) {
                throw new RunCancelledException($run->run_id);
            }

            // ── ASK HUMAN ───────────────────────────────────────────
            if ($this->detectedAskHuman !== null) {
                $signal = $this->detectedAskHuman;
                $this->detectedAskHuman = null;

                $this->saveCheckpoint($run, $iteration, $currentPrompt, $response, $toolCallRecords, null, null);

                $steps[] = new LoopStep(
                    iteration: $iteration,
                    response: $response,
                    toolCalls: $toolCallRecords,
                );

                $run->markPaused();

                $this->fireCallbacks('iterationEnd', $iteration, $response);
                $this->fireCallbacks('askHuman', $signal, $iteration);

                return new LoopResult(
                    response: $response,
                    iterations: $iteration,
                    steps: $steps,
                    askHumanSignal: $signal,
                );
            }

            // ── OBSERVATION ─────────────────────────────────────────
            $observation = $this->buildReActObservation($toolCallRecords);

            $this->fireCallbacks('observation', $observation, $iteration);

            // Save checkpoint — observation becomes next iteration's prompt
            $this->saveCheckpoint(
                run: $run,
                iteration: $iteration,
                prompt: $currentPrompt,
                response: $response,
                toolCallRecords: $toolCallRecords,
                observation: $observation,
                nextPrompt: $observation,
            );

            $steps[] = new LoopStep(
                iteration: $iteration,
                response: $response,
                toolCalls: $toolCallRecords,
                observation: $observation,
            );

            $currentPrompt = $observation;

            $this->fireCallbacks('iterationEnd', $iteration, $response);
        }

        // ── MAX ITERATIONS ───────────────────────────────────────────
        $this->fireCallbacks('maxIterationsReached', $response, $iteration);

        $run->markFailed("Max iterations ({$maxIterations}) reached.");

        $result = new LoopResult(
            response: $response,
            iterations: $iteration,
            steps: $steps,
            reachedMaxIterations: true,
        );

        if (config('agentic.react.throw_on_max_iterations', false)) {
            throw new MaxIterationsExceededException($maxIterations, $response);
        }

        return $result;
    }

    /**
     * Cancel a run by its public ID.
     *
     * The running loop will detect the cancellation on its next poll
     * (between iterations) and throw RunCancelledException.
     *
     * Safe to call from any process — sets the DB flag which the worker
     * reads asynchronously.
     *
     * @throws \Laragentic\Exceptions\RunNotFoundException
     */
    public static function cancelRun(string $runId): void
    {
        AgentRun::findByRunId($runId)->markCancelled();
    }

    /**
     * Return the persisted AgentRun for the given run ID.
     *
     * Useful for inspecting status, checkpoints, or metadata from
     * outside the running loop.
     *
     * @throws \Laragentic\Exceptions\RunNotFoundException
     */
    public static function findRun(string $runId): AgentRun
    {
        return AgentRun::findByRunId($runId);
    }

    // ─── Run Initialization ──────────────────────────────────────────

    /**
     * Find an existing run or create a new one.
     *
     * If a run with the given ID already exists AND is resumable, return
     * it so the loop can pick up from the last checkpoint. If it is in a
     * terminal state (completed, failed, cancelled) and a run_id was
     * explicitly supplied, throw rather than silently restarting.
     */
    protected function initDurableRun(
        ?string $runId,
        string $loopType,
        string $initialPrompt,
    ): AgentRun {
        if ($runId !== null) {
            $existing = AgentRun::where('run_id', $runId)->first();

            if ($existing !== null) {
                if ($existing->isTerminal()) {
                    throw new \RuntimeException(
                        "Cannot resume run '{$runId}': status is '{$existing->status->value}' (terminal).",
                    );
                }

                return $existing;
            }
        }

        return AgentRun::create([
            'run_id'         => $runId ?? (string) Str::uuid(),
            'agent_class'    => static::class,
            'loop_type'      => $loopType,
            'status'         => RunStatus::Pending,
            'initial_prompt' => $initialPrompt,
        ]);
    }

    // ─── Checkpoint Persistence ──────────────────────────────────────

    /**
     * Persist the state of one completed iteration.
     *
     * @param  array<int, array{tool: string, arguments: array<string, mixed>, result: string}>  $toolCallRecords
     */
    protected function saveCheckpoint(
        AgentRun $run,
        int $iteration,
        string $prompt,
        \Laravel\Ai\Responses\AgentResponse $response,
        array $toolCallRecords,
        ?string $observation,
        ?string $nextPrompt,
    ): void {
        AgentCheckpoint::updateOrCreate(
            ['run_id' => $run->run_id, 'iteration' => $iteration],
            [
                'prompt'        => $prompt,
                'response_text' => $response->text,
                'tool_calls'    => $toolCallRecords ?: null,
                'observation'   => $observation,
                'next_prompt'   => $nextPrompt,
            ],
        );
    }

    /**
     * Reconstruct a LoopStep from a persisted checkpoint.
     */
    protected function checkpointToLoopStep(AgentCheckpoint $checkpoint): LoopStep
    {
        // Build a minimal AgentResponse stand-in for history display
        $response = new \Laravel\Ai\Responses\AgentResponse(
            invocationId: "checkpoint-{$checkpoint->run_id}-{$checkpoint->iteration}",
            text: $checkpoint->response_text,
            usage: new \Laravel\Ai\Responses\Data\Usage(promptTokens: 0, completionTokens: 0),
            meta: new \Laravel\Ai\Responses\Data\Meta(provider: 'restored', model: 'restored'),
        );

        return new LoopStep(
            iteration: $checkpoint->iteration,
            response: $response,
            toolCalls: $checkpoint->toolCallRecords(),
            observation: $checkpoint->observation,
        );
    }

    // ─── Idempotent Tool Execution ───────────────────────────────────

    /**
     * Execute all tool calls for a loop response, with idempotency.
     *
     * For each tool call an idempotency key is derived from the run,
     * iteration, tool name, and arguments. If a result for that key
     * already exists in the database (from a prior attempt), the stored
     * result is returned without re-executing the tool.
     *
     * @return array<int, array{tool: string, arguments: array<string, mixed>, result: string}>
     */
    protected function executeDurableToolCalls(
        AgentRun $run,
        \Laravel\Ai\Responses\AgentResponse $response,
        int $iteration,
    ): array {
        $tools   = $this->resolveToolMap();
        $records = [];

        foreach ($response->toolCalls as $toolCall) {
            $toolName  = $toolCall->name;
            $arguments = $toolCall->arguments;

            if ($this->durableToolIdempotency) {
                $key    = $this->buildIdempotencyKey($run->run_id, $iteration, $toolName, $arguments);
                $cached = AgentToolCall::findByKey($key);

                if ($cached !== null) {
                    // Replay: return stored result without re-running the tool.
                    // Callbacks are NOT re-fired for replayed calls — they already
                    // fired before the crash that necessitated the resume.
                    $records[] = [
                        'tool'      => $toolName,
                        'arguments' => $arguments,
                        'result'    => $cached->result,
                    ];

                    continue;
                }
            }

            // ── Live execution ───────────────────────────────────────
            $this->fireCallbacks('beforeAction', $toolName, $arguments, $iteration);

            $result = $this->executeLoopTool($tools, $toolName, $arguments);

            $this->fireCallbacks('afterAction', $toolName, $arguments, $result, $iteration);

            // Persist idempotency record (unless opted out)
            if ($this->durableToolIdempotency) {
                $key = $this->buildIdempotencyKey($run->run_id, $iteration, $toolName, $arguments);
                AgentToolCall::create([
                    'run_id'           => $run->run_id,
                    'idempotency_key'  => $key,
                    'iteration'        => $iteration,
                    'tool_name'        => $toolName,
                    'arguments'        => $arguments,
                    'result'           => $result,
                ]);
            }

            $records[] = [
                'tool'      => $toolName,
                'arguments' => $arguments,
                'result'    => $result,
            ];

            if ($this->detectedAskHuman !== null) {
                break;
            }
        }

        return $records;
    }

    /**
     * Derive an idempotency key for a single tool call.
     *
     * The key is stable across retries: same run + iteration + tool +
     * arguments always produces the same SHA-256 hash.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function buildIdempotencyKey(
        string $runId,
        int $iteration,
        string $toolName,
        array $arguments,
    ): string {
        // Sort keys so argument ordering doesn't break idempotency
        ksort($arguments);

        $payload = $runId . ':' . $iteration . ':' . $toolName . ':' . json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', $payload);
    }

    // ─── Composition Requirements ────────────────────────────────────
    //
    // HasDurableRuns must be combined with ReActLoop and a class that
    // provides prompt(). The following methods are expected to be
    // provided at composition time:
    //
    //   • resolveMaxIterations()          — from ReActLoop
    //   • loopShouldTerminate($response)  — from ReActLoop
    //   • buildReActObservation($records) — from ReActLoop
    //   • fireSdkResolvedToolCallbacks()  — from ReActLoop
    //   • prompt($prompt, ...)            — from the agent class (Promptable)
    //
    // PHP will raise a clear error at call time if any of these are absent.
}
