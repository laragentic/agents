<?php

declare(strict_types=1);

use Laragentic\Skills\SkillResolver;

beforeEach(function () {
    $this->resolver = new SkillResolver;

    $this->availableSkills = [
        'code-review' => [
            'name' => 'code-review',
            'description' => 'Analyze code for security and performance issues',
            'tags' => ['code', 'security', 'performance'],
        ],
        'data-analysis' => [
            'name' => 'data-analysis',
            'description' => 'Analyze datasets and generate insights',
            'tags' => ['data', 'analytics', 'visualization'],
        ],
        'api-testing' => [
            'name' => 'api-testing',
            'description' => 'Test API endpoints and validate responses',
            'tags' => ['api', 'testing', 'http'],
        ],
    ];
});

test('resolves skills based on exact name match', function () {
    $resolved = $this->resolver->resolve(
        availableSkills: $this->availableSkills,
        query: 'Please use code-review to analyze this file',
        threshold: 0.3,
    );

    expect($resolved)->toContain('code-review');
});

test('resolves skills based on keyword match', function () {
    $resolved = $this->resolver->resolve(
        availableSkills: $this->availableSkills,
        query: 'Help me analyze security vulnerabilities in my code',
        threshold: 0.3,
    );

    expect($resolved)->toContain('code-review');
});

test('resolves skills based on tag match', function () {
    $resolved = $this->resolver->resolve(
        availableSkills: $this->availableSkills,
        query: 'I need help with data visualization',
        threshold: 0.3,
    );

    expect($resolved)->toContain('data-analysis');
});

test('respects threshold parameter', function () {
    $resolved = $this->resolver->resolve(
        availableSkills: $this->availableSkills,
        query: 'Random query with no matches',
        threshold: 0.5,
    );

    expect($resolved)->toBeEmpty();
});

test('respects limit parameter', function () {
    $resolved = $this->resolver->resolve(
        availableSkills: $this->availableSkills,
        query: 'test code data api',
        threshold: 0.1,
        limit: 2,
    );

    expect($resolved)->toHaveCount(2);
});

test('orders results by relevance', function () {
    $resolved = $this->resolver->resolve(
        availableSkills: $this->availableSkills,
        query: 'code review security performance',
        threshold: 0.1,
    );

    // code-review should be first as it matches more keywords
    expect($resolved[0])->toBe('code-review');
});

test('calculates relevance score for exact name match', function () {
    $score = $this->resolver->calculateRelevance(
        summary: $this->availableSkills['code-review'],
        query: 'Use code-review skill',
    );

    expect($score)->toBeGreaterThan(0.5);
});

test('calculates relevance score for keyword matches', function () {
    $score = $this->resolver->calculateRelevance(
        summary: $this->availableSkills['data-analysis'],
        query: 'analyze datasets and generate insights',
    );

    expect($score)->toBeGreaterThan(0.5);
});

test('filters stop words from keywords', function () {
    $score1 = $this->resolver->calculateRelevance(
        summary: $this->availableSkills['code-review'],
        query: 'Review the code for security issues',
    );

    $score2 = $this->resolver->calculateRelevance(
        summary: $this->availableSkills['code-review'],
        query: 'Review code security issues',
    );

    // Scores should be similar despite stop words like "the", "for"
    expect(abs($score1 - $score2))->toBeLessThan(0.3);
});

test('ignores short words', function () {
    $score = $this->resolver->calculateRelevance(
        summary: $this->availableSkills['api-testing'],
        query: 'api is in it at on',
    );

    // Should only match "api", not "is", "in", "it", "at", "on"
    expect($score)->toBeGreaterThan(0.0);
});

test('normalizes score to 0-1 range', function () {
    // Create a query with many matching keywords
    $query = str_repeat('code security performance analyze issues ', 10);

    $score = $this->resolver->calculateRelevance(
        summary: $this->availableSkills['code-review'],
        query: $query,
    );

    expect($score)->toBeLessThanOrEqual(1.0)
        ->and($score)->toBeGreaterThanOrEqual(0.0);
});
