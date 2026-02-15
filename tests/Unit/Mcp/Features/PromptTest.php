<?php

declare(strict_types=1);

use Laragentic\Mcp\Features\Prompts\PromptDefinition;
use Laragentic\Mcp\Features\Prompts\PromptMessage;

describe('PromptDefinition', function () {
    it('parses from array', function () {
        $prompt = PromptDefinition::fromArray([
            'name' => 'code-review',
            'description' => 'Review code for best practices',
            'arguments' => [
                ['name' => 'language', 'description' => 'Programming language', 'required' => true],
                ['name' => 'style', 'description' => 'Review style', 'required' => false],
            ],
        ]);

        expect($prompt->name)->toBe('code-review');
        expect($prompt->description)->toBe('Review code for best practices');
        expect($prompt->arguments)->toHaveCount(2);
        expect($prompt->requiredArguments())->toHaveCount(1);
    });

    it('handles minimal data', function () {
        $prompt = PromptDefinition::fromArray([
            'name' => 'simple',
        ]);

        expect($prompt->name)->toBe('simple');
        expect($prompt->description)->toBeNull();
        expect($prompt->arguments)->toBe([]);
    });

    it('serializes to array', function () {
        $prompt = PromptDefinition::fromArray([
            'name' => 'test',
            'description' => 'A test prompt',
        ]);

        $array = $prompt->toArray();

        expect($array['name'])->toBe('test');
        expect($array['description'])->toBe('A test prompt');
        expect($array)->not->toHaveKey('arguments');
    });
});

describe('PromptMessage', function () {
    it('parses from array', function () {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => [
                ['type' => 'text', 'text' => 'Please review this code.'],
            ],
        ]);

        expect($message->role)->toBe('user');
        expect($message->isUser())->toBeTrue();
        expect($message->isAssistant())->toBeFalse();
        expect($message->text())->toBe('Please review this code.');
    });

    it('handles assistant messages', function () {
        $message = PromptMessage::fromArray([
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => 'Here is my review.'],
            ],
        ]);

        expect($message->isAssistant())->toBeTrue();
        expect($message->isUser())->toBeFalse();
    });

    it('serializes to array', function () {
        $message = PromptMessage::fromArray([
            'role' => 'user',
            'content' => [['type' => 'text', 'text' => 'Hello']],
        ]);

        $array = $message->toArray();

        expect($array['role'])->toBe('user');
        expect($array['content'])->toHaveCount(1);
    });
});
