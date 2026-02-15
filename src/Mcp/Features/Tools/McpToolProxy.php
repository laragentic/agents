<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Tools;

use Illuminate\Support\Collection;
use Laragentic\Mcp\McpClient;

/**
 * Bridges MCP tools to Laragentic's tool system.
 *
 * Discovers MCP tools from the server and converts them to
 * Laragentic-compatible tool adapters that the LLM can use.
 */
class McpToolProxy
{
    /** @var Collection<string, ToolDefinition>|null */
    private ?Collection $cachedTools = null;

    /** @var list<\Closure> */
    private array $listChangedHandlers = [];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * List all available tools from the MCP server.
     *
     * Handles cursor-based pagination automatically.
     */
    public function list(): Collection
    {
        if ($this->cachedTools !== null) {
            return $this->cachedTools;
        }

        $this->client->requireCapability('tools');

        $tools = collect();
        $cursor = null;

        do {
            $params = $cursor ? ['cursor' => $cursor] : [];
            $response = $this->client->request('tools/list', $params);

            foreach ($response['tools'] ?? [] as $toolData) {
                $tool = ToolDefinition::fromArray($toolData);
                $tools->put($tool->name, $tool);
            }

            $cursor = $response['nextCursor'] ?? null;
        } while ($cursor !== null);

        $this->cachedTools = $tools;

        return $tools;
    }

    /**
     * Call a tool on the MCP server.
     */
    public function call(string $name, array $arguments = []): ToolResult
    {
        $this->client->requireCapability('tools');

        $result = $this->client->request('tools/call', [
            'name' => $name,
            'arguments' => $arguments,
        ]);

        return ToolResult::fromArray($result);
    }

    /**
     * Convert all MCP tools to Laragentic-compatible tool adapters.
     *
     * Each adapter implements Laravel\Ai\Contracts\Tool.
     *
     * @return array<McpToolAdapter>
     */
    public function toLaragenticTools(): array
    {
        $tools = $this->list();
        $adapters = [];

        foreach ($tools as $definition) {
            $adapters[] = new McpToolAdapter($definition, $this);
        }

        return $adapters;
    }

    /**
     * Handle a tools/list_changed notification from the server.
     *
     * Invalidates the cache and re-fetches the tool list.
     */
    public function handleListChanged(): void
    {
        $this->cachedTools = null;

        foreach ($this->listChangedHandlers as $handler) {
            $handler();
        }
    }

    /**
     * Register a handler for when the tool list changes.
     */
    public function onListChanged(\Closure $handler): void
    {
        $this->listChangedHandlers[] = $handler;
    }

    /**
     * Invalidate the cached tool list.
     */
    public function clearCache(): void
    {
        $this->cachedTools = null;
    }
}
