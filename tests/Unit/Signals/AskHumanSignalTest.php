<?php

declare(strict_types=1);

use Laragentic\Signals\AskHumanSignal;
use Laragentic\Signals\HumanQuestion;
use Laragentic\Signals\QuestionType;

// ─── QuestionType Enum ───────────────────────────────────────────────

test('QuestionType enum has correct string values', function () {
    expect(QuestionType::FreeText->value)->toBe('free_text');
    expect(QuestionType::SingleChoice->value)->toBe('single_choice');
    expect(QuestionType::MultipleChoice->value)->toBe('multiple_choice');
});

test('QuestionType can be created from string value', function () {
    expect(QuestionType::from('free_text'))->toBe(QuestionType::FreeText);
    expect(QuestionType::from('single_choice'))->toBe(QuestionType::SingleChoice);
    expect(QuestionType::from('multiple_choice'))->toBe(QuestionType::MultipleChoice);
});

// ─── HumanQuestion ───────────────────────────────────────────────────

test('HumanQuestion::freeText creates a free-text question', function () {
    $q = HumanQuestion::freeText('What is your budget?');

    expect($q->type)->toBe(QuestionType::FreeText);
    expect($q->question)->toBe('What is your budget?');
    expect($q->options)->toBe([]);
});

test('HumanQuestion::singleChoice creates a single-choice question', function () {
    $q = HumanQuestion::singleChoice('Timeline?', ['1 week', '1 month', '3 months']);

    expect($q->type)->toBe(QuestionType::SingleChoice);
    expect($q->question)->toBe('Timeline?');
    expect($q->options)->toBe(['1 week', '1 month', '3 months']);
});

test('HumanQuestion::multipleChoice creates a multiple-choice question', function () {
    $q = HumanQuestion::multipleChoice('Features needed?', ['Auth', 'Payments', 'Notifications']);

    expect($q->type)->toBe(QuestionType::MultipleChoice);
    expect($q->question)->toBe('Features needed?');
    expect($q->options)->toBe(['Auth', 'Payments', 'Notifications']);
});

test('HumanQuestion::fromArray deserializes correctly for all types', function () {
    $freeText = HumanQuestion::fromArray(['type' => 'free_text', 'question' => 'Why?']);
    expect($freeText->type)->toBe(QuestionType::FreeText);
    expect($freeText->question)->toBe('Why?');
    expect($freeText->options)->toBe([]);

    $single = HumanQuestion::fromArray(['type' => 'single_choice', 'question' => 'Pick one?', 'options' => ['A', 'B']]);
    expect($single->type)->toBe(QuestionType::SingleChoice);
    expect($single->options)->toBe(['A', 'B']);

    $multi = HumanQuestion::fromArray(['type' => 'multiple_choice', 'question' => 'Pick many?', 'options' => ['X', 'Y', 'Z']]);
    expect($multi->type)->toBe(QuestionType::MultipleChoice);
});

test('HumanQuestion::toArray serializes to expected structure', function () {
    $q = HumanQuestion::singleChoice('Color?', ['Red', 'Blue']);

    expect($q->toArray())->toBe([
        'type' => 'single_choice',
        'question' => 'Color?',
        'options' => ['Red', 'Blue'],
    ]);
});

// ─── AskHumanSignal: free-text mode ─────────────────────────────────

test('AskHumanSignal::freeText creates a free-text signal', function () {
    $signal = AskHumanSignal::freeText('What city?');

    expect($signal->isStructured())->toBeFalse();
    expect($signal->getFreeTextQuestion())->toBe('What city?');
    expect($signal->getQuestions())->toBe([]);
});

test('free-text signal serializes to correct array', function () {
    $signal = AskHumanSignal::freeText('What is your name?');

    expect($signal->toArray())->toBe([
        'mode' => 'free_text',
        'question' => 'What is your name?',
        'questions' => [],
    ]);
});

test('free-text signal __toString produces valid JSON marker', function () {
    $signal = AskHumanSignal::freeText('What is your budget?');
    $json = (string) $signal;

    $decoded = json_decode($json, true);
    expect($decoded['__laragentic_ask_human'])->toBeTrue();
    expect($decoded['mode'])->toBe('free_text');
    expect($decoded['question'])->toBe('What is your budget?');
});

