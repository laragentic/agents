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
 * Adds a Chain-of-Thought (CoT) reasoning loop to any Laravel AI SDK agent.
 *
 * The CoT loop implements iterative self-reflection reasoning:
 *
 *     User Problem
 *       ↓
 *     [REASONING ITERATION 1]
 *       ├─ Step-by-step analysis of problem
 *       ├─ Identify what's known and unknown
 *       ├─ Call tools if information needed
 *       └─ Self-evaluate: "Do I understand enough to solve this?"
 *       ↓
 *     Confident? → No  → [REASONING ITERATION 2]
 *                → Yes → [FINAL ANSWER]
 *
 * The agent continues reasoning until it expresses confidence in its
 * understanding or reaches the maximum iteration limit.
 *
 * Usage:
 *
 *     class MathAgent implements Agent, HasTools
 *     {
 *         use Promptable, ChainOfThoughtLoop;
 *
 *         public function instructions(): string { ... }
 *         public function tools(): iterable { ... }
 *     }
 *
 *     $result = (new MathAgent)
 *         ->maxReasoningIterations(10)
 *         ->chainOfThought('Complex problem requiring deep analysis...');
 */
trait ChainOfThoughtLoop
{
    use HasIterationCallbacks;
    use ExecutesLoopTools;
    use \Laragentic\Concerns\StreamsPrompts;

    /**
     * The maximum number of reasoning iterations for this agent instance.
     */
    protected ?int $maxCoTIterations = null;

    // ─── CoT-Specific Callback Registration ─────────────────────────

