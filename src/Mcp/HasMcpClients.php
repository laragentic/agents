<?php

declare(strict_types=1);

namespace Laragentic\Mcp;

use Closure;
use Illuminate\Support\Collection;
use Laragentic\Concerns\HasCallbacks;
use Laragentic\Mcp\Features\Roots\Root;

/**
 * Adds MCP client support to any Laravel AI SDK agent.
 *
 * This trait enables agents to connect to MCP servers and consume their
 * tools, resources, and prompts. MCP tools are automatically surfaced
 * as Laragentic tools that the LLM can use alongside native tools.
 *
 * Usage:
 *
 *     class MyAgent implements Agent, Conversational, HasTools
 *     {
 *         use Promptable, RemembersConversations, ReActLoop, HasMcpClients;
 *
 *         public function mcpServers(): array
 *         {
 *             return [
 *                 McpServer::stdio('filesystem', 'npx', ['-y', '@modelcontextprotocol/server-filesystem', '/tmp']),
 *                 McpServer::http('database', 'https://db-server.example.com/mcp'),
 *             ];
 *         }
 *     }
 */
trait HasMcpClients
{
    use HasCallbacks;

    /** @var array<string, McpClient> */
    private array $mcpClients = [];

    /** @var bool Whether MCP clients have been initialized */
    private bool $mcpInitialized = false;

    // ─── Server Configuration ───────────────────────────────────────

    /**
     * Define MCP server connections.
     *
     * Override this method to configure which MCP servers
     * this agent should connect to.
     *
     * @return array<McpServer>
     */
    public function mcpServers(): array
    {
        return [];
    }

    /**
     * Define filesystem roots for MCP servers.
     *
     * Override this method to configure which directories
     * servers can access.
     *
     * @return array<Root>
     */
    public function mcpRoots(): array
    {
        return [];
    }

    // ─── Fluent API ─────────────────────────────────────────────────

    /**
     * Add an MCP server connection.
     */
    public function withMcpServer(McpServer $server): static
    {
        $this->ensureMcpInfrastructure();

        $client = new McpClient($server);
        $this->mcpClients[$server->name] = $client;

        return $this;
    }

    /**
     * Add multiple MCP server connections.
     *
     * @param  array<McpServer>  $servers
     */
    public function withMcpServers(array $servers): static
    {
        foreach ($servers as $server) {
            $this->withMcpServer($server);
        }

        return $this;
    }

    // ─── Lifecycle ──────────────────────────────────────────────────

    /**
     * Connect to all configured MCP servers.
     */
    public function connectMcpServers(): void
    {
        $this->ensureMcpInfrastructure();

        $roots = $this->mcpRoots();

        foreach ($this->mcpClients as $client) {
            try {
                // Configure roots before connecting
                if (! empty($roots)) {
                    $client->roots()->setRoots($roots);
                }

                $client->connect();

                $this->fireCallbacks('mcpServerConnected', $client);
            } catch (\Throwable $e) {
                // Log the error but continue connecting other servers
                $this->fireCallbacks('mcpServerConnectionFailed', $client, $e);
            }
        }
    }

    /**
     * Disconnect from all MCP servers.
     */
    public function disconnectMcpServers(): void
    {
        foreach ($this->mcpClients as $client) {
            try {
                $client->disconnect();
                $this->fireCallbacks('mcpServerDisconnected', $client);
            } catch (\Throwable) {
                // Best effort shutdown
            }
        }
    }

    // ─── Discovery ──────────────────────────────────────────────────

    /**
     * Get all tools from all connected MCP servers.
     *
     * Returns Laragentic-compatible tool adapters.
     *
     * @return array<\Laravel\Ai\Contracts\Tool>
     */
    public function mcpTools(): array
    {
        $this->ensureMcpConnected();

        $tools = [];

        foreach ($this->mcpClients as $client) {
            if ($client->isConnected() && $client->serverCapabilities()?->supportsTools()) {
                try {
                    $tools = array_merge($tools, $client->tools()->toLaragenticTools());
                } catch (\Throwable) {
                    // Skip servers that fail to list tools
                }
            }
        }

        return $tools;
    }

    /**
     * Get all resources from all connected MCP servers.
     */
    public function mcpResources(): Collection
    {
        $this->ensureMcpConnected();

        $resources = collect();

        foreach ($this->mcpClients as $name => $client) {
            if ($client->isConnected() && $client->serverCapabilities()?->supportsResources()) {
                try {
                    $serverResources = $client->resources()->listAll();
                    $resources = $resources->merge(
                        $serverResources->mapWithKeys(fn ($r) => ["{$name}:{$r->uri}" => $r])
                    );
                } catch (\Throwable) {
                    // Skip servers that fail
                }
            }
        }

        return $resources;
    }

