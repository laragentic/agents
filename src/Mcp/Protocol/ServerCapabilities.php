<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Protocol;

/**
 * Parsed server capabilities from the initialize response.
 *
 * Determines which features the server supports so the client
 * knows what methods it can call.
 */
class ServerCapabilities
{
    public function __construct(
        public readonly ?array $tools = null,
        public readonly ?array $resources = null,
        public readonly ?array $prompts = null,
        public readonly ?array $logging = null,
        public readonly ?array $completions = null,
    ) {}

    /**
     * Parse from the initialize response capabilities object.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tools: $data['tools'] ?? null,
            resources: $data['resources'] ?? null,
            prompts: $data['prompts'] ?? null,
            logging: $data['logging'] ?? null,
            completions: $data['completions'] ?? null,
        );
    }

    /**
     * Whether the server supports tools.
     */
    public function supportsTools(): bool
    {
        return $this->tools !== null;
    }

    /**
     * Whether the server supports tools/list_changed notifications.
     */
    public function supportsToolsListChanged(): bool
    {
        return $this->tools['listChanged'] ?? false;
    }

    /**
     * Whether the server supports resources.
     */
    public function supportsResources(): bool
    {
        return $this->resources !== null;
    }

    /**
     * Whether the server supports resource subscriptions.
     */
    public function supportsResourceSubscriptions(): bool
    {
        return $this->resources['subscribe'] ?? false;
    }

    /**
     * Whether the server supports resources/list_changed notifications.
     */
    public function supportsResourcesListChanged(): bool
    {
        return $this->resources['listChanged'] ?? false;
    }

    /**
     * Whether the server supports prompts.
     */
    public function supportsPrompts(): bool
    {
        return $this->prompts !== null;
    }

    /**
     * Whether the server supports prompts/list_changed notifications.
     */
    public function supportsPromptsListChanged(): bool
    {
        return $this->prompts['listChanged'] ?? false;
    }

    /**
     * Whether the server supports logging.
     */
    public function supportsLogging(): bool
    {
        return $this->logging !== null;
    }

    /**
     * Whether the server supports completions.
     */
    public function supportsCompletions(): bool
    {
        return $this->completions !== null;
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $capabilities = [];

        if ($this->tools !== null) {
            $capabilities['tools'] = $this->tools;
        }

        if ($this->resources !== null) {
            $capabilities['resources'] = $this->resources;
        }

        if ($this->prompts !== null) {
            $capabilities['prompts'] = $this->prompts;
        }

        if ($this->logging !== null) {
            $capabilities['logging'] = $this->logging;
        }

        if ($this->completions !== null) {
            $capabilities['completions'] = $this->completions;
        }

        return $capabilities;
    }
}