    /**
     * Register a callback invoked before the LLM reasons (before prompt).
     *
     * Receives: (string $prompt, int $iteration)
     */
    public function onBeforeReasoning(Closure $callback): static
    {
        $this->loopCallbacks['beforeReasoning'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked after the LLM responds (after reasoning).
     *
     * Receives: (AgentResponse $response, int $iteration)
     */
    public function onAfterReasoning(Closure $callback): static
    {
        $this->loopCallbacks['afterReasoning'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when building a reflection prompt.
     *
     * Receives: (string $reflectionPrompt, int $iteration)
     */
    public function onReflection(Closure $callback): static
    {
        $this->loopCallbacks['reflection'][] = $callback;

        return $this;
    }

    /**
     * Set the maximum number of reasoning iterations for the CoT loop.
     */
    public function maxReasoningIterations(int $max): static
    {
        $this->maxCoTIterations = $max;

        return $this;
    }

    /**
     * Execute the Chain-of-Thought reasoning loop.
     *
     * Each iteration follows: Reasoning → (optional Tool Calls) → Self-Evaluation.
     * The loop continues until the LLM expresses confidence or max iterations reached.
     *
     * @throws MaxIterationsExceededException
     */
    public function chainOfThought(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): CoTResult {
        $maxIterations = $this->resolveMaxReasoningIterations();
        $iteration = 0;
        $steps = [];
        $response = null;
        $currentPrompt = $this->buildInitialPrompt($prompt);

        $this->fireCallbacks('loopStart', $prompt);

        while ($iteration < $maxIterations) {
            $iteration++;

            $this->fireCallbacks('iterationStart', $iteration);

            // ── REASONING ───────────────────────────────────────────
            // The LLM analyzes the problem step-by-step, identifies gaps,
            // and evaluates whether it has sufficient understanding.

            $this->fireCallbacks('beforeReasoning', $currentPrompt, $iteration);

            $response = $this->prompt(
                prompt: $currentPrompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $this->fireCallbacks('afterReasoning', $response, $iteration);

            // Check if the agent is confident in its understanding
            $isConfident = $this->isReasoningComplete($response);

            if ($isConfident) {
                // Agent has reached confidence → final answer
                $steps[] = new CoTStep(
                    iteration: $iteration,
                    response: $response,
                    confident: true,
                );

                $this->fireCallbacks('iterationEnd', $iteration, $response);
                $this->fireCallbacks('loopComplete', $response, $iteration);

                return new CoTResult(
                    response: $response,
                    reasoningIterations: $iteration,
                    steps: $steps,
                );
            }

            // ── TOOL CALLS ──────────────────────────────────────────
            // Execute any tools the LLM requested to gather information.

            $toolCallRecords = [];
            $observation = null;

            if ($response->toolCalls->isNotEmpty()) {
                $toolCallRecords = $this->executeLoopToolCalls($response, $iteration);

                // ── ASK HUMAN ───────────────────────────────────────
                if ($this->detectedAskHuman !== null) {
                    $signal = $this->detectedAskHuman;
                    $this->detectedAskHuman = null;

                    $steps[] = new CoTStep(
                        iteration: $iteration,
                        response: $response,
                        toolCalls: $toolCallRecords,
                        confident: false,
                    );

                    $this->fireCallbacks('iterationEnd', $iteration, $response);
                    $this->fireCallbacks('askHuman', $signal, $iteration, $response);

                    return new CoTResult(
                        response: $response,
                        reasoningIterations: $iteration,
                        steps: $steps,
                        askHumanSignal: $signal,
                    );
                }

                // ── PAUSE ──────────────────────────────────────────
                if ($this->detectedPause !== null) {
                    $pause = $this->detectedPause;
                    $this->detectedPause = null;

                    $executedToolNames = array_map(fn ($r) => $r['tool'], $toolCallRecords);
                    $allRequestedTools = $response->toolCalls->map(fn ($tc) => $tc->name)->all();
                    $deferredTools = array_values(array_diff($allRequestedTools, $executedToolNames));

                    $observation = $this->buildCoTPauseObservation($toolCallRecords, $deferredTools);

                    $steps[] = new CoTStep(
                        iteration: $iteration,
                        response: $response,
                        toolCalls: $toolCallRecords,
                        observation: $observation,
                        confident: false,
                    );

                    $this->fireCallbacks('iterationEnd', $iteration, $response);
                    $this->fireCallbacks('pause', $pause, $deferredTools, $iteration, $response);

                    return new CoTResult(
                        response: $response,
                        reasoningIterations: $iteration,
                        steps: $steps,
                        pauseSignal: $pause,
                        deferredTools: $deferredTools,
                    );
                }

                $observation = $this->formatObservation($toolCallRecords);
            }

            $steps[] = new CoTStep(
                iteration: $iteration,
                response: $response,
                toolCalls: $toolCallRecords,
                observation: $observation,
                confident: false,
            );

            // ── REFLECTION ──────────────────────────────────────────
            // Build a prompt for the next iteration that includes:
            // - Previous reasoning context (maintained by conversation)
            // - Tool observations (if any)
            // - Guidance to reflect and evaluate understanding

            $currentPrompt = $this->buildReflectionPrompt($observation);

            $this->fireCallbacks('reflection', $currentPrompt, $iteration);
            $this->fireCallbacks('iterationEnd', $iteration, $response);
        }

        // Max iterations reached without confidence
        $this->fireCallbacks('maxIterationsReached', $response, $iteration);

        $result = new CoTResult(
            response: $response,
            reasoningIterations: $iteration,
            steps: $steps,
            reachedMaxIterations: true,
        );

        if (config('agentic.chain_of_thought.throw_on_max_iterations', false)) {
            throw new MaxIterationsExceededException($maxIterations, $response);
        }

        return $result;
    }

    /**
     * Build the initial reasoning prompt that includes CoT guidance.
     *
     * This enhances the user's problem with reasoning instructions.
     */
    protected function buildInitialPrompt(string $userProblem): string
    {
        $prompt = $userProblem . "\n\n";
        $prompt .= CoTPrompts::reasoningGuidance() . "\n\n";

        if (method_exists($this, 'tools') && ! empty(iterator_to_array($this->tools()))) {
            $prompt .= CoTPrompts::toolGuidance() . "\n\n";
        }

        $prompt .= CoTPrompts::confidenceEvaluation();

        return $prompt;
    }

    /**
     * Build a reflection prompt for continuing reasoning after an iteration.
     *
     * This prompt guides the LLM to reflect on its current understanding
     * and decide whether to continue reasoning or provide a final answer.
     */
    protected function buildReflectionPrompt(?string $observation): string
    {
        return CoTPrompts::reflectionPrompt($observation);
    }

    /**
     * Determine if the agent's reasoning is complete (confident in understanding).
     *
     * The loop terminates when the agent explicitly expresses confidence
     * in its understanding or provides a final answer.
     *
     * Override this method to implement custom confidence detection logic.
     */
    protected function isReasoningComplete(AgentResponse $response): bool
    {
        $text = strtolower($response->text);

        // Check for explicit confidence markers
        foreach (CoTPrompts::confidenceMarkers() as $marker) {
            if (str_contains($text, $marker)) {
                return true;
            }
        }

        // If response has no tool calls and is substantial, likely final answer
        if ($response->toolCalls->isEmpty() && strlen($text) > 100) {
            // But not if it expresses uncertainty
            foreach (CoTPrompts::uncertaintyMarkers() as $marker) {
                if (str_contains($text, $marker)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Build an observation for a paused CoT loop.
     *
     * @param  array<int, array{tool: string, arguments: array<string, mixed>, result: string}>  $toolCallRecords
     * @param  list<string>  $deferredTools
     */
    protected function buildCoTPauseObservation(array $toolCallRecords, array $deferredTools): string
    {
        $observation = $this->formatObservation($toolCallRecords);

        $observation .= "\n\n⏸ The loop was paused because a tool requires user interaction. "
            . 'Wait for the user to provide the result from the interactive tool.';

        if (count($deferredTools) > 0) {
            $toolList = implode(', ', $deferredTools);
            $observation .= "\n\nIMPORTANT: The following tool(s) were requested but NOT yet executed: {$toolList}. "
                . 'After the user provides the interactive result, you MUST continue by calling these deferred tools.';
        }

        return $observation;
    }

    /**
     * Execute the Chain-of-Thought loop as a Generator for streaming contexts.
     *
     * This method mirrors chainOfThought() but yields values from callbacks,
     * making it compatible with Laravel's response()->eventStream().
     *
     * Usage with eventStream():
     *
     *     return response()->eventStream(function () use ($agent) {
     *         yield from $agent->chainOfThoughtStream('Problem...');
     *     });
     *
     * The return value of the Generator is the CoTResult, accessible
     * via Generator::getReturn() if needed.
     *
     * @return \Generator<int, mixed, mixed, CoTResult>
     *
     * @throws MaxIterationsExceededException
     */
    public function chainOfThoughtStream(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $maxIterations = $this->resolveMaxReasoningIterations();
        $iteration = 0;
        $steps = [];
        $response = null;
        $currentPrompt = $this->buildInitialPrompt($prompt);

        yield from $this->fireStreamCallbacks('loopStart', $prompt);

        while ($iteration < $maxIterations) {
            $iteration++;

            yield from $this->fireStreamCallbacks('iterationStart', $iteration);

            // ── REASONING ───────────────────────────────────────────
            yield from $this->fireStreamCallbacks('beforeReasoning', $currentPrompt, $iteration);

            $gen = $this->streamingPrompt($currentPrompt, $iteration, $provider, $model, $timeout);
            yield from $gen;
            $response = $gen->getReturn();

            yield from $this->fireStreamCallbacks('afterReasoning', $response, $iteration);

            $isConfident = $this->isReasoningComplete($response);

            if ($isConfident) {
                $steps[] = new CoTStep(
                    iteration: $iteration,
                    response: $response,
                    confident: true,
                );

                yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
                yield from $this->fireStreamCallbacks('loopComplete', $response, $iteration);

                return new CoTResult(
                    response: $response,
                    reasoningIterations: $iteration,
                    steps: $steps,
                );
            }

            // ── TOOL CALLS ──────────────────────────────────────────
            $toolCallRecords = [];
            $observation = null;

            if ($response->toolCalls->isNotEmpty()) {
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

                    if ($this->detectedAskHuman !== null || $this->detectedPause !== null) {
                        break;
                    }
                }

                // ── ASK HUMAN ───────────────────────────────────────
                if ($this->detectedAskHuman !== null) {
                    $signal = $this->detectedAskHuman;
                    $this->detectedAskHuman = null;

                    $steps[] = new CoTStep(
                        iteration: $iteration,
                        response: $response,
                        toolCalls: $toolCallRecords,
                        confident: false,
                    );

                    yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
                    yield from $this->fireStreamCallbacks('askHuman', $signal, $iteration, $response);

                    return new CoTResult(
                        response: $response,
                        reasoningIterations: $iteration,
                        steps: $steps,
                        askHumanSignal: $signal,
                    );
                }

                // ── PAUSE ──────────────────────────────────────────
                if ($this->detectedPause !== null) {
                    $pause = $this->detectedPause;
                    $this->detectedPause = null;

                    $executedToolNames = array_map(fn ($r) => $r['tool'], $toolCallRecords);
                    $allRequestedTools = $response->toolCalls->map(fn ($tc) => $tc->name)->all();
                    $deferredTools = array_values(array_diff($allRequestedTools, $executedToolNames));

                    $observation = $this->buildCoTPauseObservation($toolCallRecords, $deferredTools);

                    $steps[] = new CoTStep(
                        iteration: $iteration,
                        response: $response,
                        toolCalls: $toolCallRecords,
                        observation: $observation,
                        confident: false,
                    );

                    yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
                    yield from $this->fireStreamCallbacks('pause', $pause, $deferredTools, $iteration, $response);

                    return new CoTResult(
                        response: $response,
                        reasoningIterations: $iteration,
                        steps: $steps,
                        pauseSignal: $pause,
                        deferredTools: $deferredTools,
                    );
                }

                $observation = $this->formatObservation($toolCallRecords);
            }

            $steps[] = new CoTStep(
                iteration: $iteration,
                response: $response,
                toolCalls: $toolCallRecords,
                observation: $observation,
                confident: false,
            );

            // ── REFLECTION ──────────────────────────────────────────
            $currentPrompt = $this->buildReflectionPrompt($observation);

            yield from $this->fireStreamCallbacks('reflection', $currentPrompt, $iteration);
            yield from $this->fireStreamCallbacks('iterationEnd', $iteration, $response);
        }

        // Max iterations reached
        yield from $this->fireStreamCallbacks('maxIterationsReached', $response, $iteration);

        $result = new CoTResult(
            response: $response,
            reasoningIterations: $iteration,
            steps: $steps,
            reachedMaxIterations: true,
        );

        if (config('agentic.chain_of_thought.throw_on_max_iterations', false)) {
            throw new MaxIterationsExceededException($maxIterations, $response);
        }

        return $result;
    }

    /**
     * Resolve the maximum reasoning iteration count from the instance or config.
     */
    protected function resolveMaxReasoningIterations(): int
    {
        return $this->maxCoTIterations
            ?? (int) config('agentic.chain_of_thought.max_reasoning_iterations', 5);
    }
}
