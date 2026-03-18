<?php

declare(strict_types=1);

namespace Laragentic\Skills;

use Closure;
use Laragentic\Concerns\HasCallbacks;
use Laragentic\Contracts\LoadsSkills;

/**
 * Adds Agent Skills support to any Laravel AI SDK agent.
 *
 * This trait implements the agentskills.io specification with progressive
 * disclosure: skills are loaded on-demand, and only their metadata is
 * included in prompts until they're activated.
 *
 * Usage:
 *
 *     class MyAgent implements Agent, Conversational, HasTools
 *     {
 *         use Promptable, RemembersConversations, ReActLoop, HasAgentSkills;
 *
 *         public function instructions(): string
 *         {
 *             return 'You are a helpful assistant...';
 *         }
 *     }
 *
 * Manual skill loading:
 *
 *     $agent->withSkill('code-review')->reactLoop('Review this code...');
 *
 * Auto-resolution:
 *
 *     $agent->autoResolveSkills()->reactLoop('Help me with data analysis');
 */
trait HasAgentSkills
{
    use HasCallbacks;

    protected ?SkillRegistry $skillRegistry = null;

    protected ?LoadsSkills $skillLoader = null;

    protected ?SkillResolver $skillResolver = null;

    protected bool $autoResolve = false;

    protected float $resolutionThreshold = 0.3;

    protected int $resolutionLimit = 3;

    /**
     * Whether skills have been resolved for the current query.
     */
    protected bool $skillsResolved = false;

    // ─── Public API ─────────────────────────────────────────────────

    /**
     * Load a specific skill by name.
     */
    public function withSkill(string $name): static
    {
        $this->ensureSkillInfrastructure();

        try {
            $skill = $this->skillLoader->load($name);
            $this->skillRegistry->register($skill);

            $this->fireCallbacks('skillLoaded', $skill);
        } catch (\Exception $e) {
            // Silently skip missing skills in production
            // Developers can use callbacks to handle errors
        }

        return $this;
    }

    /**
     * Load multiple skills by name.
     *
     * @param  array<string>  $names
     */
    public function withSkills(array $names): static
    {
        foreach ($names as $name) {
            $this->withSkill($name);
        }

        return $this;
    }

    /**
     * Enable automatic skill resolution based on query relevance.
     */
    public function autoResolveSkills(
        ?float $threshold = null,
        ?int $limit = null,
    ): static {
        $this->autoResolve = true;

        if ($threshold !== null) {
            $this->resolutionThreshold = $threshold;
        }

        if ($limit !== null) {
            $this->resolutionLimit = $limit;
        }

        return $this;
    }

    /**
     * Get all currently loaded skills.
     *
     * @return array<Skill>
     */
    public function loadedSkills(): array
    {
        $this->ensureSkillInfrastructure();

        return array_values($this->skillRegistry->all());
    }

    /**
     * Collect the union of tool IDs from all loaded skills.
     *
     * Returns an empty array when no skills are loaded or when
     * none of the loaded skills specify tool restrictions.
     *
     * @return array<string>
     */
    public function allowedToolIds(): array
    {
        $skills = $this->loadedSkills();

        if (empty($skills)) {
            return [];
        }

        $hasRestrictions = false;
        $ids = [];

        foreach ($skills as $skill) {
            if ($skill->hasToolRestrictions()) {
                $hasRestrictions = true;
                $ids = array_merge($ids, $skill->toolIds);
            }
        }

        return $hasRestrictions ? array_values(array_unique($ids)) : [];
    }

    /**
     * Get the skill registry instance.
     */
    public function skillRegistry(): SkillRegistry
    {
        $this->ensureSkillInfrastructure();

        return $this->skillRegistry;
    }

    // ─── Callbacks ──────────────────────────────────────────────────

