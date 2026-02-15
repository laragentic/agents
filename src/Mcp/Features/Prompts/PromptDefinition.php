<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Prompts;

/**
 * An MCP prompt definition received from a server.
 */
class PromptDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly array $arguments = [],
    ) {}

    /**
     * Parse from the prompts/list response item.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? null,
            arguments: $data['arguments'] ?? [],
        );
    }

    /**
     * Get the required arguments.
     */
    public function requiredArguments(): array
    {
        return array_filter($this->arguments, fn ($arg) => $arg['required'] ?? false);
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if (! empty($this->arguments)) {
            $data['arguments'] = $this->arguments;
        }

        return $data;
    }
}
