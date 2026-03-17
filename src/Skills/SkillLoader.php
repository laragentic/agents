<?php

declare(strict_types=1);

namespace Laragentic\Skills;

use Laragentic\Contracts\LoadsSkills;
use Laragentic\Exceptions\SkillNotFoundException;
use Laragentic\Exceptions\SkillValidationException;

/**
 * Discovers and loads skills from the filesystem.
 *
 * Skills are stored in directories following this structure:
 *
 *     skills/
 *       code-review/
 *         SKILL.md          # Required: metadata + instructions
 *         scripts/          # Optional: executable scripts
 *         references/       # Optional: reference documents
 *         assets/           # Optional: images, etc.
 */
final class SkillLoader implements LoadsSkills
{
    /**
     * @param  string  $basePath  Base directory containing skill folders
     */
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Load a skill by name.
     *
     * @throws SkillNotFoundException
     * @throws SkillValidationException
     */
    public function load(string $name): Skill
    {
        $skillPath = $this->basePath . '/' . $name;

        if (! is_dir($skillPath)) {
            throw new SkillNotFoundException(
                "Skill directory not found: {$skillPath}"
            );
        }

        $skillFile = $skillPath . '/SKILL.md';

        if (! file_exists($skillFile)) {
            throw new SkillValidationException(
                "SKILL.md not found in skill directory: {$skillPath}"
            );
        }

        $markdown = file_get_contents($skillFile);
        $parsed = FrontmatterParser::parse($markdown);

        $metadata = SkillMetadata::fromArray($parsed['metadata']);

        // Validate required fields
        if (empty($metadata->name)) {
            throw new SkillValidationException(
                "Skill metadata must include 'name' field in {$skillFile}"
            );
        }

        if (empty($metadata->description)) {
            throw new SkillValidationException(
                "Skill metadata must include 'description' field in {$skillFile}"
            );
        }

        // Discover optional subdirectories
        $scriptsPath = is_dir($skillPath . '/scripts') ? $skillPath . '/scripts' : null;
        $referencesPath = is_dir($skillPath . '/references') ? $skillPath . '/references' : null;
        $assetsPath = is_dir($skillPath . '/assets') ? $skillPath . '/assets' : null;

        return new Skill(
            metadata: $metadata,
            instructions: $parsed['content'],
            path: $skillPath,
            scriptsPath: $scriptsPath,
            referencesPath: $referencesPath,
            assetsPath: $assetsPath,
        );
    }

    /**
     * Load only the metadata summary for a skill (progressive disclosure).
     *
     * This is faster than loading the full skill since it doesn't parse
     * the entire instructions content.
     *
     * @throws SkillNotFoundException
     * @throws SkillValidationException
     */
    public function loadSummary(string $name): array
    {
        $skillPath = $this->basePath . '/' . $name;
        $skillFile = $skillPath . '/SKILL.md';

        if (! file_exists($skillFile)) {
            throw new SkillNotFoundException(
                "Skill not found: {$name}"
            );
        }

        $markdown = file_get_contents($skillFile);
        $parsed = FrontmatterParser::parse($markdown);
        $metadata = SkillMetadata::fromArray($parsed['metadata']);

        return [
            'name' => $metadata->name,
            'description' => $metadata->description,
            'tags' => $metadata->tags,
        ];
    }

    /**
     * Discover all available skill names in the base directory.
     *
     * @return array<string>
     */
    public function discover(): array
    {
        if (! is_dir($this->basePath)) {
            return [];
        }

        $skills = [];
        $directories = scandir($this->basePath);

        foreach ($directories as $dir) {
            if ($dir === '.' || $dir === '..') {
                continue;
            }

            $skillPath = $this->basePath . '/' . $dir;

            // Check if it's a directory and has SKILL.md
            if (is_dir($skillPath) && file_exists($skillPath . '/SKILL.md')) {
                $skills[] = $dir;
            }
        }

        return $skills;
    }

    /**
     * Load summaries for all discovered skills.
     *
     * @return array<array{name: string, description: string, tags: array<string>}>
     */
    public function discoverSummaries(): array
    {
        $skillNames = $this->discover();
        $summaries = [];

        foreach ($skillNames as $name) {
            try {
                $summaries[] = $this->loadSummary($name);
            } catch (SkillNotFoundException|SkillValidationException) {
                // Skip invalid skills
                continue;
            }
        }

        return $summaries;
    }
}
