<?php

declare(strict_types=1);

namespace Laragentic\Runs;

/**
 * Lifecycle states for a durable agent run.
 *
 * Transitions:
 *
 *   pending ──► running ──► completed
 *                    │
 *                    ├──► failed
 *                    ├──► cancelled
 *                    └──► paused  (awaiting human input via AskHumanSignal)
 *                           └──► running  (when human response is provided)
 */
enum RunStatus: string
{
    /** Created but not yet started (e.g. queued). */
    case Pending = 'pending';

    /** Currently executing in a worker process. */
    case Running = 'running';

    /** Loop finished with a final LLM answer. */
    case Completed = 'completed';

    /** Loop terminated due to an unrecoverable error. */
    case Failed = 'failed';

    /** Explicitly cancelled by the application. */
    case Cancelled = 'cancelled';

    /**
     * Paused pending a human response (AskHumanSignal).
     *
     * The run can be resumed by calling durableReactLoop() again with
     * the same run_id after appending the human answer to the prompt.
     */
    case Paused = 'paused';

    /**
     * Returns true if the run has reached a terminal state and cannot
     * be resumed.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed,
            self::Failed,
            self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Returns true if the run can be resumed.
     */
    public function isResumable(): bool
    {
        return match ($this) {
            self::Paused,
            self::Running,  // e.g. worker crashed while status was still "running"
            self::Pending => true,
            default => false,
        };
    }
}
