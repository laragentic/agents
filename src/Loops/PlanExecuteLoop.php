<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Closure;
use Laragentic\Concerns\HasCallbacks;
use Laragentic\Exceptions\MaxStepsExceededException;
use Laragentic\Signals\AskHumanSignal;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Adds a Plan-Execute agentic loop to any Laravel AI SDK agent.
 *
 * The Plan-Execute loop separates planning from execution:
 *
 *     User Task
 *       ↓
 *     [PLAN]       — LLM creates a step-by-step plan
 *       ↓
 *     [EXECUTE 1]  — LLM executes step 1 (may use tools)
 *       ↓
 *     [EXECUTE 2]  — LLM executes step 2 (with prior context)
 *       ↓
 *       ...        — Continue for each step
 *       ↓
 *     [SYNTHESIZE] — LLM combines all results into a final answer
 *
 * Optional adaptive replanning: if a step fails, the LLM can revise
 * the remaining plan based on what it has learned so far.
 *
 * Usage:
 *
 *     class ResearchAgent implements Agent, HasTools
 *     {
 *         use Promptable, PlanExecuteLoop;
 *
 *         public function instructions(): string { ... }
 *         public function tools(): iterable { ... }
 *     }
 *
 *     $result = (new ResearchAgent)
 *         ->allowReplan()
 *         ->planExecute('Research and summarize AI trends');
 */
trait PlanExecuteLoop
{
    use HasCallbacks;
    use \Laragentic\Concerns\StreamsPrompts;

    /**
     * The maximum number of plan steps for this agent instance.
     */
    protected ?int $maxPlanSteps = null;

    /**
     * Whether adaptive replanning is enabled.
     */
    protected ?bool $replanEnabled = null;

    /**
     * Maximum number of replanning attempts.
     */
    protected ?int $maxReplanAttempts = null;

    // ─── Fluent Configuration ───────────────────────────────────────

    /**
     * Set the maximum number of steps for the plan-execute loop.
     */
    public function maxSteps(int $max): static
    {
        $this->maxPlanSteps = $max;

        return $this;
    }

    /**
     * Enable or disable adaptive replanning.
     */
    public function allowReplan(bool $allow = true): static
    {
        $this->replanEnabled = $allow;

        return $this;
    }

    /**
     * Set the maximum number of replanning attempts.
     */
    public function maxReplans(int $max): static
    {
        $this->maxReplanAttempts = $max;

        return $this;
    }

    // ─── Plan-Execute Callback Registration ─────────────────────────

