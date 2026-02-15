<?php

declare(strict_types=1);

use Laragentic\Mcp\Features\Sampling\ModelPreferences;
use Laragentic\Mcp\Features\Sampling\SamplingRequest;
use Laragentic\Mcp\Features\Sampling\SamplingResult;

describe('SamplingRequest', function () {
    it('parses from array', function () {
        $request = SamplingRequest::fromArray([
            'messages' => [
                ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello']],
            ],
            'maxTokens' => 1000,
            'systemPrompt' => 'You are helpful.',
            'modelPreferences' => [
                'hints' => [['name' => 'claude-3']],
                'intelligencePriority' => 0.8,
            ],
        ]);

        expect($request->messages)->toHaveCount(1);
        expect($request->maxTokens)->toBe(1000);
        expect($request->systemPrompt)->toBe('You are helpful.');
        expect($request->modelPreferences)->toBeInstanceOf(ModelPreferences::class);
        expect($request->hasTools())->toBeFalse();
    });

    it('detects tools', function () {
        $request = SamplingRequest::fromArray([
            'messages' => [],
            'tools' => [
                ['name' => 'calculator', 'description' => 'Calculate things'],
            ],
        ]);

        expect($request->hasTools())->toBeTrue();
    });
});

describe('SamplingResult', function () {
    it('creates text result', function () {
        $result = SamplingResult::text('Hello!', 'claude-3-opus', 'endTurn');

        expect($result->role)->toBe('assistant');
        expect($result->content)->toBe(['type' => 'text', 'text' => 'Hello!']);
        expect($result->model)->toBe('claude-3-opus');
        expect($result->stopReason)->toBe('endTurn');
    });

    it('serializes to array', function () {
        $result = SamplingResult::text('Test', 'gpt-4');

        $array = $result->toArray();

        expect($array['role'])->toBe('assistant');
        expect($array['content']['text'])->toBe('Test');
        expect($array['model'])->toBe('gpt-4');
    });
});

describe('ModelPreferences', function () {
    it('parses from array', function () {
        $prefs = ModelPreferences::fromArray([
            'hints' => [['name' => 'claude-3'], ['name' => 'gpt-4']],
            'costPriority' => 0.3,
            'speedPriority' => 0.5,
            'intelligencePriority' => 0.9,
        ]);

        expect($prefs->modelHints())->toBe(['claude-3', 'gpt-4']);
        expect($prefs->costPriority)->toBe(0.3);
        expect($prefs->speedPriority)->toBe(0.5);
        expect($prefs->intelligencePriority)->toBe(0.9);
    });

    it('handles empty preferences', function () {
        $prefs = ModelPreferences::fromArray([]);

        expect($prefs->modelHints())->toBe([]);
        expect($prefs->costPriority)->toBeNull();
    });

    it('serializes to array', function () {
        $prefs = new ModelPreferences(
            hints: [['name' => 'claude-3']],
            intelligencePriority: 0.8,
        );

        $array = $prefs->toArray();

        expect($array)->toHaveKeys(['hints', 'intelligencePriority']);
        expect($array)->not->toHaveKey('costPriority');
        expect($array)->not->toHaveKey('speedPriority');
    });
});
