<?php

declare(strict_types=1);

use Laragentic\Exceptions\SkillNotFoundException;
use Laragentic\Exceptions\SkillValidationException;
use Laragentic\Skills\SkillLoader;

beforeEach(function () {
    $this->skillsPath = __DIR__ . '/../../Fixtures/test-skills';
    $this->loader = new SkillLoader($this->skillsPath);
});

test('loads a valid skill', function () {
    $skill = $this->loader->load('code-review');

    expect($skill->name())->toBe('code-review')
        ->and($skill->description())->toContain('Analyze code')
        ->and($skill->tags())->toContain('code')
        ->and($skill->tags())->toContain('security')
        ->and($skill->instructions)->toContain('Code Review Skill')
        ->and($skill->metadata->version)->toBe('1.0.0')
        ->and($skill->metadata->author)->toBe('Test Author');
});

test('discovers resource directories', function () {
    $skill = $this->loader->load('code-review');

    expect($skill->hasScripts())->toBeTrue()
        ->and($skill->hasReferences())->toBeTrue()
        ->and($skill->scriptsPath)->toContain('scripts')
        ->and($skill->referencesPath)->toContain('references');
});

test('throws exception for missing skill directory', function () {
    expect(fn () => $this->loader->load('non-existent-skill'))
        ->toThrow(SkillNotFoundException::class, 'Skill directory not found');
});

test('throws exception for missing SKILL.md', function () {
    $tempDir = sys_get_temp_dir() . '/test-skill-' . uniqid();
    mkdir($tempDir);

    $loader = new SkillLoader(sys_get_temp_dir());

    expect(fn () => $loader->load(basename($tempDir)))
        ->toThrow(SkillValidationException::class, 'SKILL.md not found');

    rmdir($tempDir);
});

test('throws exception for missing name field', function () {
    $tempDir = sys_get_temp_dir() . '/test-skill-' . uniqid();
    mkdir($tempDir);
    file_put_contents($tempDir . '/SKILL.md', <<<'MD'
---
description: Missing name field
---

Content
MD);

    $loader = new SkillLoader(sys_get_temp_dir());

    expect(fn () => $loader->load(basename($tempDir)))
        ->toThrow(SkillValidationException::class, "must include 'name' field");

    unlink($tempDir . '/SKILL.md');
    rmdir($tempDir);
});

test('discovers all available skills', function () {
    $skills = $this->loader->discover();

    expect($skills)->toBeArray()
        ->and($skills)->toContain('code-review');
});

test('loads skill summaries', function () {
    $summary = $this->loader->loadSummary('code-review');

    expect($summary)->toHaveKey('name', 'code-review')
        ->and($summary)->toHaveKey('description')
        ->and($summary)->toHaveKey('tags')
        ->and($summary['tags'])->toBeArray();
});

test('discovers all skill summaries', function () {
    $summaries = $this->loader->discoverSummaries();

    expect($summaries)->toBeArray()
        ->and($summaries)->not->toBeEmpty();

    foreach ($summaries as $summary) {
        expect($summary)->toHaveKey('name')
            ->and($summary)->toHaveKey('description')
            ->and($summary)->toHaveKey('tags');
    }
});
