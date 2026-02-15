<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Events;

class McpServerDisconnected
{
    public function __construct(
        public readonly string $serverName,
    ) {}
}
