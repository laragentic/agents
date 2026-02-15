<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Utilities;

use Closure;
use Illuminate\Support\Facades\Log;
use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Receives log messages from the MCP server.
 *
 * Forwards to Laravel's logging system and custom handlers.
 */
class LogReceiver
{
    private string $currentLevel = 'info';

    /** @var list<Closure> */
    private array $handlers = [];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * Set the minimum log level on the server.
     *
     * Levels: debug, info, notice, warning, error, critical, alert, emergency
     */
    public function setLevel(string $level): void
    {
        $this->currentLevel = $level;

        $this->client->request('logging/setLevel', [
            'level' => $level,
        ]);
    }

    /**
     * Handle a log message notification from the server.
     */
    public function handleLogMessage(JsonRpcMessage $message): void
    {
        $params = $message->params ?? [];

        $level = $params['level'] ?? 'info';
        $logger = $params['logger'] ?? null;
        $data = $params['data'] ?? null;

        // Forward to Laravel logging
        $channel = config('agentic.mcp.logging.channel', 'stack');

        $context = [];
        if ($logger !== null) {
            $context['mcp_logger'] = $logger;
        }
        if ($data !== null) {
            $context['mcp_data'] = $data;
        }

        $logMessage = is_string($data) ? $data : json_encode($data);

        Log::channel($channel)->log($level, "[MCP] {$logMessage}", $context);

        // Fire custom handlers
        foreach ($this->handlers as $handler) {
            $handler($level, $logger, $data);
        }
    }

    /**
     * Register a handler for log messages.
     *
     * Receives: (string $level, ?string $logger, mixed $data)
     */
    public function onMessage(Closure $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Get the current log level.
     */
    public function level(): string
    {
        return $this->currentLevel;
    }
}
