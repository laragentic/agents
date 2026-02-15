<?php

declare(strict_types=1);

namespace Laragentic\Exceptions;

use RuntimeException;

/**
 * Thrown when a skill file is malformed or missing required metadata.
 */
final class SkillValidationException extends RuntimeException
{
    public function __construct(string $message = 'Skill validation failed')
    {
        parent::__construct($message);
    }
}
