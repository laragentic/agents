<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laragentic\Signals\AskHumanSignal;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Wraps the final AgentResponse with Chain-of-Thought loop metadata.
 *
 * Provides access to:
 * - The final response from the agent
 * - Number of reasoning iterations executed
 * - All reasoning steps with their observations
 * - Whether max iterations was reached
 *
 * Delegates property access to the underlying AgentResponse so that
 * $result->text, $result->conversationId, etc. work seamlessly.
 */
class CoTResult
{
    /**
     * @param  list<CoTStep>  $steps
     */
    public function __construct(
        public readonly AgentResponse $response,
        public readonly int $reasoningIterations,
        public readonly array $steps = [],
        public readonly bool $reachedMaxIterations = false,
        public readonly ?AskHumanSignal $askHumanSignal = null,
    ) {}

    /**
     * Get the text content of the final response.
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
     * Determine if the loop completed naturally (agent expressed confidence).
     *
     * Returns false when the loop was interrupted by an AskHumanSignal
     * or when max iterations were reached.
     */
    public function completed(): bool
    {
        return ! $this->reachedMaxIterations && $this->askHumanSignal === null;
    }

    /**
     * Determine if the loop was paused to ask the human a question.
     */
    public function askedHuman(): bool
    {
        return $this->askHumanSignal !== null;
    }

    /**
     * Get all tool calls made across all reasoning iterations.
     *
     * @return array<int, array{tool: string, arguments: array<string, mixed>, result: string, iteration: int}>
     */
    public function allToolCalls(): array
    {
        $calls = [];

        foreach ($this->steps as $step) {
            foreach ($step->toolCalls as $toolCall) {
                $calls[] = [
                    ...$toolCall,
                    'iteration' => $step->iteration,
                ];
            }
        }

        return $calls;
    }

    /**
     * Get just the reasoning text from each step.
     *
     * Returns an array of strings, one for each iteration's reasoning.
     *
     * @return array<int, string>
     */
    public function reasoningSteps(): array
    {
        return array_map(
            fn (CoTStep $step) => $step->reasoning(),
            $this->steps
        );
    }

    /**
     * Get summaries of all reasoning steps.
     *
     * Useful for logging or displaying reasoning progress.
     *
     * @return array<int, array{iteration: int, reasoning_length: int, tool_calls: int, has_observation: bool, confident: bool}>
     */
    public function stepSummaries(): array
    {
        return array_map(
            fn (CoTStep $step) => $step->summary(),
            $this->steps
        );
    }

    /**
     * Get the step where the agent first expressed confidence.
     *
     * Returns null if agent never became confident (hit max iterations).
     */
    public function confidentStep(): ?CoTStep
    {
        foreach ($this->steps as $step) {
            if ($step->confident) {
                return $step;
            }
        }

        return null;
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
