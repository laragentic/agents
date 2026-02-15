<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Tools;

/**
 * Result from calling an MCP tool.
 */
class ToolResult
{
    public function __construct(
        public readonly array $content,
        public readonly bool $isError = false,
        public readonly mixed $structuredContent = null,
    ) {}

    /**
     * Parse from the tools/call response.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            content: $data['content'] ?? [],
            isError: $data['isError'] ?? false,
            structuredContent: $data['structuredContent'] ?? null,
        );
    }

    /**
     * Get the text content of the result.
     */
    public function text(): string
    {
        $parts = [];

        foreach ($this->content as $item) {
            if (($item['type'] ?? '') === 'text') {
                $parts[] = $item['text'] ?? '';
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Check if the result contains an error.
     */
    public function hasError(): bool
    {
        return $this->isError;
    }

    /**
     * Get all content items.
     */
    public function contentItems(): array
    {
        return $this->content;
    }

    /**
     * Convert to string (primarily text content).
     */
    public function __toString(): string
    {
        if ($this->isError) {
            return 'Error: ' . $this->text();
        }

        return $this->text();
    }
}
