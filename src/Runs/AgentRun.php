<?php

declare(strict_types=1);

namespace Laragentic\Runs;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a durable agent run stored in the database.
 *
 * A run encapsulates the full lifecycle of a single agentic loop
 * execution: its initial prompt, current status, all checkpoints,
 * and the final response.
 *
 * Usage:
 *
 *     // Find a run by its public ID
 *     $run = AgentRun::findByRunId('run_abc123');
 *
 *     // Check if still in progress
 *     if ($run->isRunning()) { ... }
 *
 *     // Cancel from outside the loop
 *     $run->markCancelled();
 */
class AgentRun extends Model
{
    protected $table = 'agent_runs';

    protected $fillable = [
        'run_id',
        'agent_class',
        'loop_type',
        'status',
        'initial_prompt',
        'final_response',
        'metadata',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'paused_at',
        'failure_reason',
    ];

    protected $casts = [
        'metadata'     => 'array',
        'started_at'   => 'datetime',
        'completed_at' => 'datetime',
        'failed_at'    => 'datetime',
        'cancelled_at' => 'datetime',
        'paused_at'    => 'datetime',
        'status'       => RunStatus::class,
    ];

    // ─── Relationships ───────────────────────────────────────────────

    public function checkpoints(): HasMany
    {
        return $this->hasMany(AgentCheckpoint::class, 'run_id', 'run_id')
            ->orderBy('iteration');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class, 'run_id', 'run_id');
    }

    // ─── Finders ────────────────────────────────────────────────────

    /**
     * Find a run by its string run_id (UUID).
     *
     * @throws \Laragentic\Exceptions\RunNotFoundException
     */
    public static function findByRunId(string $runId): static
    {
        $run = static::where('run_id', $runId)->first();

        if ($run === null) {
            throw new \Laragentic\Exceptions\RunNotFoundException($runId);
        }

        return $run;
    }

    // ─── Status Checks ──────────────────────────────────────────────

    public function isPending(): bool
    {
        return $this->status === RunStatus::Pending;
    }

    public function isRunning(): bool
    {
        return $this->status === RunStatus::Running;
    }

    public function isCompleted(): bool
    {
        return $this->status === RunStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === RunStatus::Failed;
    }

    public function isCancelled(): bool
    {
        return $this->status === RunStatus::Cancelled;
    }

    public function isPaused(): bool
    {
        return $this->status === RunStatus::Paused;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isResumable(): bool
    {
        return $this->status->isResumable();
    }

    // ─── Lifecycle Transitions ───────────────────────────────────────

    public function markRunning(): void
    {
        $this->update([
            'status'     => RunStatus::Running,
            'started_at' => $this->started_at ?? now(),
        ]);
    }

    public function markCompleted(string $finalResponse): void
    {
        $this->update([
            'status'         => RunStatus::Completed,
            'final_response' => $finalResponse,
            'completed_at'   => now(),
        ]);
    }

    public function markFailed(string $reason): void
    {
        $this->update([
            'status'         => RunStatus::Failed,
            'failure_reason' => $reason,
            'failed_at'      => now(),
        ]);
    }

    public function markCancelled(): void
    {
        $this->update([
            'status'       => RunStatus::Cancelled,
            'cancelled_at' => now(),
        ]);
    }

    public function markPaused(): void
    {
        $this->update([
            'status'    => RunStatus::Paused,
            'paused_at' => now(),
        ]);
    }

    // ─── Checkpoint Helpers ──────────────────────────────────────────

    /**
     * Return the most recently saved checkpoint, or null if none exist.
     */
    public function latestCheckpoint(): ?AgentCheckpoint
    {
        return $this->checkpoints()->orderByDesc('iteration')->first();
    }

    /**
     * Return true if there are saved checkpoints to resume from.
     */
    public function hasCheckpoints(): bool
    {
        return $this->checkpoints()->exists();
    }

    /**
     * Reload this model's status from the database without touching
     * the rest of the in-memory state. Used by the loop to poll for
     * external cancellation signals between iterations.
     */
    public function freshStatus(): RunStatus
    {
        $raw = static::where('run_id', $this->run_id)->value('status');

        // Eloquent applies casts in value() on Laravel 10+, so $raw may
        // already be a RunStatus enum. Fall back to from() for older versions.
        return $raw instanceof RunStatus ? $raw : RunStatus::from($raw);
    }
}
