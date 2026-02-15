<?php

declare(strict_types=1);

use Laragentic\Mcp\Features\Roots\Root;

describe('Root', function () {
    it('creates with URI and name', function () {
        $root = new Root(
            uri: 'file:///home/user/project',
            name: 'My Project',
        );

        expect($root->uri)->toBe('file:///home/user/project');
        expect($root->name)->toBe('My Project');
    });

    it('creates with URI only', function () {
        $root = new Root(uri: 'file:///tmp');

        expect($root->uri)->toBe('file:///tmp');
        expect($root->name)->toBeNull();
    });

    it('serializes to array', function () {
        $root = new Root(
            uri: 'file:///home/user/project',
            name: 'My Project',
        );

        expect($root->toArray())->toBe([
            'uri' => 'file:///home/user/project',
            'name' => 'My Project',
        ]);
    });

    it('omits null name from array', function () {
        $root = new Root(uri: 'file:///tmp');

        expect($root->toArray())->toBe([
            'uri' => 'file:///tmp',
        ]);
    });
});