// ─── AskHumanSignal: structured mode ────────────────────────────────

test('AskHumanSignal::structured creates a structured signal', function () {
    $questions = [
        HumanQuestion::singleChoice('Timeline?', ['1 week', '1 month']),
        HumanQuestion::freeText('Anything else?'),
    ];

    $signal = AskHumanSignal::structured($questions);

    expect($signal->isStructured())->toBeTrue();
    expect($signal->getFreeTextQuestion())->toBeNull();
    expect($signal->getQuestions())->toHaveCount(2);
    expect($signal->getQuestions()[0]->type)->toBe(QuestionType::SingleChoice);
    expect($signal->getQuestions()[1]->type)->toBe(QuestionType::FreeText);
});

test('structured signal serializes to correct array', function () {
    $signal = AskHumanSignal::structured([
        HumanQuestion::multipleChoice('Features?', ['Auth', 'Payments']),
    ]);

    $arr = $signal->toArray();
    expect($arr['mode'])->toBe('structured');
    expect($arr['question'])->toBeNull();
    expect($arr['questions'])->toHaveCount(1);
    expect($arr['questions'][0]['type'])->toBe('multiple_choice');
    expect($arr['questions'][0]['options'])->toBe(['Auth', 'Payments']);
});

test('structured signal __toString produces valid JSON marker', function () {
    $signal = AskHumanSignal::structured([
        HumanQuestion::singleChoice('Pick?', ['A', 'B']),
    ]);

    $decoded = json_decode((string) $signal, true);
    expect($decoded['__laragentic_ask_human'])->toBeTrue();
    expect($decoded['mode'])->toBe('structured');
    expect($decoded['questions'])->toHaveCount(1);
    expect($decoded['questions'][0]['type'])->toBe('single_choice');
});

// ─── AskHumanSignal: isSignal() detection ───────────────────────────

test('isSignal() returns true for a valid signal string', function () {
    $signal = AskHumanSignal::freeText('Hello?');

    expect(AskHumanSignal::isSignal((string) $signal))->toBeTrue();
});

test('isSignal() returns true for a structured signal string', function () {
    $signal = AskHumanSignal::structured([HumanQuestion::freeText('Q?')]);

    expect(AskHumanSignal::isSignal((string) $signal))->toBeTrue();
});

test('isSignal() returns false for a plain string', function () {
    expect(AskHumanSignal::isSignal('just a tool result'))->toBeFalse();
});

test('isSignal() returns false for arbitrary JSON without the marker key', function () {
    expect(AskHumanSignal::isSignal('{"mode":"free_text","question":"foo"}'))->toBeFalse();
});

test('isSignal() returns false for an empty string', function () {
    expect(AskHumanSignal::isSignal(''))->toBeFalse();
});

// ─── AskHumanSignal: fromString() roundtrip ─────────────────────────

test('fromString() roundtrips a free-text signal correctly', function () {
    $original = AskHumanSignal::freeText('What is your budget?');
    $restored = AskHumanSignal::fromString((string) $original);

    expect($restored->isStructured())->toBeFalse();
    expect($restored->getFreeTextQuestion())->toBe('What is your budget?');
});

test('fromString() roundtrips a structured signal correctly', function () {
    $original = AskHumanSignal::structured([
        HumanQuestion::singleChoice('Timeline?', ['Soon', 'Later']),
        HumanQuestion::freeText('Anything else?'),
    ]);

    $restored = AskHumanSignal::fromString((string) $original);

    expect($restored->isStructured())->toBeTrue();
    expect($restored->getQuestions())->toHaveCount(2);
    expect($restored->getQuestions()[0]->type)->toBe(QuestionType::SingleChoice);
    expect($restored->getQuestions()[0]->question)->toBe('Timeline?');
    expect($restored->getQuestions()[0]->options)->toBe(['Soon', 'Later']);
    expect($restored->getQuestions()[1]->type)->toBe(QuestionType::FreeText);
});

test('fromString() throws on an invalid string', function () {
    AskHumanSignal::fromString('{"not":"a signal"}');
})->throws(\InvalidArgumentException::class);
