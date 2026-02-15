<?php

declare(strict_types=1);

use Laragentic\Skills\SkillMetadata;

test('creates metadata from array', function () {
    $data = [
        'name' => 'test-skill',
        'description' => 'A test skill',
        'tags' => ['test', 'example'],
        'version' => '1.0.0',
        'author' => 'Test Author',
        'custom_field' => 'custom value',
    ];

    $metadata = SkillMetadata::fromArray($data);

    expect($metadata->name)->toBe('test-skill')
        ->and($metadata->description)->toBe('A test skill')
        ->and($metadata->tags)->toBe(['test', 'example'])
        ->and($metadata->version)->toBe('1.0.0')
        ->and($metadata->author)->toBe('Test Author')
        ->and($metadata->raw)->toBe($data);
});

test('handles missing optional fields', function () {
    $data = [
        'name' => 'minimal-skill',
        'description' => 'Minimal metadata',
    ];

    $metadata = SkillMetadata::fromArray($data);

    expect($metadata->name)->toBe('minimal-skill')
        ->and($metadata->description)->toBe('Minimal metadata')
        ->and($metadata->tags)->toBe([])
        ->and($metadata->version)->toBeNull()
        ->and($metadata->author)->toBeNull();
});

test('converts metadata to array', function () {
    $metadata = new SkillMetadata(
        name: 'test-skill',
        description: 'A test skill',
        tags: ['test'],
        version: '1.0.0',
        author: 'Author',
    );

    $array = $metadata->toArray();

    expect($array)->toBe([
        'name' => 'test-skill',
        'description' => 'A test skill',
        'tags' => ['test'],
        'version' => '1.0.0',
        'author' => 'Author',
    ]);
});

test('can get custom metadata values', function () {
    $metadata = SkillMetadata::fromArray([
        'name' => 'test',
        'description' => 'Test',
        'custom_key' => 'custom_value',
    ]);

    expect($metadata->get('custom_key'))->toBe('custom_value')
        ->and($metadata->get('missing_key'))->toBeNull()
        ->and($metadata->get('missing_key', 'default'))->toBe('default');
});
