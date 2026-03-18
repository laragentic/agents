<?php

declare(strict_types=1);

use Laragentic\Tests\Fixtures\TestSkillAgent;

beforeEach(function () {
    config()->set('agentic.skills.path', __DIR__ . '/../Fixtures/test-skills');
});

test('agent can load a skill manually', function () {
    $agent = new TestSkillAgent;
    $agent->withSkill('code-review');

    $loadedSkills = $agent->loadedSkills();

    expect($loadedSkills)->toHaveCount(1)
        ->and($loadedSkills[0]->name())->toBe('code-review');
});

test('agent can load multiple skills', function () {
    $agent = new TestSkillAgent;
    $agent->withSkills(['code-review', 'data-analysis']);

    $loadedSkills = $agent->loadedSkills();

    expect($loadedSkills)->toHaveCount(2)
        ->and($loadedSkills[0]->name())->toBeIn(['code-review', 'data-analysis'])
        ->and($loadedSkills[1]->name())->toBeIn(['code-review', 'data-analysis']);
});

test('agent instructions include skill content when loaded', function () {
    $agent = new TestSkillAgent;
    $agent->withSkill('code-review');

    $instructions = $agent->instructions();

    expect($instructions)->toContain('Code Review Skill')
        ->and($instructions)->toContain('Security Analysis')
        ->and($instructions)->toContain('Active Skills');
});

test('agent instructions include skill index when no skills loaded', function () {
    $agent = new TestSkillAgent;

    $instructions = $agent->instructions();

    expect($instructions)->toContain('Available Skills')
        ->and($instructions)->toContain('code-review')
        ->and($instructions)->toContain('data-analysis')
        ->and($instructions)->toContain('api-testing');
});

test('skill loaded callback is fired', function () {
    $callbackFired = false;
    $loadedSkill = null;

    $agent = new TestSkillAgent;
    $agent->onSkillLoaded(function ($skill) use (&$callbackFired, &$loadedSkill) {
        $callbackFired = true;
        $loadedSkill = $skill;
    });

    $agent->withSkill('code-review');

    expect($callbackFired)->toBeTrue()
        ->and($loadedSkill)->not->toBeNull()
        ->and($loadedSkill->name())->toBe('code-review');
});

test('auto-resolve loads relevant skills', function () {
    $agent = new TestSkillAgent;
    $agent->autoResolveSkills(threshold: 0.3, limit: 2);

    // Trigger resolution by getting instructions with a query
    $instructions = $agent->instructions('Review this code for security issues');

    $loadedSkills = $agent->loadedSkills();

    expect($loadedSkills)->not->toBeEmpty();

    // Should have loaded code-review skill based on the query
    $skillNames = array_map(fn ($skill) => $skill->name(), $loadedSkills);
    expect($skillNames)->toContain('code-review');
});

test('skill resolved callback is fired on auto-resolution', function () {
    $callbackFired = false;
    $resolvedSkills = [];

    $agent = new TestSkillAgent;
    $agent->autoResolveSkills(threshold: 0.3);
    $agent->onSkillResolved(function ($skills, $query) use (&$callbackFired, &$resolvedSkills) {
        $callbackFired = true;
        $resolvedSkills = $skills;
    });

    $agent->instructions('Help with data analysis');

    expect($callbackFired)->toBeTrue()
        ->and($resolvedSkills)->not->toBeEmpty();
});

test('can access skill registry', function () {
    $agent = new TestSkillAgent;
    $agent->withSkill('code-review');

    $registry = $agent->skillRegistry();

    expect($registry->count())->toBe(1)
        ->and($registry->has('code-review'))->toBeTrue();
});

test('skills with resources are properly loaded', function () {
    $agent = new TestSkillAgent;
    $agent->withSkill('code-review');

    $loadedSkills = $agent->loadedSkills();
    $skill = $loadedSkills[0];

    expect($skill->hasScripts())->toBeTrue()
        ->and($skill->hasReferences())->toBeTrue()
        ->and($skill->scriptsPath)->toContain('scripts')
        ->and($skill->referencesPath)->toContain('references');
});

test('fluent api allows method chaining', function () {
    $agent = (new TestSkillAgent)
        ->withSkill('code-review')
        ->onSkillLoaded(function () {})
        ->autoResolveSkills();

    expect($agent)->toBeInstanceOf(TestSkillAgent::class)
        ->and($agent->loadedSkills())->toHaveCount(1);
});

test('allowedToolIds returns empty when no skills loaded', function () {
    $agent = new TestSkillAgent;

    expect($agent->allowedToolIds())->toBe([]);
});

test('allowedToolIds returns empty when loaded skills have no tool restrictions', function () {
    $agent = new TestSkillAgent;
    $agent->withSkill('code-review');

    expect($agent->allowedToolIds())->toBe([]);
});

test('allowedToolIds returns union of tool IDs from restricted skills', function () {
    $agent = new TestSkillAgent;

    $registry = $agent->skillRegistry();
    $registry->register(new \Laragentic\Skills\Skill(
        metadata: new \Laragentic\Skills\SkillMetadata(name: 'skill-a', description: 'A'),
        instructions: 'Use sdk_llm',
        path: '',
        toolIds: ['sdk_llm', 'sdk_ocr'],
    ));
    $registry->register(new \Laragentic\Skills\Skill(
        metadata: new \Laragentic\Skills\SkillMetadata(name: 'skill-b', description: 'B'),
        instructions: 'Use sdk_ocr and docs',
        path: '',
        toolIds: ['sdk_ocr', 'sdk_docs_create'],
    ));

    $allowed = $agent->allowedToolIds();

    expect($allowed)->toContain('sdk_llm')
        ->and($allowed)->toContain('sdk_ocr')
        ->and($allowed)->toContain('sdk_docs_create')
        ->and($allowed)->toHaveCount(3);
});

test('skill instructions include preferred tools when tool_ids set', function () {
    $agent = new TestSkillAgent;

    $registry = $agent->skillRegistry();
    $registry->register(new \Laragentic\Skills\Skill(
        metadata: new \Laragentic\Skills\SkillMetadata(name: 'tool-skill', description: 'T'),
        instructions: 'Do stuff',
        path: '',
        toolIds: ['sdk_llm', 'sdk_ocr'],
    ));

    $instructions = $agent->instructions();

    expect($instructions)->toContain('Preferred tools for this skill')
        ->and($instructions)->toContain('`sdk_llm`')
        ->and($instructions)->toContain('`sdk_ocr`');
});
