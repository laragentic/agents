<?php

declare(strict_types=1);

use Laragentic\Exceptions\SkillValidationException;
use Laragentic\Skills\FrontmatterParser;

test('parses valid frontmatter', function () {
    $markdown = <<<'MD'
---
name: test-skill
description: A test skill
tags: [test, example]
version: 1.0.0
author: Test Author
---

# Instructions

This is the skill content.
MD;

    $result = FrontmatterParser::parse($markdown);

    expect($result['metadata'])->toBe([
        'name' => 'test-skill',
        'description' => 'A test skill',
        'tags' => ['test', 'example'],
        'version' => '1.0.0',
        'author' => 'Test Author',
    ])
        ->and($result['content'])->toContain('# Instructions')
        ->and($result['content'])->toContain('This is the skill content.');
});

test('parses multiline values', function () {
    $markdown = <<<'MD'
---
name: test
description: |
  This is a multiline
  description that spans
  multiple lines
---

Content here
MD;

    $result = FrontmatterParser::parse($markdown);

    expect($result['metadata']['description'])
        ->toContain('multiline')
        ->toContain('multiple lines');
});

test('throws exception for missing opening delimiter', function () {
    $markdown = 'name: test
---
Content';

    expect(fn () => FrontmatterParser::parse($markdown))
        ->toThrow(SkillValidationException::class, 'must start with YAML frontmatter delimiter');
});

test('throws exception for missing closing delimiter', function () {
    $markdown = <<<'MD'
---
name: test
description: Test

Content without closing delimiter
MD;

    expect(fn () => FrontmatterParser::parse($markdown))
        ->toThrow(SkillValidationException::class, 'must have closing delimiter');
});

test('parses boolean values', function () {
    $markdown = <<<'MD'
---
name: test
description: Test
enabled: true
disabled: false
---

Content
MD;

    $result = FrontmatterParser::parse($markdown);

    expect($result['metadata']['enabled'])->toBe(true)
        ->and($result['metadata']['disabled'])->toBe(false);
});

test('parses numeric values', function () {
    $markdown = <<<'MD'
---
name: test
description: Test
integer_value: 42
float_value: 3.14
---

Content
MD;

    $result = FrontmatterParser::parse($markdown);

    expect($result['metadata']['integer_value'])->toBe(42)
        ->and($result['metadata']['float_value'])->toBe(3.14);
});

test('ignores comments and empty lines', function () {
    $markdown = <<<'MD'
---
# This is a comment
name: test

description: Test
# Another comment
---

Content
MD;

    $result = FrontmatterParser::parse($markdown);

    expect($result['metadata'])->toBe([
        'name' => 'test',
        'description' => 'Test',
    ]);
});
