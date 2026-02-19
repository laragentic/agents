<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laragentic\Signals\AskHumanSignal;
use Laravel\Ai\Responses\AgentResponse;

/**
 * Wraps the final AgentResponse with metadata about the loop execution.
 *
 * Delegates property access to the underlying AgentResponse so that
 * $result->text, $result->conversationId, etc. work seamlessly.
 */
class LoopResult
{
    /**
     * @param  list<LoopStep>  $steps
     */
    public function __construct(
        public readonly AgentResponse $response,
        public readonly int $iterations,
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
     * Determine if the loop completed naturally (LLM gave a final answer).
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
     * Get all tool calls made across all iterations.
     *
     * @return array<int, array{tool: string, arguments: array<string, mixed>, result: mixed, iteration: int}>
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
