<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Resources;

/**
 * An MCP resource template (RFC 6570 URI template).
 */
class ResourceTemplate
{
    public function __construct(
        public readonly string $uriTemplate,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $mimeType = null,
        public readonly ?array $annotations = null,
    ) {}

    /**
     * Parse from the resource templates list response item.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uriTemplate: $data['uriTemplate'],
            name: $data['name'],
            description: $data['description'] ?? null,
            mimeType: $data['mimeType'] ?? null,
            annotations: $data['annotations'] ?? null,
        );
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = [
            'uriTemplate' => $this->uriTemplate,
            'name' => $this->name,
        ];

        if ($this->description !== null) {
            $data['description'] = $this->description;
        }

        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }

        if ($this->annotations !== null) {
            $data['annotations'] = $this->annotations;
        }

        return $data;
    }
}
