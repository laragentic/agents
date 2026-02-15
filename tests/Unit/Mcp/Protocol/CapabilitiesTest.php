<?php

declare(strict_types=1);

use Laragentic\Mcp\Protocol\ClientCapabilities;
use Laragentic\Mcp\Protocol\ServerCapabilities;

describe('ClientCapabilities', function () {
    it('creates with all capabilities', function () {
        $caps = ClientCapabilities::all();

        expect($caps->supportsRoots())->toBeTrue();
        expect($caps->supportsRootsListChanged())->toBeTrue();
        expect($caps->supportsSampling())->toBeTrue();
        expect($caps->supportsSamplingTools())->toBeTrue();
        expect($caps->supportsElicitation())->toBeTrue();
    });

    it('creates with no capabilities', function () {
        $caps = ClientCapabilities::none();

        expect($caps->supportsRoots())->toBeFalse();
        expect($caps->supportsSampling())->toBeFalse();
        expect($caps->supportsElicitation())->toBeFalse();
    });

    it('serializes to array', function () {
        $caps = ClientCapabilities::all();
        $array = $caps->toArray();

        expect($array)->toHaveKeys(['roots', 'sampling', 'elicitation']);
        expect($array['roots']['listChanged'])->toBeTrue();
        expect($array['sampling']['tools'])->toBeTrue();
    });

    it('omits null capabilities from array', function () {
        $caps = new ClientCapabilities(roots: ['listChanged' => true]);
        $array = $caps->toArray();

        expect($array)->toHaveKey('roots');
        expect($array)->not->toHaveKey('sampling');
        expect($array)->not->toHaveKey('elicitation');
    });
});

describe('ServerCapabilities', function () {
    it('parses full capabilities', function () {
        $caps = ServerCapabilities::fromArray([
            'tools' => ['listChanged' => true],
            'resources' => ['subscribe' => true, 'listChanged' => true],
            'prompts' => ['listChanged' => true],
            'logging' => [],
            'completions' => [],
        ]);

        expect($caps->supportsTools())->toBeTrue();
        expect($caps->supportsToolsListChanged())->toBeTrue();
        expect($caps->supportsResources())->toBeTrue();
        expect($caps->supportsResourceSubscriptions())->toBeTrue();
        expect($caps->supportsResourcesListChanged())->toBeTrue();
        expect($caps->supportsPrompts())->toBeTrue();
        expect($caps->supportsPromptsListChanged())->toBeTrue();
        expect($caps->supportsLogging())->toBeTrue();
        expect($caps->supportsCompletions())->toBeTrue();
    });

    it('parses minimal capabilities', function () {
        $caps = ServerCapabilities::fromArray([]);

        expect($caps->supportsTools())->toBeFalse();
        expect($caps->supportsResources())->toBeFalse();
        expect($caps->supportsPrompts())->toBeFalse();
        expect($caps->supportsLogging())->toBeFalse();
        expect($caps->supportsCompletions())->toBeFalse();
    });

    it('handles tools without listChanged', function () {
        $caps = ServerCapabilities::fromArray([
            'tools' => [],
        ]);

        expect($caps->supportsTools())->toBeTrue();
        expect($caps->supportsToolsListChanged())->toBeFalse();
    });

    it('serializes to array', function () {
        $caps = ServerCapabilities::fromArray([
            'tools' => ['listChanged' => true],
            'prompts' => [],
        ]);

        $array = $caps->toArray();

        expect($array)->toHaveKeys(['tools', 'prompts']);
        expect($array)->not->toHaveKey('resources');
    });
});
