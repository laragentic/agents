<?php

declare(strict_types=1);

namespace Laragentic\Exceptions;

use RuntimeException;

/**
 * Thrown when a requested skill cannot be found.
 */
final class SkillNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Skill not found')
    {
        parent::__construct($message);
    }
}
