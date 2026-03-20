<?php

declare(strict_types=1);

use Laravel\Ai\Responses\AgentResponse;
use Laragentic\Loops\LoopResult;
use Laragentic\Tests\Fixtures\MathAgent;

/*
|--------------------------------------------------------------------------
| Integration Tests — Real API Calls
|--------------------------------------------------------------------------
|
| These tests call real LLM APIs and require valid API keys in .env.
| They are grouped under 'integration' so they can be run separately:
|
|   vendor/bin/pest --group=integration
|
| To exclude them during normal development:
|
|   vendor/bin/pest --exclude-group=integration
|
*/

// ─── Helpers ────────────────────────────────────────────────────────

if (! function_exists('skipWithoutApiKey')) {
    function skipWithoutApiKey(string $key): void
    {
        if (empty(env($key))) {
            test()->markTestSkipped("Skipping: {$key} not set in .env");
        }
    }
}

// ─── ReAct Loop with Real LLM ──────────────────────────────────────

test('agent uses calculator tool to solve a math problem', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $events = [];

    $result = (new MathAgent)
        ->maxIterations(3)
        ->onBeforeThought(function (string $prompt, int $iteration) use (&$events) {
            $events[] = "thought:before:iter{$iteration}";
        })
        ->onAfterThought(function (AgentResponse $response, int $iteration) use (&$events) {
            $events[] = "thought:after:iter{$iteration}";
            $events[] = "  text: " . mb_substr($response->text, 0, 80);
            $events[] = "  toolCalls: " . $response->toolCalls->count();
            $events[] = "  toolResults: " . $response->toolResults->count();
        })
        ->onBeforeAction(function (string $tool, array $args) use (&$events) {
            $events[] = "action:before:{$tool}(" . json_encode($args) . ')';
        })
        ->onAfterAction(function (string $tool, array $args, string $result) use (&$events) {
            $events[] = "action:after:{$tool} = {$result}";
        })
        ->onObservation(function (string $observation, int $iteration) use (&$events) {
            $events[] = "observation:iter{$iteration}";
        })
        ->onLoopComplete(function (AgentResponse $response, int $iterations) use (&$events) {
            $events[] = "complete:iterations={$iterations}";
        })
        ->reactLoop(
            'What is 847 multiplied by 293? Use the calculator tool.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    // The loop should have completed
    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->completed())->toBeTrue();

    // The answer should contain 248171 (847 * 293 = 248,171)
    expect($result->text())->toContain('248');

    // The SDK should have handled the tool call internally
    expect($result->response->toolCalls)->not->toBeEmpty();

    // The loop should complete in 1 iteration since the SDK handles
    // the full tool cycle within a single prompt() call
    expect($result->iterations)->toBe(1);
})->group('integration');

test('agent handles a multi-step math problem with multiple tool calls', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $toolCallLog = [];

    $result = (new MathAgent)
        ->maxIterations(5)
        ->onAfterThought(function (AgentResponse $response, int $iteration) use (&$toolCallLog) {
            foreach ($response->toolCalls as $tc) {
                $toolCallLog[] = [
                    'iteration' => $iteration,
                    'tool' => $tc->name,
                    'args' => $tc->arguments,
                ];
            }
        })
        ->reactLoop(
            'First calculate 15 * 23, then calculate 99 + 47, then add those two results together. Use the calculator for each step.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result->completed())->toBeTrue();

    // The calculator tool should have been invoked multiple times
    expect($toolCallLog)->not->toBeEmpty();
    expect(count($toolCallLog))->toBeGreaterThanOrEqual(2);

    // All tool calls should target the calculator
    $toolNames = array_unique(array_column($toolCallLog, 'tool'));
    expect($toolNames)->toBe(['calculator']);

    // The expected sub-calculations should appear in the tool arguments
    $expressions = array_column(array_column($toolCallLog, 'args'), 'expression');
    expect($expressions)->toContain('15 * 23');
    expect($expressions)->toContain('99 + 47');

    // The final addition should also have been performed (345 + 146 = 491)
    $hasAddition = collect($expressions)->contains(fn($expr) => str_contains($expr, '345') && str_contains($expr, '146'));
    expect($hasAddition)->toBeTrue('Expected a tool call adding 345 + 146');
})->group('integration');

test('callbacks fire during a real API call', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $callbacksFired = [];

    $result = (new MathAgent)
        ->maxIterations(3)
        ->onLoopStart(function () use (&$callbacksFired) {
            $callbacksFired['loopStart'] = true;
        })
        ->onIterationStart(function (int $i) use (&$callbacksFired) {
            $callbacksFired['iterationStart'] = $i;
        })
        ->onBeforeThought(function () use (&$callbacksFired) {
            $callbacksFired['beforeThought'] = true;
        })
        ->onAfterThought(function () use (&$callbacksFired) {
            $callbacksFired['afterThought'] = true;
        })
        ->onIterationEnd(function () use (&$callbacksFired) {
            $callbacksFired['iterationEnd'] = true;
        })
        ->onLoopComplete(function (AgentResponse $response, int $iterations) use (&$callbacksFired) {
            $callbacksFired['loopComplete'] = $iterations;
        })
        ->reactLoop(
            'What is 5 + 3? Use the calculator.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($callbacksFired)->toHaveKey('loopStart');
    expect($callbacksFired)->toHaveKey('iterationStart');
    expect($callbacksFired)->toHaveKey('beforeThought');
    expect($callbacksFired)->toHaveKey('afterThought');
    expect($callbacksFired)->toHaveKey('iterationEnd');
    expect($callbacksFired)->toHaveKey('loopComplete');
    expect($callbacksFired['loopComplete'])->toBe(1);

    // The actual answer
    expect($result->text())->toContain('8');
})->group('integration');
