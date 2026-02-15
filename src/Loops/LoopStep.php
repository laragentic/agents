<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laravel\Ai\Responses\AgentResponse;

/**
 * Represents a single iteration (step) within an agentic loop.
 *
 * Each step captures the LLM's thought (response), any actions taken
 * (tool calls), and the observation (formatted tool results fed back).
 */
class LoopStep
{
    /**
     * @param  array<int, array{tool: string, arguments: array<string, mixed>, result: mixed}>  $toolCalls
     */
    public function __construct(
        public readonly int $iteration,
        public readonly AgentResponse $response,
        public readonly array $toolCalls = [],
        public readonly ?string $observation = null,
    ) {}

    /**
     * Determine if this step involved any tool calls (action).
     */
    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }

    /**
     * Determine if this step is a final answer (no tool calls).
     */
    public function isFinalAnswer(): bool
    {
        return ! $this->hasToolCalls();
    }

    /**
     * Determine if this step produced an observation.
     */
    public function hasObservation(): bool
    {
        return $this->observation !== null;
    }
}
