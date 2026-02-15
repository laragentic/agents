<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Roots;

/**
 * An MCP root representing a filesystem boundary.
 */
class Root
{
    public function __construct(
        public readonly string $uri,
        public readonly ?string $name = null,
    ) {}

    /**
     * Create from a file path.
     */
    public static function fromPath(string $path, ?string $name = null): self
    {
        return new self(
            uri: 'file://' . realpath($path),
            name: $name,
        );
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = ['uri' => $this->uri];

        if ($this->name !== null) {
            $data['name'] = $this->name;
        }

        return $data;
    }
}
