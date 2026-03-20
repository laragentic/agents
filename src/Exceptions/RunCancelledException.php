<?php

declare(strict_types=1);

namespace Laragentic\Exceptions;

use RuntimeException;

/**
 * Thrown when a durable run is cancelled while executing.
 *
 * The loop checks for external cancellation between iterations.
 * When a cancellation is detected the loop saves its current state,
 * marks the run as cancelled, and throws this exception so the
 * calling code (queue job, controller, etc.) can react appropriately.
 */
class RunCancelledException extends RuntimeException
{
    public function __construct(
        public readonly string $runId,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Agent run '{$runId}' was cancelled.",
        );
    }
}
