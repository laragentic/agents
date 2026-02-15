<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Tools;

/**
 * An MCP tool definition received from a server.
 */
class ToolDefinition
{
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly ?array $annotations = null,
    ) {}

    /**
     * Parse from the tools/list response item.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'],
            description: $data['description'] ?? '',
            inputSchema: $data['inputSchema'] ?? ['type' => 'object', 'properties' => []],
            annotations: $data['annotations'] ?? null,
        );
    }

    /**
     * Get the JSON Schema properties for this tool's input.
     */
    public function properties(): array
    {
        return $this->inputSchema['properties'] ?? [];
    }

    /**
     * Get the required properties for this tool's input.
     */
    public function required(): array
    {
        return $this->inputSchema['required'] ?? [];
    }

    /**
     * Check if the tool is annotated as read-only.
     */
    public function isReadOnly(): bool
    {
        return $this->annotations['readOnlyHint'] ?? false;
    }

    /**
     * Check if the tool is annotated as destructive.
     */
    public function isDestructive(): bool
    {
        return $this->annotations['destructiveHint'] ?? true;
    }

    /**
     * Check if the tool requires confirmation.
     */
    public function requiresConfirmation(): bool
    {
        return $this->annotations['confirmationHint'] ?? false;
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = [
            'name' => $this->name,
            'description' => $this->description,
            'inputSchema' => $this->inputSchema,
        ];

        if ($this->annotations !== null) {
            $data['annotations'] = $this->annotations;
        }

        return $data;
    }
}
