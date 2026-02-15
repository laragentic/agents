<?php

declare(strict_types=1);

namespace Laragentic\Skills;

/**
 * Represents a complete Agent Skill following the agentskills.io specification.
 *
 * A skill encapsulates:
 * - Metadata (name, description, tags)
 * - Instructions (prompt content from SKILL.md)
 * - Optional resources (scripts, references, assets)
 * - Relevance scoring for auto-resolution
 */
final class Skill
{
    /**
     * @param  ?string  $scriptsPath  Absolute path to scripts/ subdirectory if it exists
     * @param  ?string  $referencesPath  Absolute path to references/ subdirectory if it exists
     * @param  ?string  $assetsPath  Absolute path to assets/ subdirectory if it exists
     */
    public function __construct(
        public readonly SkillMetadata $metadata,
        public readonly string $instructions,
        public readonly string $path,
        public readonly ?string $scriptsPath = null,
        public readonly ?string $referencesPath = null,
        public readonly ?string $assetsPath = null,
        private float $relevanceScore = 0.0,
    ) {}

    /**
     * Get the skill's unique name.
     */
    public function name(): string
    {
        return $this->metadata->name;
    }

    /**
     * Get the skill's description.
     */
    public function description(): string
    {
        return $this->metadata->description;
    }

    /**
     * Get the skill's tags.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return $this->metadata->tags;
    }

    /**
     * Set the relevance score for this skill.
     *
     * Used during auto-resolution to rank skills by relevance to the query.
     */
    public function setRelevanceScore(float $score): void
    {
        $this->relevanceScore = max(0.0, min(1.0, $score));
    }

    /**
     * Get the relevance score for this skill.
     */
    public function relevanceScore(): float
    {
        return $this->relevanceScore;
    }

    /**
     * Check if the skill has scripts available.
     */
    public function hasScripts(): bool
    {
        return $this->scriptsPath !== null && is_dir($this->scriptsPath);
    }

    /**
     * Check if the skill has references available.
     */
    public function hasReferences(): bool
    {
        return $this->referencesPath !== null && is_dir($this->referencesPath);
    }

    /**
     * Check if the skill has assets available.
     */
    public function hasAssets(): bool
    {
        return $this->assetsPath !== null && is_dir($this->assetsPath);
    }

    /**
     * Get a summary of the skill (metadata only, no instructions).
     *
     * Used for progressive disclosure - show skill index without full content.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'tags' => $this->tags(),
        ];
    }

    /**
     * Convert skill to array representation.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name(),
            'description' => $this->description(),
            'tags' => $this->tags(),
            'version' => $this->metadata->version,
            'author' => $this->metadata->author,
            'has_scripts' => $this->hasScripts(),
            'has_references' => $this->hasReferences(),
            'has_assets' => $this->hasAssets(),
        ];
    }
}
