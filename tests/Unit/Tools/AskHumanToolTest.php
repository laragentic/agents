<?php

declare(strict_types=1);

use Laragentic\Signals\AskHumanSignal;
use Laragentic\Signals\QuestionType;
use Laragentic\Tools\AskHumanTool;
use Laravel\Ai\Tools\Request;

// ─── Tool Identity ───────────────────────────────────────────────────

test('tool name is ask_human', function () {
    expect((new AskHumanTool)->name())->toBe('ask_human');
});

test('tool description is non-empty and mentions clarification', function () {
    $description = (string) (new AskHumanTool)->description();

    expect($description)->not->toBeEmpty();
    expect(strtolower($description))->toContain('clarification');
});

// ─── handle(): free-text mode ────────────────────────────────────────

test('handle() returns a free-text AskHumanSignal when mode is free_text', function () {
    $tool = new AskHumanTool;
    $request = new Request(['mode' => 'free_text', 'question' => 'What is your budget?']);

    $signal = $tool->handle($request);

    expect($signal)->toBeInstanceOf(AskHumanSignal::class);
    expect($signal->isStructured())->toBeFalse();
    expect($signal->getFreeTextQuestion())->toBe('What is your budget?');
});

test('handle() defaults to free_text when no mode provided', function () {
    $tool = new AskHumanTool;
    $request = new Request(['question' => 'Open question?']);

    $signal = $tool->handle($request);

    expect($signal)->toBeInstanceOf(AskHumanSignal::class);
    expect($signal->isStructured())->toBeFalse();
    expect($signal->getFreeTextQuestion())->toBe('Open question?');
});

test('handle() returns empty question string when question key is absent', function () {
    $tool = new AskHumanTool;
    $request = new Request(['mode' => 'free_text']);

    $signal = $tool->handle($request);

    expect($signal->getFreeTextQuestion())->toBe('');
});

// ─── handle(): structured mode ───────────────────────────────────────

test('handle() returns a structured AskHumanSignal when mode is structured', function () {
    $tool = new AskHumanTool;
    $request = new Request([
        'mode' => 'structured',
        'questions' => [
            ['type' => 'single_choice', 'question' => 'Timeline?', 'options' => ['1 week', '1 month', '3 months']],
            ['type' => 'free_text', 'question' => 'Anything else?'],
        ],
    ]);

    $signal = $tool->handle($request);

    expect($signal)->toBeInstanceOf(AskHumanSignal::class);
    expect($signal->isStructured())->toBeTrue();
    expect($signal->getQuestions())->toHaveCount(2);
    expect($signal->getQuestions()[0]->type)->toBe(QuestionType::SingleChoice);
    expect($signal->getQuestions()[0]->question)->toBe('Timeline?');
    expect($signal->getQuestions()[0]->options)->toBe(['1 week', '1 month', '3 months']);
    expect($signal->getQuestions()[1]->type)->toBe(QuestionType::FreeText);
});

test('handle() handles multiple-choice questions in structured mode', function () {
    $tool = new AskHumanTool;
    $request = new Request([
        'mode' => 'structured',
        'questions' => [
            ['type' => 'multiple_choice', 'question' => 'Features needed?', 'options' => ['Auth', 'Payments', 'Notifications']],
        ],
    ]);

    $signal = $tool->handle($request);

    expect($signal->getQuestions()[0]->type)->toBe(QuestionType::MultipleChoice);
    expect($signal->getQuestions()[0]->options)->toBe(['Auth', 'Payments', 'Notifications']);
});

test('handle() returns empty structured signal when questions key is absent', function () {
    $tool = new AskHumanTool;
    $request = new Request(['mode' => 'structured']);

    $signal = $tool->handle($request);

    expect($signal->isStructured())->toBeTrue();
    expect($signal->getQuestions())->toBe([]);
});

// ─── Stringable: signal is valid marker ─────────────────────────────

test('handle() result implements Stringable and is a valid signal marker', function () {
    $tool = new AskHumanTool;
    $request = new Request(['mode' => 'free_text', 'question' => 'Test?']);

    $signal = $tool->handle($request);
    $str = (string) $signal;

    expect(AskHumanSignal::isSignal($str))->toBeTrue();
    expect(AskHumanSignal::fromString($str)->getFreeTextQuestion())->toBe('Test?');
});
