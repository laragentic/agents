<?php

declare(strict_types=1);

namespace Laragentic\Skills;

use Laravel\Ai\Responses\AgentResponse;

/**
 * Wraps an AgentResponse with information about which skills were loaded.
 *
 * Allows tracking which skills influenced the agent's response,
 * useful for logging, debugging, and understanding agent behavior.
 */
final readonly class SkillResult
{
    /**
     * @param  array<Skill>  $loadedSkills
     */
    public function __construct(
        public AgentResponse $response,
        public array $loadedSkills = [],
    ) {}

    /**
     * Get the text response from the agent.
     */
    public function text(): string
    {
        return $this->response->text;
    }

    /**
     * Check if any skills were loaded for this response.
     */
    public function hasSkills(): bool
    {
        return ! empty($this->loadedSkills);
    }

    /**
     * Get the names of loaded skills.
     *
     * @return array<string>
     */
    public function skillNames(): array
    {
        return array_map(fn (Skill $skill) => $skill->name(), $this->loadedSkills);
    }

    /**
     * Get metadata about loaded skills.
     *
     * @return array<array<string, mixed>>
     */
    public function skillSummaries(): array
    {
        return array_map(fn (Skill $skill) => $skill->summary(), $this->loadedSkills);
    }
}
