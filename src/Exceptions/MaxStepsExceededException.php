<?php

declare(strict_types=1);

namespace Laragentic\Exceptions;

use Laravel\Ai\Responses\AgentResponse;
use RuntimeException;

class MaxStepsExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $maxSteps,
        public readonly ?AgentResponse $lastResponse = null,
    ) {
        parent::__construct(
            "Plan-execute loop exceeded the maximum of {$maxSteps} steps.",
        );
    }
}
