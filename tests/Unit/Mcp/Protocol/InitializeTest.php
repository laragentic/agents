<?php

declare(strict_types=1);

use Laragentic\Mcp\Protocol\ClientCapabilities;
use Laragentic\Mcp\Protocol\InitializeParams;
use Laragentic\Mcp\Protocol\InitializeResult;

describe('InitializeParams', function () {
    it('serializes to array', function () {
        $params = new InitializeParams(
            protocolVersion: '2025-11-25',
            capabilities: ClientCapabilities::all(),
            clientName: 'laragentic',
            clientVersion: '1.0.0',
        );

        $array = $params->toArray();

        expect($array['protocolVersion'])->toBe('2025-11-25');
        expect($array['clientInfo']['name'])->toBe('laragentic');
        expect($array['clientInfo']['version'])->toBe('1.0.0');
        expect($array['capabilities'])->toHaveKeys(['roots', 'sampling', 'elicitation']);
    });
});

describe('InitializeResult', function () {
    it('parses from array', function () {
        $result = InitializeResult::fromArray([
            'protocolVersion' => '2025-11-25',
            'capabilities' => [
                'tools' => ['listChanged' => true],
                'resources' => ['subscribe' => true],
            ],
            'serverInfo' => [
                'name' => 'test-server',
                'version' => '2.0.0',
            ],
            'instructions' => 'Use me wisely.',
        ]);

        expect($result->protocolVersion)->toBe('2025-11-25');
        expect($result->serverName)->toBe('test-server');
        expect($result->serverVersion)->toBe('2.0.0');
        expect($result->instructions)->toBe('Use me wisely.');
        expect($result->capabilities->supportsTools())->toBeTrue();
        expect($result->capabilities->supportsResources())->toBeTrue();
    });

    it('handles missing optional fields', function () {
        $result = InitializeResult::fromArray([
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
        ]);

        expect($result->serverName)->toBe('unknown');
        expect($result->serverVersion)->toBe('unknown');
        expect($result->instructions)->toBeNull();
    });

    it('serializes to array', function () {
        $result = InitializeResult::fromArray([
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => []],
            'serverInfo' => ['name' => 'test', 'version' => '1.0'],
            'instructions' => 'Hello',
        ]);

        $array = $result->toArray();

        expect($array['protocolVersion'])->toBe('2025-11-25');
        expect($array['serverInfo']['name'])->toBe('test');
        expect($array['instructions'])->toBe('Hello');
    });
});
