<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Resources;

use Illuminate\Support\Collection;
use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Manages MCP resource operations.
 *
 * Provides list, read, subscribe, and template operations.
 */
class ResourceManager
{
    /** @var Collection<string, ResourceDefinition>|null */
    private ?Collection $cachedResources = null;

    /** @var list<\Closure> */
    private array $listChangedHandlers = [];

    /** @var list<\Closure> */
    private array $updatedHandlers = [];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * List resources with optional pagination cursor.
     */
    public function list(?string $cursor = null): array
    {
        $this->client->requireCapability('resources');

        $params = $cursor ? ['cursor' => $cursor] : [];
        $response = $this->client->request('resources/list', $params);

        $resources = [];
        foreach ($response['resources'] ?? [] as $item) {
            $resources[] = ResourceDefinition::fromArray($item);
        }

        return [
            'resources' => $resources,
            'nextCursor' => $response['nextCursor'] ?? null,
        ];
    }

    /**
     * List all resources, auto-paginating.
     */
    public function listAll(): Collection
    {
        if ($this->cachedResources !== null) {
            return $this->cachedResources;
        }

        $resources = collect();
        $cursor = null;

        do {
            $result = $this->list($cursor);

            foreach ($result['resources'] as $resource) {
                $resources->put($resource->uri, $resource);
            }

            $cursor = $result['nextCursor'];
        } while ($cursor !== null);

        $this->cachedResources = $resources;

        return $resources;
    }

    /**
     * Read a resource by URI.
     */
    public function read(string $uri): ResourceContents
    {
        $this->client->requireCapability('resources');

        $response = $this->client->request('resources/read', ['uri' => $uri]);

        $contents = $response['contents'] ?? [];

        if (empty($contents)) {
            return new ResourceContents(uri: $uri);
        }

        return ResourceContents::fromArray($contents[0]);
    }

    /**
     * List resource templates.
     */
    public function listTemplates(): Collection
    {
        $this->client->requireCapability('resources');

        $templates = collect();
        $cursor = null;

        do {
            $params = $cursor ? ['cursor' => $cursor] : [];
            $response = $this->client->request('resources/templates/list', $params);

            foreach ($response['resourceTemplates'] ?? [] as $item) {
                $template = ResourceTemplate::fromArray($item);
                $templates->put($template->uriTemplate, $template);
            }

            $cursor = $response['nextCursor'] ?? null;
        } while ($cursor !== null);

        return $templates;
    }

    /**
     * Subscribe to resource updates.
     */
    public function subscribe(string $uri): void
    {
        $this->client->requireCapability('resources.subscribe');

        $this->client->request('resources/subscribe', ['uri' => $uri]);
    }

    /**
     * Unsubscribe from resource updates.
     */
    public function unsubscribe(string $uri): void
    {
        $this->client->requireCapability('resources.subscribe');

        $this->client->request('resources/unsubscribe', ['uri' => $uri]);
    }

    /**
     * Handle a resources/list_changed notification.
     */
    public function handleListChanged(): void
    {
        $this->cachedResources = null;

        foreach ($this->listChangedHandlers as $handler) {
            $handler();
        }
    }

    /**
     * Handle a resources/updated notification.
     */
    public function handleResourceUpdated(JsonRpcMessage $message): void
    {
        $uri = $message->params['uri'] ?? null;

        if ($uri === null) {
            return;
        }

        foreach ($this->updatedHandlers as $handler) {
            $handler($uri);
        }
    }

    /**
     * Register a handler for when the resource list changes.
     */
    public function onListChanged(\Closure $handler): void
    {
        $this->listChangedHandlers[] = $handler;
    }

    /**
     * Register a handler for resource updates.
     */
    public function onResourceUpdated(\Closure $handler): void
    {
        $this->updatedHandlers[] = $handler;
    }

    /**
     * Clear the cached resource list.
     */
    public function clearCache(): void
    {
        $this->cachedResources = null;
    }
}
