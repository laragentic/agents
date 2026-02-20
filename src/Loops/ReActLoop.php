<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Closure;
use Laragentic\Concerns\ExecutesLoopTools;
use Laragentic\Concerns\HasIterationCallbacks;
use Laragentic\Exceptions\MaxIterationsExceededException;
use Laragentic\Signals\AskHumanSignal;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Adds a ReAct (Reasoning + Acting) agentic loop to any Laravel AI SDK agent.
 *
 * The ReAct loop follows this cycle:
 *
 *     User Goal
 *       ↓
 *     [THOUGHT]  — LLM reasons about what to do next
 *       ↓
 *     [ACTION]   — LLM calls one or more tools
 *       ↓
 *     [OBSERVATION] — Tool results are fed back to the LLM
 *       ↓
 *     Goal finished? → No  → back to THOUGHT (with the observation)
 *                    → Yes → LLM produces final answer
 *
 * The observation is the checkpoint: the LLM receives the tool results
 * and decides whether the user's goal has been satisfied or if it needs
 * to take further action.
 *
 * Usage:
 *
 *     class SalesCoach implements Agent, Conversational
 *     {
 *         use Promptable, RemembersConversations, ReActLoop;
 *
 *         public function instructions(): string { ... }
 *         public function tools(): iterable { ... }
 *     }
 *
 *     $result = (new SalesCoach)
 *         ->forUser($user)
 *         ->reactLoop('Analyze this transcript...');
 */
trait ReActLoop
{
    use HasIterationCallbacks;
    use ExecutesLoopTools;
    use \Laragentic\Concerns\StreamsPrompts;

    /**
     * The maximum number of loop iterations for this agent instance.
     */
    protected ?int $maxLoopIterations = null;

    // ─── ReAct-Specific Callback Registration ────────────────────────

