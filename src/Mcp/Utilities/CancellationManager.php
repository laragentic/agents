<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Utilities;

use Closure;
use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Manages request cancellation for MCP.
 */
class CancellationManager
{
    /** @var list<Closure> */
    private array $handlers = [];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * Cancel a pending request.
     *
     * MUST NOT cancel initialize requests per the spec.
     */
    public function cancel(string|int $requestId, ?string $reason = null): void
    {
        $params = ['requestId' => $requestId];

        if ($reason !== null) {
            $params['reason'] = $reason;
        }

        $this->client->notify('notifications/cancelled', $params);
    }

    /**
     * Handle a cancellation notification from the server.
     */
    public function handleCancelled(JsonRpcMessage $message): void
    {
        $params = $message->params ?? [];

        $requestId = $params['requestId'] ?? null;
        $reason = $params['reason'] ?? null;

        if ($requestId === null) {
            return;
        }

        foreach ($this->handlers as $handler) {
            $handler($requestId, $reason);
        }
    }

    /**
     * Register a handler for when the server cancels a request.
     */
    public function onCancelled(Closure $handler): void
    {
        $this->handlers[] = $handler;
    }
}
