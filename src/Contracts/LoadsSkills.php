<?php

declare(strict_types=1);

namespace Laragentic\Contracts;

use Laragentic\Skills\Skill;

/**
 * Contract for loading agent skills from any backing store.
 *
 * The default implementation (SkillLoader) reads from the filesystem.
 * Consumers can implement this to load skills from a database, API, etc.
 */
interface LoadsSkills
{
    /**
     * Load a skill by name.
     *
     * @throws \Laragentic\Exceptions\SkillNotFoundException
     */
    public function load(string $name): Skill;

    /**
     * Load only the metadata summary for a skill (progressive disclosure).
     *
     * @return array{name: string, description: string, tags: array<string>}
     *
     * @throws \Laragentic\Exceptions\SkillNotFoundException
     */
    public function loadSummary(string $name): array;

    /**
     * Discover all available skill names.
     *
     * @return array<string>
     */
    public function discover(): array;

    /**
     * Load summaries for all discovered skills.
     *
     * @return array<array{name: string, description: string, tags: array<string>}>
     */
    public function discoverSummaries(): array;
}
