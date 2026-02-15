<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Events;

class McpResourceUpdated
{
    public function __construct(
        public readonly string $serverName,
        public readonly string $uri,
    ) {}
}
