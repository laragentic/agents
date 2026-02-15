<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Events;

class McpProgressUpdated
{
    public function __construct(
        public readonly string $serverName,
        public readonly string $progressToken,
        public readonly float $progress,
        public readonly ?float $total,
        public readonly ?string $message,
    ) {}
}
