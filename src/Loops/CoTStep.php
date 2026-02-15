<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laravel\Ai\Responses\AgentResponse;

/**
 * Represents a single reasoning iteration in a Chain-of-Thought loop.
 *
 * Each step captures:
 * - The iteration number
 * - The agent's reasoning response
 * - Any tool calls executed during this iteration
 * - Tool observations fed back to the agent
 * - Whether the agent was confident at this step
 */
class CoTStep
{
    /**
     * @param  array<int, array{tool: string, arguments: array<string, mixed>, result: string}>  $toolCalls
     */
    public function __construct(
        public readonly int $iteration,
        public readonly AgentResponse $response,
        public readonly array $toolCalls = [],
        public readonly ?string $observation = null,
        public readonly bool $confident = false,
    ) {}

    /**
     * Check if this step involved tool calls.
     */
    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    /**
     * Check if this step has an observation (tool results).
     */
    public function hasObservation(): bool
    {
        return $this->observation !== null;
    }

    /**
     * Get the reasoning text from this step.
     *
     * This is the LLM's response text containing its step-by-step analysis.
     */
    public function reasoning(): string
    {
        return $this->response->text;
    }

    /**
     * Check if this was the final reasoning step (agent expressed confidence).
     */
    public function isFinal(): bool
    {
        return $this->confident;
    }

    /**
     * Get a summary of this reasoning step.
     *
     * @return array{iteration: int, reasoning_length: int, tool_calls: int, has_observation: bool, confident: bool}
     */
    public function summary(): array
    {
        return [
            'iteration' => $this->iteration,
            'reasoning_length' => strlen($this->response->text),
            'tool_calls' => count($this->toolCalls),
            'has_observation' => $this->hasObservation(),
            'confident' => $this->confident,
        ];
    }
}
