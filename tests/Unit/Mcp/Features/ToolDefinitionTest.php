<?php

declare(strict_types=1);

use Laragentic\Mcp\Features\Tools\ToolDefinition;
use Laragentic\Mcp\Features\Tools\ToolResult;

describe('ToolDefinition', function () {
    it('parses from array', function () {
        $tool = ToolDefinition::fromArray([
            'name' => 'read_file',
            'description' => 'Read a file from the filesystem',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'path' => [
                        'type' => 'string',
                        'description' => 'The file path to read',
                    ],
                ],
                'required' => ['path'],
            ],
        ]);

        expect($tool->name)->toBe('read_file');
        expect($tool->description)->toBe('Read a file from the filesystem');
        expect($tool->properties())->toHaveKey('path');
        expect($tool->required())->toBe(['path']);
    });

    it('handles missing optional fields', function () {
        $tool = ToolDefinition::fromArray([
            'name' => 'simple_tool',
        ]);

        expect($tool->name)->toBe('simple_tool');
        expect($tool->description)->toBe('');
        expect($tool->properties())->toBe([]);
        expect($tool->required())->toBe([]);
        expect($tool->annotations)->toBeNull();
    });

    it('parses annotations', function () {
        $tool = ToolDefinition::fromArray([
            'name' => 'delete_file',
            'description' => 'Delete a file',
            'inputSchema' => ['type' => 'object'],
            'annotations' => [
                'readOnlyHint' => false,
                'destructiveHint' => true,
                'confirmationHint' => true,
            ],
        ]);

        expect($tool->isReadOnly())->toBeFalse();
        expect($tool->isDestructive())->toBeTrue();
        expect($tool->requiresConfirmation())->toBeTrue();
    });

    it('serializes to array', function () {
        $tool = ToolDefinition::fromArray([
            'name' => 'test',
            'description' => 'A test tool',
            'inputSchema' => ['type' => 'object'],
        ]);

        $array = $tool->toArray();

        expect($array['name'])->toBe('test');
        expect($array['description'])->toBe('A test tool');
        expect($array)->not->toHaveKey('annotations');
    });
});

describe('ToolResult', function () {
    it('parses text content', function () {
        $result = ToolResult::fromArray([
            'content' => [
                ['type' => 'text', 'text' => 'Hello, world!'],
            ],
        ]);

        expect($result->text())->toBe('Hello, world!');
        expect($result->hasError())->toBeFalse();
        expect((string) $result)->toBe('Hello, world!');
    });

    it('parses error result', function () {
        $result = ToolResult::fromArray([
            'content' => [
                ['type' => 'text', 'text' => 'File not found'],
            ],
            'isError' => true,
        ]);

        expect($result->hasError())->toBeTrue();
        expect((string) $result)->toBe('Error: File not found');
    });

    it('handles multiple content items', function () {
        $result = ToolResult::fromArray([
            'content' => [
                ['type' => 'text', 'text' => 'Line 1'],
                ['type' => 'text', 'text' => 'Line 2'],
            ],
        ]);

        expect($result->text())->toBe("Line 1\nLine 2");
    });

    it('handles empty content', function () {
        $result = ToolResult::fromArray([]);

        expect($result->text())->toBe('');
        expect($result->contentItems())->toBe([]);
    });

    it('parses structured content', function () {
        $result = ToolResult::fromArray([
            'content' => [['type' => 'text', 'text' => 'ok']],
            'structuredContent' => ['status' => 'success'],
        ]);

        expect($result->structuredContent)->toBe(['status' => 'success']);
    });
});
