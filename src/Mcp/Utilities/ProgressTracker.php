<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Utilities;

use Closure;
use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Tracks progress notifications from the MCP server.
 */
class ProgressTracker
{
    private int $nextToken = 1;

    /** @var list<Closure> */
    private array $handlers = [];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * Generate a progress token to attach to a request.
     */
    public function createToken(): string
    {
        return 'progress_' . $this->nextToken++;
    }

    /**
     * Register a handler for progress notifications.
     *
     * Receives: (string $progressToken, float $progress, ?float $total, ?string $message)
     */
    public function onProgress(Closure $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Handle a progress notification from the server.
     */
    public function handleProgress(JsonRpcMessage $message): void
    {
        $params = $message->params ?? [];

        $token = $params['progressToken'] ?? null;
        $progress = $params['progress'] ?? 0;
        $total = $params['total'] ?? null;
        $progressMessage = $params['message'] ?? null;

        if ($token === null) {
            return;
        }

        foreach ($this->handlers as $handler) {
            $handler($token, (float) $progress, $total !== null ? (float) $total : null, $progressMessage);
        }
    }
}
