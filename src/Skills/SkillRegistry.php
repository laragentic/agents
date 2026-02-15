<?php

declare(strict_types=1);

namespace Laragentic\Skills;

use Laragentic\Exceptions\SkillNotFoundException;

/**
 * In-memory registry for loaded skills.
 *
 * Stores skills by name for fast retrieval and manages the active skill set
 * for an agent instance. Each agent gets its own registry instance.
 */
final class SkillRegistry
{
    /**
     * @var array<string, Skill>
     */
    private array $skills = [];

    /**
     * Register a skill in the registry.
     */
    public function register(Skill $skill): void
    {
        $this->skills[$skill->name()] = $skill;
    }

    /**
     * Check if a skill is registered.
     */
    public function has(string $name): bool
    {
        return isset($this->skills[$name]);
    }

    /**
     * Get a registered skill by name.
     *
     * @throws SkillNotFoundException
     */
    public function get(string $name): Skill
    {
        if (! $this->has($name)) {
            throw new SkillNotFoundException(
                "Skill '{$name}' is not registered"
            );
        }

        return $this->skills[$name];
    }

    /**
     * Get all registered skills.
     *
     * @return array<string, Skill>
     */
    public function all(): array
    {
        return $this->skills;
    }

    /**
     * Get all registered skill names.
     *
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->skills);
    }

    /**
     * Count registered skills.
     */
    public function count(): int
    {
        return count($this->skills);
    }

    /**
     * Clear all registered skills.
     */
    public function clear(): void
    {
        $this->skills = [];
    }

    /**
     * Find skills by tag.
     *
     * @return array<Skill>
     */
    public function findByTag(string $tag): array
    {
        return array_values(array_filter(
            $this->skills,
            fn (Skill $skill) => in_array($tag, $skill->tags(), true)
        ));
    }

    /**
     * Search skills by keyword in name or description.
     *
     * @return array<Skill>
     */
    public function search(string $keyword): array
    {
        $keyword = strtolower($keyword);

        return array_values(array_filter(
            $this->skills,
            fn (Skill $skill) => str_contains(strtolower($skill->name()), $keyword)
                || str_contains(strtolower($skill->description()), $keyword)
        ));
    }
}
