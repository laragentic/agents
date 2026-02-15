<?php

declare(strict_types=1);

use Laravel\Ai\Responses\AgentResponse;
use Laragentic\Loops\PlanResult;
use Laragentic\Loops\PlanStep;
use Laragentic\Tests\Fixtures\ResearchAgent;

/*
|--------------------------------------------------------------------------
| Integration Tests — Plan-Execute Loop with Real API
|--------------------------------------------------------------------------
|
| These tests call real LLM APIs and require valid API keys in .env.
| They are grouped under 'integration' so they can be run separately:
|
|   vendor/bin/pest --group=integration
|
*/

function skipWithoutKey(string $key): void
{
    if (empty(env($key))) {
        test()->markTestSkipped("Skipping: {$key} not set in .env");
    }
}

// ─── Plan-Execute Loop with Real LLM ───────────────────────────────

test('plan-execute: creates plan and executes steps for a research task', function () {
    skipWithoutKey('ANTHROPIC_API_KEY');

    $events = [];

    $result = (new ResearchAgent)
        ->maxSteps(5)
        ->allowReplan(false)
        ->onPlanCreated(function (array $steps) use (&$events) {
            $events[] = 'plan:' . count($steps) . ' steps';
            foreach ($steps as $i => $s) {
                $events[] = '  ' . ($i + 1) . '. ' . mb_substr($s, 0, 60);
            }
        })
        ->onBeforeStep(function (int $num, string $desc) use (&$events) {
            $events[] = "step:before:{$num}";
        })
        ->onAfterStep(function (int $num, string $desc, AgentResponse $response) use (&$events) {
            $events[] = "step:after:{$num} (" . mb_strlen($response->text) . ' chars)';
        })
        ->onBeforeSynthesis(function () use (&$events) {
            $events[] = 'synthesis:before';
        })
        ->onAfterSynthesis(function (AgentResponse $response) use (&$events) {
            $events[] = 'synthesis:after (' . mb_strlen($response->text) . ' chars)';
        })
        ->onLoopComplete(function (AgentResponse $response, int $steps) use (&$events) {
            $events[] = "complete:{$steps} steps";
        })
        ->planExecute(
            'Calculate the total cost of a party: venue is $500, catering for 20 people at $25 each, and decorations are $150. Provide an itemized breakdown.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result)->toBeInstanceOf(PlanResult::class);
    expect($result->completed())->toBeTrue();

    // Should have created a plan with at least one step
    expect($result->plan)->not->toBeEmpty();

    // All steps should be executed
    expect($result->stepsExecuted())->toBe(count($result->plan));

    // The final answer should reference the key numbers
    // Venue $500 + Catering $500 (20*$25) + Decorations $150 = $1,150
    expect($result->text())->toMatch('/1.?150/');

    // Steps should all be PlanStep instances
    foreach ($result->steps as $step) {
        expect($step)->toBeInstanceOf(PlanStep::class);
        expect($step->isExecuted())->toBeTrue();
    }
})->group('integration');

test('plan-execute: callbacks fire in correct order during real API call', function () {
    skipWithoutKey('ANTHROPIC_API_KEY');

    $callbackOrder = [];

    $result = (new ResearchAgent)
        ->maxSteps(5)
        ->allowReplan(false)
        ->onLoopStart(function () use (&$callbackOrder) {
            $callbackOrder[] = 'loopStart';
        })
        ->onPlanCreated(function () use (&$callbackOrder) {
            $callbackOrder[] = 'planCreated';
        })
        ->onBeforeStep(function () use (&$callbackOrder) {
            $callbackOrder[] = 'beforeStep';
        })
        ->onAfterStep(function () use (&$callbackOrder) {
            $callbackOrder[] = 'afterStep';
        })
        ->onBeforeSynthesis(function () use (&$callbackOrder) {
            $callbackOrder[] = 'beforeSynthesis';
        })
        ->onAfterSynthesis(function () use (&$callbackOrder) {
            $callbackOrder[] = 'afterSynthesis';
        })
        ->onLoopComplete(function () use (&$callbackOrder) {
            $callbackOrder[] = 'loopComplete';
        })
        ->planExecute(
            'What is 7 + 5? Then what is 3 * 4? Give me both answers.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    // Verify the order: loopStart → planCreated → steps → synthesis → loopComplete
    expect($callbackOrder[0])->toBe('loopStart');
    expect($callbackOrder[1])->toBe('planCreated');

    // There should be at least one beforeStep/afterStep pair
    expect($callbackOrder)->toContain('beforeStep');
    expect($callbackOrder)->toContain('afterStep');

    // Synthesis and complete should be at the end
    $lastThree = array_slice($callbackOrder, -3);
    expect($lastThree)->toBe(['beforeSynthesis', 'afterSynthesis', 'loopComplete']);

    // The final text should contain both answers (12 and 12)
    expect($result->text())->toContain('12');
})->group('integration');

test('plan-execute: stepResults provides structured access to all step outcomes', function () {
    skipWithoutKey('ANTHROPIC_API_KEY');

    $result = (new ResearchAgent)
        ->maxSteps(5)
        ->allowReplan(false)
        ->planExecute(
            'Calculate 15 * 3 and 100 / 4 separately, then tell me both results.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    $stepResults = $result->stepResults();

    expect($stepResults)->not->toBeEmpty();

    foreach ($stepResults as $sr) {
        expect($sr)->toHaveKeys(['step', 'description', 'result']);
        expect($sr['step'])->toBeInt();
        expect($sr['description'])->toBeString()->not->toBeEmpty();
        expect($sr['result'])->toBeString()->not->toBeEmpty();
    }
})->group('integration');
