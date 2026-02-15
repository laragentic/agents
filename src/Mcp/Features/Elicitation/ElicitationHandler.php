<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Elicitation;

use Closure;
use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Handles elicitation/create requests from the MCP server.
 *
 * When the server needs user input (form data or URL consent),
 * it sends an elicitation request. This handler delegates to
 * configured callbacks.
 */
class ElicitationHandler
{
    /** @var Closure|null */
    private ?Closure $formHandler = null;

    /** @var Closure|null */
    private ?Closure $urlHandler = null;

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * Set the form elicitation handler.
     *
     * Receives: (array $requestedSchema, string $message) → array{action: string, content?: array}
     */
    public function onForm(Closure $handler): void
    {
        $this->formHandler = $handler;
    }

    /**
     * Set the URL elicitation handler.
     *
     * Receives: (string $url, string $message) → array{action: string}
     */
    public function onUrl(Closure $handler): void
    {
        $this->urlHandler = $handler;
    }

    /**
     * Handle an elicitation/create request from the server.
     */
    public function handleRequest(JsonRpcMessage $message): array
    {
        $params = $message->params ?? [];
        $mode = $params['mode'] ?? 'form';

        if ($mode === 'url' && $this->urlHandler !== null) {
            return ($this->urlHandler)(
                $params['url'] ?? '',
                $params['message'] ?? '',
            );
        }

        if ($mode === 'form' && $this->formHandler !== null) {
            return ($this->formHandler)(
                $params['requestedSchema'] ?? [],
                $params['message'] ?? '',
            );
        }

        // Default: decline if no handler is configured
        return ['action' => 'decline'];
    }
}
