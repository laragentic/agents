<?php

declare(strict_types=1);

namespace Laragentic\Skills;

/**
 * Represents the frontmatter metadata from a SKILL.md file.
 *
 * Following the agentskills.io specification, this contains
 * the YAML-parsed metadata block at the top of each skill file.
 */
final readonly class SkillMetadata
{
    /**
     * @param  array<string>  $tags
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $tags = [],
        public ?string $version = null,
        public ?string $author = null,
        public array $raw = [],
    ) {}

    /**
     * Create metadata from parsed YAML array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: $data['name'] ?? '',
            description: $data['description'] ?? '',
            tags: $data['tags'] ?? [],
            version: $data['version'] ?? null,
            author: $data['author'] ?? null,
            raw: $data,
        );
    }

    /**
     * Convert metadata to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'tags' => $this->tags,
            'version' => $this->version,
            'author' => $this->author,
        ];
    }

    /**
     * Get a custom metadata value from the raw YAML.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->raw[$key] ?? $default;
    }
}
