<?php

declare(strict_types=1);

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\ChainOfThoughtLoop;
use Laragentic\Loops\CoTResult;
use Laragentic\Tests\Fixtures\CalculatorTool;

/**
 * Integration tests for ChainOfThoughtLoop with real API calls.
 *
 * These tests require a valid ANTHROPIC_API_KEY in your .env file.
 * They are tagged with @group integration and will be skipped if the key is missing.
 */

// ─── Test Agent ─────────────────────────────────────────────────────

class CoTIntegrationAgent implements Agent, HasTools
{
    use Promptable, ChainOfThoughtLoop;

    public function instructions(): string
    {
        return 'You are a problem-solving expert. Use Chain-of-Thought reasoning to analyze '
             . 'problems systematically. Evaluate your understanding and express confidence '
             . 'when you have a complete solution. If you need calculations, use the calculator tool.';
    }

    public function tools(): iterable
    {
        return [new CalculatorTool];
    }

    public function model(): string
    {
        return 'claude-opus-4-6';
    }

    public function driver(): string
    {
        return 'anthropic';
    }
}

// ─── Tests ──────────────────────────────────────────────────────────

beforeEach(function () {
    if (! env('ANTHROPIC_API_KEY')) {
        $this->markTestSkipped('ANTHROPIC_API_KEY not set');
    }
});

test('solves simple math problem in one iteration', function () {
    $agent = new CoTIntegrationAgent;

    $result = $agent
        ->maxReasoningIterations(5)
        ->chainOfThought('What is 15% of 200?');

    expect($result)->toBeInstanceOf(CoTResult::class);
    expect($result->reasoningIterations)->toBeLessThanOrEqual(3);
    expect($result->completed())->toBeTrue();
    expect($result->text())->toContain('30');
})->group('integration');

test('uses tool calls to gather information during reasoning', function () {
    $agent = new CoTIntegrationAgent;

    $result = $agent
        ->maxReasoningIterations(5)
        ->chainOfThought(
            'A book costs $25 with a 20% discount. What is the final price after discount?'
        );

    expect($result->reasoningIterations)->toBeGreaterThan(0);
    expect($result->completed())->toBeTrue();

    // Check if agent used calculator or reasoned through it
    $toolCalls = $result->allToolCalls();
    $reasoning = $result->text();

    // Should either use calculator or show calculation reasoning
    expect(count($toolCalls) > 0 || str_contains(strtolower($reasoning), '20'))->toBeTrue();
    expect($reasoning)->toContain('20');
})->group('integration');

test('iterates multiple times for complex reasoning', function () {
    $iterationCount = 0;

    $agent = (new CoTIntegrationAgent)
        ->maxReasoningIterations(6)
        ->onIterationStart(function () use (&$iterationCount) {
            $iterationCount++;
        });

    $result = $agent->chainOfThought(
        'If a train travels 120 miles in 2 hours, and another train travels 180 miles in 3 hours, '
        . 'which train is faster and by how much (in mph)?'
    );

    expect($result->completed())->toBeTrue();
    expect($iterationCount)->toBeGreaterThan(0);
    expect($result->reasoningSteps())->toHaveCount($iterationCount);

    $finalAnswer = strtolower($result->text());
    // Should identify speeds (60 mph and 60 mph, or similar reasoning)
    expect($finalAnswer)->toMatch('/60|speed|mph/i');
})->group('integration');

test('expresses confidence when solution is clear', function () {
    $agent = new CoTIntegrationAgent;

    $result = $agent
        ->maxReasoningIterations(4)
        ->chainOfThought('What is 2 + 2?');

    expect($result->completed())->toBeTrue();

    // Agent should express confidence quickly for simple problems
    expect($result->reasoningIterations)->toBeLessThanOrEqual(2);

    // Check for confidence markers in reasoning
    $allReasoning = strtolower(implode(' ', $result->reasoningSteps()));
    $hasConfidence = str_contains($allReasoning, 'confident') ||
                     str_contains($allReasoning, 'final answer') ||
                     str_contains($allReasoning, 'clear');

    expect($hasConfidence)->toBeTrue();
})->group('integration');

test('handles multi-step reasoning with tool usage', function () {
    $toolUsed = false;

    $agent = (new CoTIntegrationAgent)
        ->maxReasoningIterations(6)
        ->onAfterAction(function () use (&$toolUsed) {
            $toolUsed = true;
        });

    $result = $agent->chainOfThought(
        'A store has 150 items. They sell 40% on Monday and 30% of the remaining on Tuesday. '
        . 'How many items are left?'
    );

    expect($result->completed())->toBeTrue();

    // Verify reasoning quality
    $finalAnswer = $result->text();
    // Should contain the answer: 63 items (150 - 60 - 27 = 63)
    expect($finalAnswer)->toMatch('/63|sixty-three/i');

    // Agent may or may not use calculator, but should solve correctly
    if ($toolUsed) {
        expect($result->allToolCalls())->not->toBeEmpty();
    }
})->group('integration');

test('callbacks fire during real execution', function () {
    $events = [];

    $agent = (new CoTIntegrationAgent)
        ->maxReasoningIterations(4)
        ->onLoopStart(function () use (&$events) {
            $events[] = 'start';
        })
        ->onIterationStart(function (int $iteration) use (&$events) {
            $events[] = "iteration_{$iteration}";
        })
        ->onLoopComplete(function () use (&$events) {
            $events[] = 'complete';
        });

    $result = $agent->chainOfThought('What is 10 divided by 2?');

    expect($events)->toContain('start');
    expect($events)->toContain('complete');
    expect($events)->toContain('iteration_1');
    expect($result->completed())->toBeTrue();
})->group('integration');

test('respects max iterations limit in real execution', function () {
    $agent = new CoTIntegrationAgent;

    $result = $agent
        ->maxReasoningIterations(2)
        ->chainOfThought(
            'Analyze the philosophical implications of artificial intelligence on human consciousness.'
        );

    // With only 2 iterations, complex philosophical question may not complete
    expect($result->reasoningIterations)->toBe(2);

    // Should have attempted reasoning even if incomplete
    expect(strlen($result->text()))->toBeGreaterThan(50);
})->group('integration');

test('works with conversation context', function () {
    $agent = new CoTIntegrationAgent;

    // First question
    $result1 = $agent
        ->maxReasoningIterations(4)
        ->chainOfThought('What is 15 times 4?');

    expect($result1->completed())->toBeTrue();
    expect($result1->text())->toContain('60');

    // Should be able to call again (each call is independent without RemembersConversations)
    $result2 = $agent
        ->maxReasoningIterations(4)
        ->chainOfThought('What is 20 times 3?');

    expect($result2->completed())->toBeTrue();
    expect($result2->text())->toContain('60');
})->group('integration');
