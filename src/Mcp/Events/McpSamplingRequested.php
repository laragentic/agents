<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Events;

use Laragentic\Mcp\Features\Sampling\SamplingRequest;

class McpSamplingRequested
{
    public function __construct(
        public readonly string $serverName,
        public readonly SamplingRequest $request,
    ) {}
}
