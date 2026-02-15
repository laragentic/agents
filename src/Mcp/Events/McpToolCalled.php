<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Events;

class McpToolCalled
{
    public function __construct(
        public readonly string $serverName,
        public readonly string $toolName,
        public readonly array $arguments,
        public readonly string $result,
        public readonly float $duration,
    ) {}
}
