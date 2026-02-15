<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Resources;

/**
 * Content read from an MCP resource.
 *
 * Can contain text or binary (base64-encoded) data.
 */
class ResourceContents
{
    public function __construct(
        public readonly string $uri,
        public readonly ?string $mimeType = null,
        public readonly ?string $text = null,
        public readonly ?string $blob = null,
    ) {}

    /**
     * Parse from the resources/read response content item.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            uri: $data['uri'],
            mimeType: $data['mimeType'] ?? null,
            text: $data['text'] ?? null,
            blob: $data['blob'] ?? null,
        );
    }

    /**
     * Whether this content is text-based.
     */
    public function isText(): bool
    {
        return $this->text !== null;
    }

    /**
     * Whether this content is binary (base64).
     */
    public function isBinary(): bool
    {
        return $this->blob !== null;
    }

    /**
     * Get the content as a string.
     */
    public function content(): string
    {
        if ($this->text !== null) {
            return $this->text;
        }

        if ($this->blob !== null) {
            return base64_decode($this->blob);
        }

        return '';
    }

    /**
     * Convert to string.
     */
    public function __toString(): string
    {
        return $this->content();
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = ['uri' => $this->uri];

        if ($this->mimeType !== null) {
            $data['mimeType'] = $this->mimeType;
        }

        if ($this->text !== null) {
            $data['text'] = $this->text;
        }

        if ($this->blob !== null) {
            $data['blob'] = $this->blob;
        }

        return $data;
    }
}
