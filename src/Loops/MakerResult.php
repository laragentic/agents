<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laravel\Ai\Responses\AgentResponse;

/**
 * Wraps the final AgentResponse with MAKER loop metadata.
 *
 * Provides access to:
 * - The final response from the agent
 * - Total steps executed (decompositions + executions + compositions)
 * - Detailed execution statistics (votes, red flags, decompositions, etc.)
 * - All steps taken during execution
 * - Whether max iterations was reached
 *
 * Delegates property access to the underlying AgentResponse so that
 * $result->text, $result->conversationId, etc. work seamlessly.
 */
class MakerResult
{
    /**
     * @param  list<MakerStep>  $steps
     * @param  array{total_steps: int, atomic_executions: int, decompositions: int, compositions: int, subtasks_created: int, votes_cast: int, red_flags_detected: int, max_depth_reached: int}  $executionStats
     */
    public function __construct(
        public readonly AgentResponse $response,
        public readonly int $totalSteps,
        public readonly array $executionStats,
        public readonly array $steps = [],
        public readonly bool $reachedMaxIterations = false,
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
     * Determine if the loop completed naturally (within iteration limit).
     */
    public function completed(): bool
    {
        return ! $this->reachedMaxIterations;
    }

    /**
     * Calculate the estimated error rate based on voting patterns.
     *
     * More votes needed typically indicates higher uncertainty/error rate.
     */
    public function errorRate(): float
    {
        if ($this->executionStats['atomic_executions'] === 0) {
            return 0.0;
        }

        $avgVotesPerStep = $this->executionStats['votes_cast'] / max(1, $this->executionStats['atomic_executions']);

        // If we need more votes, error rate is higher
        $estimatedErrorRate = 1 - (1 / $avgVotesPerStep);

        return round($estimatedErrorRate, 4);
    }

    /**
     * Get all steps of a specific type.
     *
     * @return array<int, MakerStep>
     */
    public function stepsByType(string $type): array
    {
        return array_filter(
            $this->steps,
            fn (MakerStep $step) => $step->type === $type
        );
    }

    /**
     * Get all decomposition steps.
     *
     * @return array<int, MakerStep>
     */
    public function decompositions(): array
    {
        return $this->stepsByType('decompose');
    }

    /**
     * Get all atomic execution steps.
     *
     * @return array<int, MakerStep>
     */
    public function atomicExecutions(): array
    {
        return $this->stepsByType('execute');
    }

    /**
     * Get all composition steps.
     *
     * @return array<int, MakerStep>
     */
    public function compositions(): array
    {
        return $this->stepsByType('compose');
    }

    /**
     * Get steps where red flags were detected.
     *
     * @return array<int, MakerStep>
     */
    public function stepsWithRedFlags(): array
    {
        return array_filter(
            $this->steps,
            fn (MakerStep $step) => $step->hadRedFlags()
        );
    }

    /**
     * Get the deepest decomposition depth reached.
     */
    public function maxDepthReached(): int
    {
        return $this->executionStats['max_depth_reached'];
    }

    /**
     * Get summaries of all steps.
     *
     * Useful for logging or displaying execution progress.
     *
     * @return array<int, array{iteration: int, type: string, task_length: int, votes: int, total_votes: int, red_flags: int, depth: int, consensus: bool}>
     */
    public function stepSummaries(): array
    {
        return array_map(
            fn (MakerStep $step) => $step->summary(),
            $this->steps
        );
    }

    /**
     * Get a tree representation of the decomposition hierarchy.
     *
     * Returns nested array showing how tasks were decomposed.
     *
     * @return array<string, mixed>
     */
    public function decompositionTree(): array
    {
        $tree = [
            'task' => 'root',
            'type' => 'root',
            'depth' => 0,
            'children' => [],
        ];

        // Build tree from steps
        foreach ($this->steps as $step) {
            if ($step->type === 'decompose' && ! empty($step->subtasks)) {
                // Add decomposition node
                $tree['children'][] = [
                    'task' => substr($step->task, 0, 50) . (strlen($step->task) > 50 ? '...' : ''),
                    'type' => 'decompose',
                    'depth' => $step->depth,
                    'subtasks' => count($step->subtasks),
                ];
            }
        }

        return $tree;
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