    /**
     * Register a callback invoked before the LLM reasons (before prompt).
     *
     * Receives: (string $prompt, int $iteration)
     */
    public function onBeforeThought(Closure $callback): static
    {
        $this->loopCallbacks['beforeThought'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked after the LLM responds (after thought).
     *
     * Receives: (AgentResponse $response, int $iteration)
     */
    public function onAfterThought(Closure $callback): static
    {
        $this->loopCallbacks['afterThought'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when the observation is ready.
     *
     * Receives: (string $observation, int $iteration)
     */
    public function onObservation(Closure $callback): static
    {
        $this->loopCallbacks['observation'][] = $callback;

        return $this;
    }

    /**
     * Set the maximum number of iterations for the ReAct loop.
     */
    public function maxIterations(int $max): static
    {
        $this->maxLoopIterations = $max;

        return $this;
    }

    /**
     * Execute the ReAct loop.
     *
     * Each iteration follows the Thought → Action → Observation cycle.
     * The loop continues until the LLM produces a response with no tool
     * calls (goal satisfied) or the max iteration limit is reached.
     *
     * @throws MaxIterationsExceededException
     */
    public function reactLoop(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): LoopResult {
        $maxIterations = $this->resolveMaxIterations();
        $iteration = 0;
        $steps = [];
        $response = null;
        $currentPrompt = $prompt;

        $this->fireCallbacks('loopStart', $prompt);

        while ($iteration < $maxIterations) {
            $iteration++;

            $this->fireCallbacks('iterationStart', $iteration);

            // ── THOUGHT ─────────────────────────────────────────────
            // The LLM reasons about the current state and decides what
            // to do. On iteration 1 this is the user's original prompt.
            // On subsequent iterations the prompt contains the observation
            // (tool results) so the LLM can evaluate progress.

            $this->fireCallbacks('beforeThought', $currentPrompt, $iteration);

            $response = $this->prompt(
                prompt: $currentPrompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $this->fireCallbacks('afterThought', $response, $iteration);

            // If no tool calls, the LLM considers the goal satisfied
            // and has produced a final answer.
            if ($this->loopShouldTerminate($response)) {
                // When the SDK resolved tool calls internally (Prism),
                // the tool results are already in toolResults. Scan them
                // for an AskHumanSignal before treating this as a normal
                // loop completion, since executeLoopToolCalls() is skipped.
                foreach ($response->toolResults as $toolResult) {
                    $resultStr = (string) $toolResult->result;

                    if (AskHumanSignal::isSignal($resultStr)) {
                        $signal = AskHumanSignal::fromString($resultStr);

                        $steps[] = new LoopStep(
                            iteration: $iteration,
                            response: $response,
                        );

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

                // When the SDK resolved tool calls internally (Prism),
                // fire action callbacks so consumers see what happened.
                $this->fireSdkResolvedToolCallbacks($response, $iteration);

                $steps[] = new LoopStep(
                    iteration: $iteration,
                    response: $response,
                );

                $this->fireCallbacks('iterationEnd', $iteration, $response);
                $this->fireCallbacks('loopComplete', $response, $iteration);

                return new LoopResult(
                    response: $response,
                    iterations: $iteration,
                    steps: $steps,
                );
            }

            // ── ACTION ──────────────────────────────────────────────
            // Execute each tool the LLM requested.

            $toolCallRecords = $this->executeLoopToolCalls($response, $iteration);

            // ── ASK HUMAN ───────────────────────────────────────────
            // If a tool returned an AskHumanSignal, pause the loop and
            // fire the onAskHuman callback. No second LLM call is made.

            if ($this->detectedAskHuman !== null) {
                $signal = $this->detectedAskHuman;
                $this->detectedAskHuman = null;

                $steps[] = new LoopStep(
                    iteration: $iteration,
                    response: $response,
                    toolCalls: $toolCallRecords,
                );

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
            // Format the tool results into an observation and feed it
            // back to the LLM on the next iteration. The LLM will then
            // evaluate whether the user's goal has been met.

            $observation = $this->buildReActObservation($toolCallRecords);

            $this->fireCallbacks('observation', $observation, $iteration);

            $steps[] = new LoopStep(
                iteration: $iteration,
                response: $response,
                toolCalls: $toolCallRecords,
                observation: $observation,
            );

            // The observation becomes the next prompt — the LLM sees
            // the tool results and decides: continue or final answer.
            $currentPrompt = $observation;

            $this->fireCallbacks('iterationEnd', $iteration, $response);
        }

        // Max iterations reached — the goal was not satisfied in time.
        $this->fireCallbacks('maxIterationsReached', $response, $iteration);

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
     * Determine if the loop should terminate based on the LLM response.
     *
     * The loop terminates when:
     * - The LLM returned no tool calls (pure text answer), OR
     * - The SDK already resolved all tool calls internally (each tool
     *   call has a matching tool result), meaning the full Thought →
     *   Action → Observation cycle completed within a single prompt().
     *
     * Override this method to implement custom termination logic.
     */
    protected function loopShouldTerminate(AgentResponse $response): bool
    {
        if ($response->toolCalls->isEmpty()) {
            return true;
        }

        // When the Laravel AI SDK resolves tool calls internally (via Prism),
        // toolResults will match toolCalls. In that case the cycle is complete
        // and we should not re-execute the already-resolved tools.
        return $response->toolResults->count() >= $response->toolCalls->count();
    }

    /**
     * Build the full observation prompt fed back to the LLM after tool execution.
     *
     * Wraps formatObservation() with ReAct-specific guidance that instructs the
     * LLM to evaluate whether the user's goal has been satisfied or if more
     * tool calls are needed.
     *
     * Override this method to customize the full observation prompt, or override
     * formatObservation() to customize only the tool result formatting.
     *
     * @param  array<int, array{tool: string, arguments: array<string, mixed>, result: string}>  $toolCallRecords
     */
    protected function buildReActObservation(array $toolCallRecords): string
    {
        return "Observation:\n" . $this->formatObservation($toolCallRecords)
            . "\n\nBased on the observation above, continue working toward the user's goal. "
            . 'If the goal is fully satisfied, provide a final answer. '
            . 'If more information is needed, use the available tools.';
    }

    /**
     * Execute the ReAct loop as a Generator for streaming contexts.
     *
     * This method mirrors reactLoop() but yields values from callbacks,
     * making it compatible with Laravel's response()->eventStream().
     *
     * Usage with eventStream():
     *
     *     return response()->eventStream(function () use ($agent) {
     *         yield from $agent->reactLoopStream('Hello!');
     *     });
     *
     * The return value of the Generator is the LoopResult, accessible
     * via Generator::getReturn() if needed.
     *
     * @return \Generator<int, mixed, mixed, LoopResult>
     *
     * @throws MaxIterationsExceededException
     */
    public function reactLoopStream(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $maxIterations = $this->resolveMaxIterations();
        $iteration = 0;
        $steps = [];
        $response = null;
        $currentPrompt = $prompt;

        yield from $this->fireStreamCallbacks('loopStart', $prompt);

        while ($iteration < $maxIterations) {
            $iteration++;

            yield from $this->fireStreamCallbacks('iterationStart', $iteration);

            // ── THOUGHT ─────────────────────────────────────────────
            yield from $this->fireStreamCallbacks('beforeThought', $currentPrompt, $iteration);

            $gen = $this->streamingPrompt($currentPrompt, $iteration, $provider, $model, $timeout);
            yield from $gen;
            $response = $gen->getReturn();

            yield from $this->fireStreamCallbacks('afterThought', $response, $iteration);

            // If no tool calls, the LLM considers the goal satisfied.
            if ($this->loopShouldTerminate($response)) {
                // Scan SDK-resolved tool results for an AskHumanSignal
                // before treating this as a normal loop completion.
                foreach ($response->toolResults as $toolResult) {
                    $resultStr = (string) $toolResult->result;

                    if (AskHumanSignal::isSignal($resultStr)) {
                        $signal = AskHumanSignal::fromString($resultStr);

                        $steps[] = new LoopStep(
                            iteration: $iteration,
                            response: $response,
                        );

                        yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
                        yield from $this->fireStreamCallbacks('askHuman', $signal, $iteration);

                        return new LoopResult(
                            response: $response,
                            iterations: $iteration,
                            steps: $steps,
                            askHumanSignal: $signal,
                        );
                    }
                }

                // When the SDK resolved tool calls internally (Prism),
                // fire action callbacks so consumers see what happened.
                yield from $this->fireSdkResolvedToolStreamCallbacks($response, $iteration);

                $steps[] = new LoopStep(
                    iteration: $iteration,
                    response: $response,
                );

                yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
                yield from $this->fireStreamCallbacks('loopComplete', $response, $iteration);

                return new LoopResult(
                    response: $response,
                    iterations: $iteration,
                    steps: $steps,
                );
            }

            // ── ACTION ──────────────────────────────────────────────
            $toolCallRecords = [];
            $tools = $this->resolveToolMap();

            foreach ($response->toolCalls as $toolCall) {
                $toolName = $toolCall->name;
                $arguments = $toolCall->arguments;

                yield from $this->fireStreamCallbacks('beforeAction', $toolName, $arguments, $iteration);

                $result = $this->executeLoopTool($tools, $toolName, $arguments);

                yield from $this->fireStreamCallbacks('afterAction', $toolName, $arguments, $result, $iteration);

                $toolCallRecords[] = [
                    'tool' => $toolName,
                    'arguments' => $arguments,
                    'result' => $result,
                ];

                if ($this->detectedAskHuman !== null) {
                    break;
                }
            }

            // ── ASK HUMAN ───────────────────────────────────────────
            if ($this->detectedAskHuman !== null) {
                $signal = $this->detectedAskHuman;
                $this->detectedAskHuman = null;

                $steps[] = new LoopStep(
                    iteration: $iteration,
                    response: $response,
                    toolCalls: $toolCallRecords,
                );

                yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
                yield from $this->fireStreamCallbacks('askHuman', $signal, $iteration);

                return new LoopResult(
                    response: $response,
                    iterations: $iteration,
                    steps: $steps,
                    askHumanSignal: $signal,
                );
            }

            // ── OBSERVATION ─────────────────────────────────────────
            $observation = $this->buildReActObservation($toolCallRecords);

            yield from $this->fireStreamCallbacks('observation', $observation, $iteration);

            $steps[] = new LoopStep(
                iteration: $iteration,
                response: $response,
                toolCalls: $toolCallRecords,
                observation: $observation,
            );

            $currentPrompt = $observation;

            yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
        }

        // Max iterations reached.
        yield from $this->fireStreamCallbacks('maxIterationsReached', $response, $iteration);

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
     * Fire action callbacks for tool calls that the SDK resolved internally.
     *
     * When the Laravel AI SDK's Prism gateway executes tool calls within a
     * single prompt() call, our loop detects this via loopShouldTerminate()
     * and skips its own ACTION phase. This method replays the SDK-resolved
     * tool calls through our callbacks so consumers still see them.
     */
    protected function fireSdkResolvedToolCallbacks(AgentResponse $response, int $iteration): void
    {
        if ($response->toolCalls->isEmpty()) {
            return;
        }

        // Build a map of tool results by name for matching
        $resultsByName = [];
        foreach ($response->toolResults as $toolResult) {
            $resultsByName[$toolResult->name] = (string) $toolResult->result;
        }

        foreach ($response->toolCalls as $toolCall) {
            $result = $resultsByName[$toolCall->name] ?? '';

            $this->fireCallbacks('beforeAction', $toolCall->name, $toolCall->arguments, $iteration);
            $this->fireCallbacks('afterAction', $toolCall->name, $toolCall->arguments, $result, $iteration);
        }
    }

    /**
     * Streaming variant of fireSdkResolvedToolCallbacks.
     *
     * @return \Generator
     */
    protected function fireSdkResolvedToolStreamCallbacks(AgentResponse $response, int $iteration): \Generator
    {
        if ($response->toolCalls->isEmpty()) {
            return;
        }

        $resultsByName = [];
        foreach ($response->toolResults as $toolResult) {
            $resultsByName[$toolResult->name] = (string) $toolResult->result;
        }

        foreach ($response->toolCalls as $toolCall) {
            $result = $resultsByName[$toolCall->name] ?? '';

            yield from $this->fireStreamCallbacks('beforeAction', $toolCall->name, $toolCall->arguments, $iteration);
            yield from $this->fireStreamCallbacks('afterAction', $toolCall->name, $toolCall->arguments, $result, $iteration);
        }
    }

    /**
     * Resolve the maximum iteration count from the instance or config.
     */
    protected function resolveMaxIterations(): int
    {
        return $this->maxLoopIterations
            ?? (int) config('agentic.react.max_iterations', 10);
    }
}
