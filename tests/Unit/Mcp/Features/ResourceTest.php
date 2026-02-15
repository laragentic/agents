<?php

declare(strict_types=1);

use Laragentic\Mcp\Features\Resources\ResourceContents;
use Laragentic\Mcp\Features\Resources\ResourceDefinition;
use Laragentic\Mcp\Features\Resources\ResourceTemplate;

describe('ResourceDefinition', function () {
    it('parses from array', function () {
        $resource = ResourceDefinition::fromArray([
            'uri' => 'file:///tmp/readme.md',
            'name' => 'README',
            'description' => 'Project readme file',
            'mimeType' => 'text/markdown',
            'size' => 1024,
        ]);

        expect($resource->uri)->toBe('file:///tmp/readme.md');
        expect($resource->name)->toBe('README');
        expect($resource->description)->toBe('Project readme file');
        expect($resource->mimeType)->toBe('text/markdown');
        expect($resource->size)->toBe(1024);
    });

    it('handles minimal data', function () {
        $resource = ResourceDefinition::fromArray([
            'uri' => 'file:///tmp/test',
            'name' => 'Test',
        ]);

        expect($resource->description)->toBeNull();
        expect($resource->mimeType)->toBeNull();
        expect($resource->size)->toBeNull();
    });

    it('serializes to array', function () {
        $resource = ResourceDefinition::fromArray([
            'uri' => 'file:///tmp/test',
            'name' => 'Test',
            'description' => 'A test',
        ]);

        $array = $resource->toArray();

        expect($array)->toBe([
            'uri' => 'file:///tmp/test',
            'name' => 'Test',
            'description' => 'A test',
        ]);
    });
});

describe('ResourceTemplate', function () {
    it('parses from array', function () {
        $template = ResourceTemplate::fromArray([
            'uriTemplate' => 'file:///tmp/{filename}',
            'name' => 'File by name',
            'description' => 'Access a file by name',
            'mimeType' => 'application/octet-stream',
        ]);

        expect($template->uriTemplate)->toBe('file:///tmp/{filename}');
        expect($template->name)->toBe('File by name');
        expect($template->description)->toBe('Access a file by name');
    });

    it('serializes to array', function () {
        $template = ResourceTemplate::fromArray([
            'uriTemplate' => 'db://{table}/{id}',
            'name' => 'Database record',
        ]);

        $array = $template->toArray();

        expect($array['uriTemplate'])->toBe('db://{table}/{id}');
        expect($array['name'])->toBe('Database record');
    });
});

describe('ResourceContents', function () {
    it('handles text content', function () {
        $content = ResourceContents::fromArray([
            'uri' => 'file:///tmp/test.txt',
            'mimeType' => 'text/plain',
            'text' => 'Hello, world!',
        ]);

        expect($content->isText())->toBeTrue();
        expect($content->isBinary())->toBeFalse();
        expect($content->content())->toBe('Hello, world!');
        expect((string) $content)->toBe('Hello, world!');
    });

    it('handles binary content', function () {
        $data = base64_encode('binary data');
        $content = ResourceContents::fromArray([
            'uri' => 'file:///tmp/image.png',
            'mimeType' => 'image/png',
            'blob' => $data,
        ]);

        expect($content->isText())->toBeFalse();
        expect($content->isBinary())->toBeTrue();
        expect($content->content())->toBe('binary data');
    });

    it('handles empty content', function () {
        $content = new ResourceContents(uri: 'file:///tmp/empty');

        expect($content->content())->toBe('');
    });
});
