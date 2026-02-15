<?php

declare(strict_types=1);

namespace Laragentic\Exceptions;

use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;

class MaxIterationsExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $maxIterations,
        public readonly ?AgentResponse $lastResponse = null,
    ) {
        parent::__construct(
            "ReAct loop exceeded the maximum of {$maxIterations} iterations.",
        );
    }
}
