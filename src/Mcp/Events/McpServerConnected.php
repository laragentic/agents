<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Events;

use Laragentic\Mcp\McpClient;

class McpServerConnected
{
    public function __construct(
        public readonly McpClient $client,
        public readonly string $serverName,
    ) {}
}
