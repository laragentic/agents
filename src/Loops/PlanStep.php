<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laravel\Ai\Responses\AgentResponse;

/**
 * Represents a single step within a plan-execute loop.
 *
 * Captures the step description from the plan and, once executed,
 * the LLM's response for that step.
 */
class PlanStep
{
    public function __construct(
        public readonly int $number,
        public readonly string $description,
        public readonly ?AgentResponse $response = null,
        public readonly bool $triggeredReplan = false,
    ) {}

    /**
     * Determine if this step has been executed.
     */
    public function isExecuted(): bool
    {
        return $this->response !== null;
    }

    /**
     * Get the text result of this step's execution.
     */
    public function result(): ?string
    {
        return $this->response?->text;
    }

    /**
     * Create a new instance with the execution response attached.
     */
    public function withResponse(AgentResponse $response, bool $triggeredReplan = false): static
    {
        return new static(
            number: $this->number,
            description: $this->description,
            response: $response,
            triggeredReplan: $triggeredReplan,
        );
    }
}
