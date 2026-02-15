<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Laravel\Ai\Responses\AgentResponse;

/**
 * Represents a single step in a MAKER loop execution.
 *
 * Each step captures one of three operation types:
 * - decompose: Breaking a task into subtasks with voting
 * - execute: Executing an atomic task with voting
 * - compose: Combining subtask results with voting
 */
class MakerStep
{
    /**
     * @param  array<string, array{response: string, votes: int}>  $candidates
     * @param  array<int, mixed>  $subtasks
     */
    public function __construct(
        public readonly int $iteration,
        public readonly string $type, // 'decompose' | 'execute' | 'compose'
        public readonly string $task,
        public readonly ?AgentResponse $response = null,
        public readonly ?string $result = null,
        public readonly int $votes = 0,
        public readonly int $totalVotes = 0,
        public readonly array $candidates = [],
        public readonly int $redFlagsDetected = 0,
        public readonly int $depth = 0,
        public readonly array $subtasks = [],
    ) {}

    /**
     * Check if this step was atomic execution (not decomposed).
     */
    public function isAtomic(): bool
    {
        return $this->type === 'execute';
    }

    /**
     * Check if this step was a decomposition.
     */
    public function wasDecomposed(): bool
    {
        return $this->type === 'decompose';
    }

    /**
     * Check if this step was composition.
     */
    public function wasComposed(): bool
    {
        return $this->type === 'compose';
    }

    /**
     * Check if consensus was reached during voting.
     */
    public function consensusReached(): bool
    {
        return $this->votes > 0 && $this->totalVotes > 0;
    }

    /**
     * Check if any red flags were detected during this step.
     */
    public function hadRedFlags(): bool
    {
        return $this->redFlagsDetected > 0;
    }

    /**
     * Get a summary of this step.
     *
     * @return array{iteration: int, type: string, task_length: int, votes: int, total_votes: int, red_flags: int, depth: int, consensus: bool}
     */
    public function summary(): array
    {
        return [
            'iteration' => $this->iteration,
            'type' => $this->type,
            'task_length' => strlen($this->task),
            'votes' => $this->votes,
            'total_votes' => $this->totalVotes,
            'red_flags' => $this->redFlagsDetected,
            'depth' => $this->depth,
            'consensus' => $this->consensusReached(),
        ];
    }

    /**
     * Get the number of candidates that participated in voting.
     */
    public function candidateCount(): int
    {
        return count($this->candidates);
    }
}
