<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Roots;

use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Serves roots/list requests from the MCP server.
 *
 * Provides filesystem root boundaries that tell the server
 * which directories/files it should operate within.
 */
class RootsProvider
{
    /** @var array<Root> */
    private array $roots = [];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * Set the available roots.
     *
     * @param  array<Root>  $roots
     */
    public function setRoots(array $roots): void
    {
        $this->roots = $roots;
    }

    /**
     * Add a root.
     */
    public function addRoot(Root $root): void
    {
        $this->roots[] = $root;
    }

    /**
     * Get the configured roots.
     *
     * @return array<Root>
     */
    public function getRoots(): array
    {
        return $this->roots;
    }

    /**
     * Handle a roots/list request from the server.
     */
    public function handleList(JsonRpcMessage $message): array
    {
        return [
            'roots' => array_map(fn (Root $root) => $root->toArray(), $this->roots),
        ];
    }

    /**
     * Send a roots/list_changed notification to the server.
     */
    public function notifyChange(): void
    {
        $this->client->notify('notifications/roots/list_changed');
    }
}
