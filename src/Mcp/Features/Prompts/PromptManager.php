<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Prompts;

use Illuminate\Support\Collection;
use Laragentic\Mcp\McpClient;

/**
 * Manages MCP prompt operations.
 */
class PromptManager
{
    /** @var Collection<string, PromptDefinition>|null */
    private ?Collection $cachedPrompts = null;

    /** @var list<\Closure> */
    private array $listChangedHandlers = [];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * List prompts with optional pagination cursor.
     */
    public function list(?string $cursor = null): array
    {
        $this->client->requireCapability('prompts');

        $params = $cursor ? ['cursor' => $cursor] : [];
        $response = $this->client->request('prompts/list', $params);

        $prompts = [];
        foreach ($response['prompts'] ?? [] as $item) {
            $prompts[] = PromptDefinition::fromArray($item);
        }

        return [
            'prompts' => $prompts,
            'nextCursor' => $response['nextCursor'] ?? null,
        ];
    }

    /**
     * List all prompts, auto-paginating.
     */
    public function listAll(): Collection
    {
        if ($this->cachedPrompts !== null) {
            return $this->cachedPrompts;
        }

        $prompts = collect();
        $cursor = null;

        do {
            $result = $this->list($cursor);

            foreach ($result['prompts'] as $prompt) {
                $prompts->put($prompt->name, $prompt);
            }

            $cursor = $result['nextCursor'];
        } while ($cursor !== null);

        $this->cachedPrompts = $prompts;

        return $prompts;
    }

    /**
     * Get a prompt by name with arguments.
     *
     * @return array<PromptMessage>
     */
    public function get(string $name, array $arguments = []): array
    {
        $this->client->requireCapability('prompts');

        $params = ['name' => $name];

        if (! empty($arguments)) {
            $params['arguments'] = $arguments;
        }

        $response = $this->client->request('prompts/get', $params);

        $messages = [];
        foreach ($response['messages'] ?? [] as $item) {
            $messages[] = PromptMessage::fromArray($item);
        }

        return $messages;
    }

    /**
     * Handle a prompts/list_changed notification.
     */
    public function handleListChanged(): void
    {
        $this->cachedPrompts = null;

        foreach ($this->listChangedHandlers as $handler) {
            $handler();
        }
    }

    /**
     * Register a handler for when the prompt list changes.
     */
    public function onListChanged(\Closure $handler): void
    {
        $this->listChangedHandlers[] = $handler;
    }

    /**
     * Clear the cached prompt list.
     */
    public function clearCache(): void
    {
        $this->cachedPrompts = null;
    }
}