    /**
     * Register a callback invoked when a skill is loaded.
     *
     * Receives: (Skill $skill)
     */
    public function onSkillLoaded(Closure $callback): static
    {
        $this->loopCallbacks['skillLoaded'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when skills are auto-resolved.
     *
     * Receives: (array<Skill> $skills, string $query)
     */
    public function onSkillResolved(Closure $callback): static
    {
        $this->loopCallbacks['skillResolved'][] = $callback;

        return $this;
    }

    // ─── Prompt Enhancement ─────────────────────────────────────────

    /**
     * Enhance the agent's base instructions with skill content.
     *
     * This method is called internally to inject skill instructions
     * into the prompt before sending to the LLM.
     */
    protected function enhanceInstructionsWithSkills(string $baseInstructions, string $query = ''): string
    {
        $this->ensureSkillInfrastructure();

        // Auto-resolve skills if enabled and not already resolved
        if ($this->autoResolve && ! $this->skillsResolved) {
            $this->resolveSkillsForQuery($query);
            $this->skillsResolved = true;
        }

        $loadedSkills = $this->loadedSkills();

        // If no skills loaded, show skill index for discovery
        if (empty($loadedSkills)) {
            return $this->buildPromptWithSkillIndex($baseInstructions);
        }

        // Build prompt with loaded skill instructions
        return $this->buildPromptWithLoadedSkills($baseInstructions, $loadedSkills);
    }

    /**
     * Build prompt with skill index (progressive disclosure).
     */
    protected function buildPromptWithSkillIndex(string $baseInstructions): string
    {
        $summaries = $this->skillLoader->discoverSummaries();

        if (empty($summaries)) {
            return $baseInstructions;
        }

        $index = "\n\n# Available Skills\n\n";
        $index .= "The following skills are available. To use a skill, mention it by name in your response.\n\n";

        foreach ($summaries as $summary) {
            $index .= "**{$summary['name']}**: {$summary['description']}\n";
        }

        return $baseInstructions . $index;
    }

    /**
     * Build prompt with loaded skill instructions.
     *
     * @param  array<Skill>  $skills
     */
    protected function buildPromptWithLoadedSkills(string $baseInstructions, array $skills): string
    {
        $prompt = $baseInstructions . "\n\n# Active Skills\n\n";
        $prompt .= "The following skills are loaded and ready to use. Follow the skill instructions precisely.\n\n";

        foreach ($skills as $skill) {
            $prompt .= "## Skill: {$skill->name()}\n\n";
            $prompt .= $skill->instructions . "\n\n";

            if ($skill->hasToolRestrictions()) {
                $toolList = implode(', ', array_map(fn ($id) => "`{$id}`", $skill->toolIds));
                $prompt .= "**Preferred tools for this skill**: {$toolList}\n";
            }

            if ($skill->hasScripts()) {
                $prompt .= "**Scripts available**: {$skill->scriptsPath}\n";
            }

            if ($skill->hasReferences()) {
                $prompt .= "**References available**: {$skill->referencesPath}\n";
            }

            if ($skill->hasAssets()) {
                $prompt .= "**Assets available**: {$skill->assetsPath}\n";
            }

            $prompt .= "\n---\n\n";
        }

        return $prompt;
    }

    /**
     * Resolve skills for a query using the resolver.
     */
    protected function resolveSkillsForQuery(string $query): void
    {
        $summaries = $this->skillLoader->discoverSummaries();

        if (empty($summaries)) {
            return;
        }

        // Build summary map keyed by name
        $summaryMap = [];
        foreach ($summaries as $summary) {
            $summaryMap[$summary['name']] = $summary;
        }

        $resolvedNames = $this->skillResolver->resolve(
            availableSkills: $summaryMap,
            query: $query,
            threshold: $this->resolutionThreshold,
            limit: $this->resolutionLimit,
        );

        // Load resolved skills
        $resolvedSkills = [];
        foreach ($resolvedNames as $name) {
            try {
                $skill = $this->skillLoader->load($name);
                $this->skillRegistry->register($skill);
                $resolvedSkills[] = $skill;

                $this->fireCallbacks('skillLoaded', $skill);
            } catch (\Exception) {
                // Skip failed loads
            }
        }

        if (! empty($resolvedSkills)) {
            $this->fireCallbacks('skillResolved', $resolvedSkills, $query);
        }
    }

    /**
     * Initialize skill infrastructure (loader, registry, resolver).
     */
    protected function ensureSkillInfrastructure(): void
    {
        if ($this->skillRegistry === null) {
            $this->skillRegistry = new SkillRegistry;
        }

        if ($this->skillLoader === null) {
            $skillsPath = config('agentic.skills.path', app_path('Skills'));
            $this->skillLoader = new SkillLoader($skillsPath);
        }

        if ($this->skillResolver === null) {
            $this->skillResolver = new SkillResolver;
        }
    }

    /**
     * Override prompt method to inject skills into instructions.
     *
     * This intercepts the base prompt() call from Laravel AI SDK
     * to enhance instructions with skill content.
     */
    protected function getEnhancedInstructions(string $query = ''): string
    {
        $baseInstructions = method_exists($this, 'instructions')
            ? $this->instructions()
            : '';

        return $this->enhanceInstructionsWithSkills($baseInstructions, $query);
    }
}