    /**
     * Register a callback invoked after the plan is created.
     *
     * Receives: (array $steps)
     */
    public function onPlanCreated(Closure $callback): static
    {
        $this->loopCallbacks['planCreated'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked before each step is executed.
     *
     * Receives: (int $stepNumber, string $description, int $totalSteps)
     */
    public function onBeforeStep(Closure $callback): static
    {
        $this->loopCallbacks['beforeStep'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked after each step is executed.
     *
     * Receives: (int $stepNumber, string $description, AgentResponse $response, int $totalSteps)
     */
    public function onAfterStep(Closure $callback): static
    {
        $this->loopCallbacks['afterStep'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when the plan is revised.
     *
     * Receives: (array $newSteps, int $replanCount)
     */
    public function onReplan(Closure $callback): static
    {
        $this->loopCallbacks['replan'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked before the synthesis phase.
     *
     * Receives: (array $stepResults)
     */
    public function onBeforeSynthesis(Closure $callback): static
    {
        $this->loopCallbacks['beforeSynthesis'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked after the synthesis phase.
     *
     * Receives: (AgentResponse $response)
     */
    public function onAfterSynthesis(Closure $callback): static
    {
        $this->loopCallbacks['afterSynthesis'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when the max step limit is reached.
     *
     * Receives: (AgentResponse $lastResponse, int $stepsExecuted)
     */
    public function onMaxStepsReached(Closure $callback): static
    {
        $this->loopCallbacks['maxStepsReached'][] = $callback;

        return $this;
    }

    // ─── Main Loop ──────────────────────────────────────────────────

    /**
     * Execute the plan-execute loop.
     *
     * 1. Create a plan for the given task
     * 2. Execute each step sequentially with context
     * 3. Synthesize all results into a final answer
     *
     * @throws MaxStepsExceededException
     */
    public function planExecute(
        string $task,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): PlanResult {
        $maxSteps = $this->resolveMaxSteps();
        $allowReplan = $this->resolveAllowReplan();
        $maxReplans = $this->resolveMaxReplans();
        $replanCount = 0;

        $this->fireCallbacks('loopStart', $task);

        // ── PLANNING PHASE ──────────────────────────────────────────
        $planResponse = $this->prompt(
            prompt: $this->buildPlanningPrompt($task),
            provider: $provider,
            model: $model,
            timeout: $timeout,
        );

        $planDescriptions = $this->parsePlanSteps($planResponse->text);

        // If the LLM didn't produce a parseable plan, treat the
        // whole response as a single step.
        if (empty($planDescriptions)) {
            $planDescriptions = [$planResponse->text];
        }

        $this->fireCallbacks('planCreated', $planDescriptions);

        // ── EXECUTION PHASE ─────────────────────────────────────────
        $executedSteps = [];
        $stepsExecuted = 0;
        $lastResponse = $planResponse;

        for ($i = 0; $i < count($planDescriptions); $i++) {
            // Check max steps BEFORE executing the next step
            if ($stepsExecuted >= $maxSteps) {
                $this->fireCallbacks('maxStepsReached', $lastResponse, $stepsExecuted);

                $result = new PlanResult(
                    response: $lastResponse,
                    plan: $planDescriptions,
                    steps: $executedSteps,
                    replans: $replanCount,
                    reachedMaxSteps: true,
                );

                if (config('agentic.plan_execute.throw_on_max_steps', false)) {
                    throw new MaxStepsExceededException($maxSteps, $lastResponse);
                }

                return $result;
            }

            $stepNumber = $i + 1;
            $description = $planDescriptions[$i];
            $totalSteps = count($planDescriptions);

            $this->fireCallbacks('beforeStep', $stepNumber, $description, $totalSteps);

            $stepResponse = $this->prompt(
                prompt: $this->buildStepExecutionPrompt(
                    $task,
                    $planDescriptions,
                    $stepNumber,
                    $description,
                    $executedSteps,
                ),
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $stepsExecuted++;
            $lastResponse = $stepResponse;

            // ── ASK HUMAN ───────────────────────────────────────────
            // PlanExecuteLoop uses the SDK to resolve tool calls within
            // prompt(). Scan toolResults for the AskHumanSignal JSON marker.

            foreach ($stepResponse->toolResults as $toolResult) {
                $resultStr = (string) $toolResult->result;

                if (AskHumanSignal::isSignal($resultStr)) {
                    $signal = AskHumanSignal::fromString($resultStr);

                    $this->fireCallbacks('askHuman', $signal, $stepNumber, $stepResponse);

                    return new PlanResult(
                        response: $stepResponse,
                        plan: $planDescriptions,
                        steps: $executedSteps,
                        replans: $replanCount,
                        askHumanSignal: $signal,
                    );
                }
            }

            // Check for replanning
            $triggeredReplan = false;

            if ($allowReplan && $replanCount < $maxReplans && $this->shouldReplan($stepResponse)) {
                $replanCount++;
                $triggeredReplan = true;

                $executedSteps[] = (new PlanStep(
                    number: $stepNumber,
                    description: $description,
                ))->withResponse($stepResponse, triggeredReplan: true);

                $this->fireCallbacks('afterStep', $stepNumber, $description, $stepResponse, $totalSteps);

                // Get a revised plan for the remaining work
                $revisedDescriptions = $this->revisePlan(
                    $task,
                    $planDescriptions,
                    $executedSteps,
                    $stepNumber,
                    $stepResponse,
                    $provider,
                    $model,
                    $timeout,
                );

                if (! empty($revisedDescriptions)) {
                    $planDescriptions = $revisedDescriptions;
                    $i = -1; // Reset to start of new plan (increments to 0)
                    $stepNumber = 0;

                    $this->fireCallbacks('replan', $planDescriptions, $replanCount);
                }

                continue;
            }

            $executedSteps[] = (new PlanStep(
                number: $stepNumber,
                description: $description,
            ))->withResponse($stepResponse);

            $this->fireCallbacks('afterStep', $stepNumber, $description, $stepResponse, $totalSteps);
        }

        // ── SYNTHESIS PHASE ─────────────────────────────────────────
        $this->fireCallbacks('beforeSynthesis', $executedSteps);

        $synthesisResponse = $this->prompt(
            prompt: $this->buildSynthesisPrompt($task, $executedSteps),
            provider: $provider,
            model: $model,
            timeout: $timeout,
        );

        $this->fireCallbacks('afterSynthesis', $synthesisResponse);
        $this->fireCallbacks('loopComplete', $synthesisResponse, $stepsExecuted);

        return new PlanResult(
            response: $synthesisResponse,
            plan: $planDescriptions,
            steps: $executedSteps,
            replans: $replanCount,
        );
    }

    /**
     * Execute the plan-execute loop as a Generator for streaming contexts.
     *
     * This method mirrors planExecute() but yields values from callbacks,
     * making it compatible with Laravel's response()->eventStream().
     *
     * Usage with eventStream():
     *
     *     return response()->eventStream(function () use ($agent) {
     *         yield from $agent->planExecuteStream('Research AI trends');
     *     });
     *
     * The return value of the Generator is the PlanResult, accessible
     * via Generator::getReturn() if needed.
     *
     * @return \Generator<int, mixed, mixed, PlanResult>
     *
     * @throws MaxStepsExceededException
     */
    public function planExecuteStream(
        string $task,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $maxSteps = $this->resolveMaxSteps();
        $allowReplan = $this->resolveAllowReplan();
        $maxReplans = $this->resolveMaxReplans();
        $replanCount = 0;

        yield from $this->fireStreamCallbacks('loopStart', $task);

        // ── PLANNING PHASE ──────────────────────────────────────────
        $planGen = $this->streamingPrompt($this->buildPlanningPrompt($task), 0, $provider, $model, $timeout);
        yield from $planGen;
        $planResponse = $planGen->getReturn();

        $planDescriptions = $this->parsePlanSteps($planResponse->text);

        if (empty($planDescriptions)) {
            $planDescriptions = [$planResponse->text];
        }

        yield from $this->fireStreamCallbacks('planCreated', $planDescriptions);

        // ── EXECUTION PHASE ─────────────────────────────────────────
        $executedSteps = [];
        $stepsExecuted = 0;
        $lastResponse = $planResponse;

        for ($i = 0; $i < count($planDescriptions); $i++) {
            if ($stepsExecuted >= $maxSteps) {
                yield from $this->fireStreamCallbacks('maxStepsReached', $lastResponse, $stepsExecuted);

                $result = new PlanResult(
                    response: $lastResponse,
                    plan: $planDescriptions,
                    steps: $executedSteps,
                    replans: $replanCount,
                    reachedMaxSteps: true,
                );

                if (config('agentic.plan_execute.throw_on_max_steps', false)) {
                    throw new MaxStepsExceededException($maxSteps, $lastResponse);
                }

                return $result;
            }

            $stepNumber = $i + 1;
            $description = $planDescriptions[$i];
            $totalSteps = count($planDescriptions);

            yield from $this->fireStreamCallbacks('beforeStep', $stepNumber, $description, $totalSteps);

            $stepGen = $this->streamingPrompt(
                $this->buildStepExecutionPrompt($task, $planDescriptions, $stepNumber, $description, $executedSteps),
                $stepNumber,
                $provider,
                $model,
                $timeout,
            );
            yield from $stepGen;
            $stepResponse = $stepGen->getReturn();

            $stepsExecuted++;
            $lastResponse = $stepResponse;

            // ── ASK HUMAN ───────────────────────────────────────────
            foreach ($stepResponse->toolResults as $toolResult) {
                $resultStr = (string) $toolResult->result;

                if (AskHumanSignal::isSignal($resultStr)) {
                    $signal = AskHumanSignal::fromString($resultStr);

                    yield from $this->fireStreamCallbacks('askHuman', $signal, $stepNumber, $stepResponse);

                    return new PlanResult(
                        response: $stepResponse,
                        plan: $planDescriptions,
                        steps: $executedSteps,
                        replans: $replanCount,
                        askHumanSignal: $signal,
                    );
                }
            }

            $triggeredReplan = false;

            if ($allowReplan && $replanCount < $maxReplans && $this->shouldReplan($stepResponse)) {
                $replanCount++;
                $triggeredReplan = true;

                $executedSteps[] = (new PlanStep(
                    number: $stepNumber,
                    description: $description,
                ))->withResponse($stepResponse, triggeredReplan: true);

                yield from $this->fireStreamCallbacks('afterStep', $stepNumber, $description, $stepResponse, $totalSteps);

                $revisedDescriptions = $this->revisePlan(
                    $task,
                    $planDescriptions,
                    $executedSteps,
                    $stepNumber,
                    $stepResponse,
                    $provider,
                    $model,
                    $timeout,
                );

                if (! empty($revisedDescriptions)) {
                    $planDescriptions = $revisedDescriptions;
                    $i = -1;
                    $stepNumber = 0;

                    yield from $this->fireStreamCallbacks('replan', $planDescriptions, $replanCount);
                }

                continue;
            }

            $executedSteps[] = (new PlanStep(
                number: $stepNumber,
                description: $description,
            ))->withResponse($stepResponse);

            yield from $this->fireStreamCallbacks('afterStep', $stepNumber, $description, $stepResponse, $totalSteps);
        }

        // ── SYNTHESIS PHASE ─────────────────────────────────────────
        yield from $this->fireStreamCallbacks('beforeSynthesis', $executedSteps);

        $synthesisGen = $this->streamingPrompt(
            $this->buildSynthesisPrompt($task, $executedSteps),
            $stepsExecuted + 1,
            $provider,
            $model,
            $timeout,
        );
        yield from $synthesisGen;
        $synthesisResponse = $synthesisGen->getReturn();

        yield from $this->fireStreamCallbacks('afterSynthesis', $synthesisResponse);
        yield from $this->fireStreamCallbacks('loopComplete', $synthesisResponse, $stepsExecuted);

        return new PlanResult(
            response: $synthesisResponse,
            plan: $planDescriptions,
            steps: $executedSteps,
            replans: $replanCount,
        );
    }

    // ─── Prompt Builders (Override to Customize) ────────────────────

    /**
     * Build the prompt that asks the LLM to create a plan.
     *
     * Override this method to customize how plans are requested.
     */
    protected function buildPlanningPrompt(string $task): string
    {
        return <<<PROMPT
        Create a step-by-step plan to accomplish the following task. Each step should be a single, actionable item.

        Format your plan as a numbered list:
        1. First step
        2. Second step
        3. Third step
        (and so on)

        Keep the plan focused and concise. Only include steps that are necessary.

        Task: {$task}
        PROMPT;
    }

    /**
     * Build the prompt that asks the LLM to execute a specific step.
     *
     * Override this method to customize how steps are executed.
     *
     * @param  list<string>    $plan           Full plan step descriptions
     * @param  list<PlanStep>  $previousSteps  Already-executed steps
     */
    protected function buildStepExecutionPrompt(
        string $task,
        array $plan,
        int $stepNumber,
        string $stepDescription,
        array $previousSteps,
    ): string {
        $planFormatted = collect($plan)
            ->map(fn(string $desc, int $i) => ($i + 1) . '. ' . $desc)
            ->implode("\n");

        $previousContext = '';

        if (! empty($previousSteps)) {
            $previousContext = "\n\nResults from previous steps:\n";

            foreach ($previousSteps as $step) {
                $previousContext .= "\nStep {$step->number} ({$step->description}):\n{$step->result()}\n";
            }
        }

        return <<<PROMPT
        You are executing step {$stepNumber} of a plan.

        Original task: {$task}

        Full plan:
        {$planFormatted}
        {$previousContext}
        Current step to execute: Step {$stepNumber} — {$stepDescription}

        Execute this step thoroughly. Use any available tools if needed. Provide a clear result.
        PROMPT;
    }

    /**
     * Build the prompt that asks the LLM to synthesize all step results.
     *
     * Override this method to customize how results are synthesized.
     *
     * @param  list<PlanStep>  $steps
     */
    protected function buildSynthesisPrompt(string $task, array $steps): string
    {
        $resultsFormatted = '';

        foreach ($steps as $step) {
            $resultsFormatted .= "\nStep {$step->number} ({$step->description}):\n{$step->result()}\n";
        }

        return <<<PROMPT
        You have completed a multi-step plan for the following task:

        Task: {$task}

        Step results:
        {$resultsFormatted}

        Synthesize all the step results into a comprehensive, well-organized final answer for the user. Be thorough but concise.
        PROMPT;
    }

    // ─── Plan Parsing ───────────────────────────────────────────────

    /**
     * Parse numbered steps from the LLM's plan response.
     *
     * Recognizes formats like "1. Step", "1) Step", "Step 1: Step".
     *
     * Override this method to customize plan parsing.
     *
     * @return list<string>
     */
    protected function parsePlanSteps(string $planText): array
    {
        // Match numbered list items: "1. ...", "1) ...", "1: ..."
        preg_match_all('/^\s*\d+[.):\-]\s*(.+)$/m', $planText, $matches);

        return array_values(array_map('trim', $matches[1] ?? []));
    }

    // ─── Replanning ─────────────────────────────────────────────────

    /**
     * Determine if a step result indicates failure and replanning is needed.
     *
     * Override this method to customize failure detection.
     */
    protected function shouldReplan(AgentResponse $response): bool
    {
        $failureIndicators = [
            'error',
            'failed',
            'unable to',
            'cannot',
            'impossible',
            'not possible',
        ];

        $text = strtolower($response->text);

        foreach ($failureIndicators as $indicator) {
            if (str_contains($text, $indicator)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ask the LLM to create a revised plan based on what has happened so far.
     *
     * @param  list<string>    $originalPlan    The current plan
     * @param  list<PlanStep>  $completedSteps  Steps already executed
     * @return list<string>    New plan step descriptions
     */
    protected function revisePlan(
        string $task,
        array $originalPlan,
        array $completedSteps,
        int $failedStepNumber,
        AgentResponse $failedResponse,
        ?string $provider,
        ?string $model,
        ?int $timeout,
    ): array {
        $planFormatted = collect($originalPlan)
            ->map(fn(string $desc, int $i) => ($i + 1) . '. ' . $desc)
            ->implode("\n");

        $completedFormatted = '';

        foreach ($completedSteps as $step) {
            $status = $step->triggeredReplan ? ' (FAILED)' : '';
            $completedFormatted .= "\nStep {$step->number}{$status}: {$step->result()}\n";
        }

        $replanPrompt = <<<PROMPT
        The current plan encountered an issue at step {$failedStepNumber}.

        Original task: {$task}

        Original plan:
        {$planFormatted}

        Completed steps:
        {$completedFormatted}

        Failed step result: {$failedResponse->text}

        Create a revised plan to complete the remaining work. Account for what has already been accomplished and the failure above. Format as a numbered list starting from 1.
        PROMPT;

        $response = $this->prompt(
            prompt: $replanPrompt,
            provider: $provider,
            model: $model,
            timeout: $timeout,
        );

        return $this->parsePlanSteps($response->text);
    }

    // ─── Configuration Resolution ───────────────────────────────────

    /**
     * Resolve the maximum step count from the instance or config.
     */
    protected function resolveMaxSteps(): int
    {
        return $this->maxPlanSteps
            ?? (int) config('agentic.plan_execute.max_steps', 10);
    }

    /**
     * Resolve whether replanning is enabled from the instance or config.
     */
    protected function resolveAllowReplan(): bool
    {
        return $this->replanEnabled
            ?? (bool) config('agentic.plan_execute.allow_replan', true);
    }

    /**
     * Resolve the maximum replanning attempts from the instance or config.
     */
    protected function resolveMaxReplans(): int
    {
        return $this->maxReplanAttempts
            ?? (int) config('agentic.plan_execute.max_replans', 3);
    }
}
