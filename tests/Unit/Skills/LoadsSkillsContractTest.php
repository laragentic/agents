<?php

declare(strict_types=1);

use Laragentic\Contracts\LoadsSkills;
use Laragentic\Exceptions\SkillNotFoundException;
use Laragentic\Skills\HasAgentSkills;
use Laragentic\Skills\Skill;
use Laragentic\Skills\SkillMetadata;

/**
 * In-memory LoadsSkills implementation for testing.
 */
class InMemorySkillLoader implements LoadsSkills
{
    /** @var array<string, Skill> */
    private array $skills = [];

    public function addSkill(Skill $skill): void
    {
        $this->skills[$skill->name()] = $skill;
    }

    public function load(string $name): Skill
    {
        if (! isset($this->skills[$name])) {
            throw new SkillNotFoundException("Skill not found: {$name}");
        }

        return $this->skills[$name];
    }

    public function loadSummary(string $name): array
    {
        $skill = $this->load($name);

        return [
            'name' => $skill->name(),
            'description' => $skill->description(),
            'tags' => $skill->tags(),
        ];
    }

    public function discover(): array
    {
        return array_keys($this->skills);
    }

    public function discoverSummaries(): array
    {
        return array_map(fn (Skill $skill) => [
            'name' => $skill->name(),
            'description' => $skill->description(),
            'tags' => $skill->tags(),
        ], array_values($this->skills));
    }
}

/**
 * Test agent that uses a custom LoadsSkills implementation.
 */
class CustomLoaderAgent
{
    use HasAgentSkills;

    public function __construct(private readonly LoadsSkills $customLoader) {}

    public function instructions(?string $query = null): string
    {
        return $this->enhanceInstructionsWithSkills(
            'You are a test agent.',
            $query ?? ''
        );
    }

    protected function ensureSkillInfrastructure(): void
    {
        if ($this->skillRegistry === null) {
            $this->skillRegistry = new \Laragentic\Skills\SkillRegistry;
        }

        $this->skillLoader = $this->customLoader;

        if ($this->skillResolver === null) {
            $this->skillResolver = new \Laragentic\Skills\SkillResolver;
        }
    }
}

// ─── Tests ───────────────────────────────────────────────────────

test('HasAgentSkills accepts a custom LoadsSkills implementation', function () {
    $loader = new InMemorySkillLoader;
    $loader->addSkill(new Skill(
        metadata: new SkillMetadata(
            name: 'test-skill',
            description: 'A test skill',
            tags: ['testing'],
        ),
        instructions: 'Do something helpful.',
        path: '',
    ));

    $agent = new CustomLoaderAgent($loader);
    $agent->withSkill('test-skill');

    expect($agent->loadedSkills())->toHaveCount(1)
        ->and($agent->loadedSkills()[0]->name())->toBe('test-skill');
});

test('custom loader skills appear in agent instructions', function () {
    $loader = new InMemorySkillLoader;
    $loader->addSkill(new Skill(
        metadata: new SkillMetadata(
            name: 'db-skill',
            description: 'A database-loaded skill',
            tags: ['database'],
        ),
        instructions: 'Follow these database-specific instructions.',
        path: '',
    ));

    $agent = new CustomLoaderAgent($loader);
    $agent->withSkill('db-skill');

    $instructions = $agent->instructions();

    expect($instructions)->toContain('Active Skills')
        ->and($instructions)->toContain('db-skill')
        ->and($instructions)->toContain('Follow these database-specific instructions.');
});

test('custom loader skill index appears when no skills loaded', function () {
    $loader = new InMemorySkillLoader;
    $loader->addSkill(new Skill(
        metadata: new SkillMetadata(
            name: 'available-skill',
            description: 'This skill is available but not loaded',
            tags: ['discovery'],
        ),
        instructions: 'Instructions here.',
        path: '',
    ));

    $agent = new CustomLoaderAgent($loader);
    $instructions = $agent->instructions();

    expect($instructions)->toContain('Available Skills')
        ->and($instructions)->toContain('available-skill')
        ->and($instructions)->toContain('This skill is available but not loaded');
});

test('custom loader with empty skills returns base instructions only', function () {
    $loader = new InMemorySkillLoader;
    $agent = new CustomLoaderAgent($loader);

    $instructions = $agent->instructions();

    expect($instructions)->toBe('You are a test agent.');
});

test('SkillLoader implements LoadsSkills', function () {
    $loader = new \Laragentic\Skills\SkillLoader(sys_get_temp_dir());

    expect($loader)->toBeInstanceOf(LoadsSkills::class);
});
