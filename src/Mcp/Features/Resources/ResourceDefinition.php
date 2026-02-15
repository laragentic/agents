<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Resources;

/**
 * An MCP resource definition received from a server.
 */
class ResourceDefinition
{
    public function __construct(
        public readonly string $uri,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?int $size = null,
        public readonly ?array $annotations = null,
    ) {}

    /**
     * Parse from the resources/list response item.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uri: $data['uri'],
            name: $data['name'],
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            size: $data['size'] ?? null,
            annotations: $data['annotations'] ?? null,
        );
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = [
            'uri' => $this->uri,
            'name' => $this->name,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }

        if ($this->size !== null) {
            $data['size'] = $this->size;
        }

        if ($this->annotations !== null) {
            $data['annotations'] = $this->annotations;
        }

        return $data;
    }
}
