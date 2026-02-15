<?php

declare(strict_types=1);

use Laragentic\Skills\Skill;
use Laragentic\Skills\SkillMetadata;

test('creates skill with metadata', function () {
    $metadata = new SkillMetadata(
        name: 'test-skill',
        description: 'A test skill',
        tags: ['test'],
    );

    $skill = new Skill(
        metadata: $metadata,
        instructions: 'Test instructions',
        path: '/path/to/skill',
    );

    expect($skill->name())->toBe('test-skill')
        ->and($skill->description())->toBe('A test skill')
        ->and($skill->tags())->toBe(['test'])
        ->and($skill->instructions)->toBe('Test instructions')
        ->and($skill->path)->toBe('/path/to/skill');
});

test('tracks resource directories', function () {
    $metadata = new SkillMetadata(
        name: 'test-skill',
        description: 'Test',
    );

    $skill = new Skill(
        metadata: $metadata,
        instructions: 'Instructions',
        path: '/path/to/skill',
        scriptsPath: '/path/to/skill/scripts',
        referencesPath: '/path/to/skill/references',
        assetsPath: '/path/to/skill/assets',
    );

    expect($skill->scriptsPath)->toBe('/path/to/skill/scripts')
        ->and($skill->referencesPath)->toBe('/path/to/skill/references')
        ->and($skill->assetsPath)->toBe('/path/to/skill/assets');
});

test('manages relevance score', function () {
    $metadata = new SkillMetadata(name: 'test', description: 'Test');
    $skill = new Skill(
        metadata: $metadata,
        instructions: 'Test',
        path: '/path',
    );

    expect($skill->relevanceScore())->toBe(0.0);

    $skill->setRelevanceScore(0.75);
    expect($skill->relevanceScore())->toBe(0.75);

    // Score is clamped to 0-1 range
    $skill->setRelevanceScore(1.5);
    expect($skill->relevanceScore())->toBe(1.0);

    $skill->setRelevanceScore(-0.5);
    expect($skill->relevanceScore())->toBe(0.0);
});

test('generates skill summary', function () {
    $metadata = new SkillMetadata(
        name: 'test-skill',
        description: 'A test skill',
        tags: ['test', 'example'],
    );

    $skill = new Skill(
        metadata: $metadata,
        instructions: 'Long instructions...',
        path: '/path',
    );

    $summary = $skill->summary();

    expect($summary)->toBe([
        'name' => 'test-skill',
        'description' => 'A test skill',
        'tags' => ['test', 'example'],
    ]);
});

test('converts skill to array', function () {
    $metadata = new SkillMetadata(
        name: 'test-skill',
        description: 'Test',
        tags: ['test'],
        version: '1.0.0',
        author: 'Author',
    );

    $skill = new Skill(
        metadata: $metadata,
        instructions: 'Instructions',
        path: '/path',
    );

    $array = $skill->toArray();

    expect($array)->toHaveKey('name', 'test-skill')
        ->and($array)->toHaveKey('description', 'Test')
        ->and($array)->toHaveKey('tags', ['test'])
        ->and($array)->toHaveKey('version', '1.0.0')
        ->and($array)->toHaveKey('author', 'Author')
        ->and($array)->toHaveKey('has_scripts', false)
        ->and($array)->toHaveKey('has_references', false)
        ->and($array)->toHaveKey('has_assets', false);
});
