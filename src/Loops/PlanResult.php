<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laragentic\Contracts\PausesLoop;
use Laragentic\Signals\AskHumanSignal;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Wraps the final synthesized AgentResponse with metadata about
 * the plan-execute loop execution.
 *
 * Delegates property access to the underlying AgentResponse so that
 * $result->text, $result->conversationId, etc. work seamlessly.
 */
class PlanResult
{
    /**
     * @param  list<string>     $plan           The original plan step descriptions
     * @param  list<PlanStep>   $steps          Executed step objects with responses
     * @param  list<string>     $deferredSteps  Plan step descriptions not yet executed due to pause
     */
    public function __construct(
        public readonly AgentResponse $response,
        public readonly array $plan,
        public readonly array $steps,
        public readonly int $replans = 0,
        public readonly bool $reachedMaxSteps = false,
        public readonly ?AskHumanSignal $askHumanSignal = null,
        public readonly ?PausesLoop $pauseSignal = null,
        public readonly array $deferredSteps = [],
    ) {}

    /**
     * Get the text content of the final synthesized response.
     */
    public function text(): string
    {
        return $this->response->text;
    }

    /**
     * Get the conversation ID from the final response.
     */
    public function conversationId(): ?string
    {
        return $this->response->conversationId;
    }

    /**
     * Determine if the loop completed naturally (all steps executed + synthesized).
     *
     * Returns false when the loop was interrupted by an AskHumanSignal,
     * PausesLoop, or when max steps were reached.
     */
    public function completed(): bool
    {
        return ! $this->reachedMaxSteps
            && $this->askHumanSignal === null
            && $this->pauseSignal === null;
    }

    /**
     * Determine if the loop was paused to ask the human a question.
     */
    public function askedHuman(): bool
    {
        return $this->askHumanSignal !== null;
    }

    /**
     * Determine if the loop was paused by a PausesLoop tool result.
     */
    public function paused(): bool
    {
        return $this->pauseSignal !== null;
    }

    /**
     * Get the total number of steps that were executed.
     */
    public function stepsExecuted(): int
    {
        return count(array_filter($this->steps, fn(PlanStep $s) => $s->isExecuted()));
    }

    /**
     * Get the total number of steps in the plan.
     */
    public function totalPlannedSteps(): int
    {
        return count($this->plan);
    }

    /**
     * Determine if replanning occurred during execution.
     */
    public function wasReplanned(): bool
    {
        return $this->replans > 0;
    }

    /**
     * Get all step results as an associative array.
     *
     * @return array<int, array{step: int, description: string, result: ?string}>
     */
    public function stepResults(): array
    {
        return array_map(fn(PlanStep $step) => [
            'step' => $step->number,
            'description' => $step->description,
            'result' => $step->result(),
        ], $this->steps);
    }

    /**
     * Delegate property access to the underlying AgentResponse.
     */
    public function __get(string $name): mixed
    {
        return $this->response->{$name};
    }

    /**
     * Delegate string conversion to the underlying AgentResponse.
     */
    public function __toString(): string
    {
        return (string) $this->response;
    }
}
