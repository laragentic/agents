<?php

declare(strict_types=1);

use Laragentic\Exceptions\SkillNotFoundException;
use Laragentic\Skills\Skill;
use Laragentic\Skills\SkillMetadata;
use Laragentic\Skills\SkillRegistry;

beforeEach(function () {
    $this->registry = new SkillRegistry;
});

test('registers and retrieves skills', function () {
    $metadata = new SkillMetadata(
        name: 'test-skill',
        description: 'Test',
    );

    $skill = new Skill(
        metadata: $metadata,
        instructions: 'Instructions',
        path: '/path',
    );

    $this->registry->register($skill);

    expect($this->registry->has('test-skill'))->toBeTrue()
        ->and($this->registry->get('test-skill'))->toBe($skill);
});

test('throws exception for unregistered skill', function () {
    expect(fn () => $this->registry->get('non-existent'))
        ->toThrow(SkillNotFoundException::class, 'is not registered');
});

test('returns all registered skills', function () {
    $skill1 = new Skill(
        metadata: new SkillMetadata(name: 'skill-1', description: 'First'),
        instructions: 'Instructions',
        path: '/path',
    );

    $skill2 = new Skill(
        metadata: new SkillMetadata(name: 'skill-2', description: 'Second'),
        instructions: 'Instructions',
        path: '/path',
    );

    $this->registry->register($skill1);
    $this->registry->register($skill2);

    $all = $this->registry->all();

    expect($all)->toHaveCount(2)
        ->and($all)->toHaveKey('skill-1')
        ->and($all)->toHaveKey('skill-2');
});

test('returns skill names', function () {
    $skill1 = new Skill(
        metadata: new SkillMetadata(name: 'skill-1', description: 'First'),
        instructions: 'Instructions',
        path: '/path',
    );

    $skill2 = new Skill(
        metadata: new SkillMetadata(name: 'skill-2', description: 'Second'),
        instructions: 'Instructions',
        path: '/path',
    );

    $this->registry->register($skill1);
    $this->registry->register($skill2);

    $names = $this->registry->names();

    expect($names)->toContain('skill-1')
        ->and($names)->toContain('skill-2');
});

test('counts registered skills', function () {
    expect($this->registry->count())->toBe(0);

    $skill = new Skill(
        metadata: new SkillMetadata(name: 'test', description: 'Test'),
        instructions: 'Instructions',
        path: '/path',
    );

    $this->registry->register($skill);

    expect($this->registry->count())->toBe(1);
});

test('clears all skills', function () {
    $skill = new Skill(
        metadata: new SkillMetadata(name: 'test', description: 'Test'),
        instructions: 'Instructions',
        path: '/path',
    );

    $this->registry->register($skill);
    expect($this->registry->count())->toBe(1);

    $this->registry->clear();
    expect($this->registry->count())->toBe(0);
});

test('finds skills by tag', function () {
    $skill1 = new Skill(
        metadata: new SkillMetadata(name: 'skill-1', description: 'First', tags: ['code', 'review']),
        instructions: 'Instructions',
        path: '/path',
    );

    $skill2 = new Skill(
        metadata: new SkillMetadata(name: 'skill-2', description: 'Second', tags: ['data', 'analysis']),
        instructions: 'Instructions',
        path: '/path',
    );

    $skill3 = new Skill(
        metadata: new SkillMetadata(name: 'skill-3', description: 'Third', tags: ['code', 'testing']),
        instructions: 'Instructions',
        path: '/path',
    );

    $this->registry->register($skill1);
    $this->registry->register($skill2);
    $this->registry->register($skill3);

    $codeSkills = $this->registry->findByTag('code');

    expect($codeSkills)->toHaveCount(2)
        ->and($codeSkills[0]->name())->toBeIn(['skill-1', 'skill-3'])
        ->and($codeSkills[1]->name())->toBeIn(['skill-1', 'skill-3']);
});

test('searches skills by keyword', function () {
    $skill1 = new Skill(
        metadata: new SkillMetadata(name: 'code-review', description: 'Review code for issues'),
        instructions: 'Instructions',
        path: '/path',
    );

    $skill2 = new Skill(
        metadata: new SkillMetadata(name: 'data-analysis', description: 'Analyze datasets'),
        instructions: 'Instructions',
        path: '/path',
    );

    $this->registry->register($skill1);
    $this->registry->register($skill2);

    $results = $this->registry->search('code');

    expect($results)->toHaveCount(1)
        ->and($results[0]->name())->toBe('code-review');

    $results = $this->registry->search('analysis');

    expect($results)->toHaveCount(1)
        ->and($results[0]->name())->toBe('data-analysis');
});
