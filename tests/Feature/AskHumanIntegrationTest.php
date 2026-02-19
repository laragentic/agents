<?php

declare(strict_types=1);

use Laragentic\Loops\LoopResult;
use Laragentic\Signals\AskHumanSignal;
use Laragentic\Tests\Fixtures\AskHumanAgent;

/*
|--------------------------------------------------------------------------
| AskHuman Integration Tests — Real API Calls
|--------------------------------------------------------------------------
|
| These tests verify that a real LLM recognises the ask_human tool schema,
| calls it when prompted, and that the loop correctly terminates without a
| second LLM call.
|
| Run: vendor/bin/pest --group=integration
| Skip during development: vendor/bin/pest --exclude-group=integration
|
*/

// ─── Free-text Question ──────────────────────────────────────────────

test('agent calls ask_human with a free-text question', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $callbackFired = false;
    $capturedSignal = null;
    $capturedIteration = null;
    $loopCompleted = false;

    $result = (new AskHumanAgent('free_text'))
        ->maxIterations(3)
        ->onAskHuman(function (AskHumanSignal $signal, int $iteration) use (
            &$callbackFired,
            &$capturedSignal,
            &$capturedIteration,
        ) {
            $callbackFired = true;
            $capturedSignal = $signal;
            $capturedIteration = $iteration;
        })
        ->onLoopComplete(function () use (&$loopCompleted) {
            $loopCompleted = true;
        })
        ->reactLoop(
            'I need something. Ask me what I need.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    // ── The loop must have paused to ask — not completed normally ────
    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->askedHuman())->toBeTrue();
    expect($result->completed())->toBeFalse();
    expect($result->reachedMaxIterations)->toBeFalse();
    expect($loopCompleted)->toBeFalse();

    // ── Only one LLM call — no second iteration ──────────────────────
    expect($result->iterations)->toBe(1);

    // ── onAskHuman callback fired correctly ──────────────────────────
    expect($callbackFired)->toBeTrue();
    expect($capturedIteration)->toBe(1);
    expect($capturedSignal)->toBeInstanceOf(AskHumanSignal::class);

    // ── Result and callback share the same signal object ─────────────
    expect($capturedSignal)->toBe($result->askHumanSignal);

    // ── Free-text mode: single non-empty question ────────────────────
    expect($result->askHumanSignal->isStructured())->toBeFalse();
    expect($result->askHumanSignal->getFreeTextQuestion())->not->toBeEmpty();

    // ── Serializes correctly for API responses ───────────────────────
    $arr = $result->askHumanSignal->toArray();
    expect($arr['mode'])->toBe('free_text');
    expect($arr['question'])->not->toBeEmpty();
})->group('integration');

// ─── Structured Questions ────────────────────────────────────────────

test('agent calls ask_human with structured questions when instructed to', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $callbackFired = false;

    $result = (new AskHumanAgent('structured'))
        ->maxIterations(3)
        ->onAskHuman(function (AskHumanSignal $signal) use (&$callbackFired) {
            $callbackFired = true;
        })
        ->reactLoop(
            'I want to build something. Ask me what I want to build using structured questions.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result->askedHuman())->toBeTrue();
    expect($callbackFired)->toBeTrue();

    // ── Structured mode with at least one question ───────────────────
    expect($result->askHumanSignal->isStructured())->toBeTrue();
    expect($result->askHumanSignal->getQuestions())->not->toBeEmpty();

    // ── Every question is valid ──────────────────────────────────────
    foreach ($result->askHumanSignal->getQuestions() as $question) {
        expect($question->question)->not->toBeEmpty();
        expect($question->type->value)->toBeIn(['free_text', 'single_choice', 'multiple_choice']);

        if (in_array($question->type->value, ['single_choice', 'multiple_choice'])) {
            expect($question->options)->not->toBeEmpty();
        }
    }

    // ── Serializes as a frontend would receive it ────────────────────
    $arr = $result->askHumanSignal->toArray();
    expect($arr['mode'])->toBe('structured');
    expect($arr['questions'])->not->toBeEmpty();

    foreach ($arr['questions'] as $q) {
        expect($q)->toHaveKeys(['type', 'question', 'options']);
        expect($q['type'])->toBeIn(['free_text', 'single_choice', 'multiple_choice']);
    }
})->group('integration');

// ─── onAskHuman is the source of truth ───────────────────────────────

test('onAskHuman callback signal is identical to result->askHumanSignal', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $callbackSignal = null;

    $result = (new AskHumanAgent)
        ->maxIterations(3)
        ->onAskHuman(function (AskHumanSignal $signal) use (&$callbackSignal) {
            $callbackSignal = $signal;
        })
        ->reactLoop(
            'Ask me a question before proceeding.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result->askedHuman())->toBeTrue();
    expect($callbackSignal)->not->toBeNull();
    expect($callbackSignal)->toBe($result->askHumanSignal);
    expect($callbackSignal->toArray())->toBe($result->askHumanSignal->toArray());
})->group('integration');

// ─── JSON Roundtrip (simulates frontend/backend boundary) ────────────

test('signal survives JSON encode/decode roundtrip as a frontend would use it', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $result = (new AskHumanAgent('structured'))
        ->maxIterations(3)
        ->reactLoop(
            'Ask me structured questions before doing anything.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result->askedHuman())->toBeTrue();

    // ── Simulate what a Laravel controller returns ───────────────────
    $jsonResponse = json_encode($result->askHumanSignal->toArray());
    expect($jsonResponse)->toBeString()->not->toBeEmpty();

    $decoded = json_decode($jsonResponse, true);
    expect($decoded)->toHaveKey('mode');
    expect($decoded)->toHaveKey('questions');

    // ── The marker string also roundtrips cleanly ────────────────────
    $markerString = (string) $result->askHumanSignal;
    expect(AskHumanSignal::isSignal($markerString))->toBeTrue();

    $restored = AskHumanSignal::fromString($markerString);
    expect($restored->isStructured())->toBe($result->askHumanSignal->isStructured());
    expect($restored->toArray())->toBe($result->askHumanSignal->toArray());
})->group('integration');