    /**
     * Get all prompts from all connected MCP servers.
     */
    public function mcpPrompts(): Collection
    {
        $this->ensureMcpConnected();

        $prompts = collect();

        foreach ($this->mcpClients as $name => $client) {
            if ($client->isConnected() && $client->serverCapabilities()?->supportsPrompts()) {
                try {
                    $serverPrompts = $client->prompts()->listAll();
                    $prompts = $prompts->merge(
                        $serverPrompts->mapWithKeys(fn ($p) => ["{$name}:{$p->name}" => $p])
                    );
                } catch (\Throwable) {
                    // Skip servers that fail
                }
            }
        }

        return $prompts;
    }

    /**
     * Get a specific MCP client by server name.
     */
    public function mcpClient(string $name): ?McpClient
    {
        return $this->mcpClients[$name] ?? null;
    }

    /**
     * Get all MCP clients.
     *
     * @return array<string, McpClient>
     */
    public function mcpClientMap(): array
    {
        return $this->mcpClients;
    }

    // ─── Callbacks ──────────────────────────────────────────────────

    /**
     * Register a callback for when an MCP server connects.
     */
    public function onMcpServerConnected(Closure $callback): static
    {
        $this->loopCallbacks['mcpServerConnected'][] = $callback;

        return $this;
    }

    /**
     * Register a callback for when an MCP server disconnects.
     */
    public function onMcpServerDisconnected(Closure $callback): static
    {
        $this->loopCallbacks['mcpServerDisconnected'][] = $callback;

        return $this;
    }

    /**
     * Register a callback for when an MCP server connection fails.
     */
    public function onMcpServerConnectionFailed(Closure $callback): static
    {
        $this->loopCallbacks['mcpServerConnectionFailed'][] = $callback;

        return $this;
    }

    /**
     * Register a callback for when an MCP tool is called.
     */
    public function onMcpToolCalled(Closure $callback): static
    {
        $this->loopCallbacks['mcpToolCalled'][] = $callback;

        return $this;
    }

    /**
     * Register a callback for when a sampling request is received.
     */
    public function onMcpSamplingRequested(Closure $callback): static
    {
        $this->loopCallbacks['mcpSamplingRequested'][] = $callback;

        return $this;
    }

    /**
     * Register a callback for when a resource is updated.
     */
    public function onMcpResourceUpdated(Closure $callback): static
    {
        $this->loopCallbacks['mcpResourceUpdated'][] = $callback;

        return $this;
    }

    // ─── Tool Resolution Integration ────────────────────────────────

    /**
     * Resolve MCP tools and merge them into the tool map.
     *
     * This method is called by the loop traits' resolveToolMap() method
     * to include MCP tools alongside native tools. The loops call this
     * automatically if HasMcpClients is used.
     *
     * @return array<string, \Laravel\Ai\Contracts\Tool>
     */
    protected function resolveMcpTools(): array
    {
        $tools = [];

        foreach ($this->mcpTools() as $tool) {
            $name = method_exists($tool, 'name')
                ? call_user_func([$tool, 'name'])
                : class_basename($tool);

            $tools[$name] = $tool;
        }

        return $tools;
    }

    // ─── Infrastructure ─────────────────────────────────────────────

    /**
     * Initialize MCP infrastructure from server configuration.
     */
    private function ensureMcpInfrastructure(): void
    {
        if ($this->mcpInitialized) {
            return;
        }

        $this->mcpInitialized = true;

        // Load servers from the agent's mcpServers() method
        foreach ($this->mcpServers() as $server) {
            if (! isset($this->mcpClients[$server->name])) {
                $this->mcpClients[$server->name] = new McpClient($server);
            }
        }
    }

    /**
     * Ensure MCP servers are connected.
     */
    private function ensureMcpConnected(): void
    {
        $this->ensureMcpInfrastructure();

        // Auto-connect if not yet connected
        $anyConnected = false;
        foreach ($this->mcpClients as $client) {
            if ($client->isConnected()) {
                $anyConnected = true;

                break;
            }
        }

        if (! $anyConnected && ! empty($this->mcpClients)) {
            $this->connectMcpServers();
        }
    }
}
