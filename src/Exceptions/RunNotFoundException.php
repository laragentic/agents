<?php

declare(strict_types=1);

namespace Laragentic\Exceptions;

use RuntimeException;

/**
 * Thrown when a run_id is not found in the database.
 *
 * Raised by AgentRun::findByRunId() and by HasDurableRuns::resumeRun()
 * when the caller passes an unknown run ID.
 */
class RunNotFoundException extends RuntimeException
{
    public function __construct(
        public readonly string $runId,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Agent run '{$runId}' not found.",
        );
    }
}
