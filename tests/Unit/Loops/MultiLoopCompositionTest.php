<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Loops\CoTResult;
use Laragentic\Loops\LoopResult;
use Laragentic\Tests\Fixtures\AdditionTool;
use Laragentic\Tests\Fixtures\MultiLoopAgent;

// ─── Helpers ────────────────────────────────────────────────────────

function makeMultiResponse(string $text, array $toolCalls = []): AgentResponse
{
    $response = new AgentResponse(
        invocationId: 'inv-' . uniqid(),
        text: $text,
        usage: new Usage(promptTokens: 10, completionTokens: 20),
        meta: new Meta(provider: 'test', model: 'test-model'),
    );

    if (! empty($toolCalls)) {
        $response->toolCalls = new Collection(
            array_map(
                fn (array $tc) => new ToolCall(
                    id: $tc['id'] ?? 'tc-' . uniqid(),
                    name: $tc['name'],
                    arguments: $tc['arguments'] ?? [],
                ),
                $toolCalls,
            ),
        );
    }

    return $response;
}

function makeMultiLoopAgent(): MultiLoopAgent
{
    return new MultiLoopAgent;
}

// ─── Composition: No Collision ───────────────────────────────────────

test('can instantiate an agent using all three loop traits without collision', function () {
    $agent = makeMultiLoopAgent();

    expect($agent)->toBeInstanceOf(MultiLoopAgent::class);
});

test('reactLoop works correctly on a multi-loop agent', function () {
    $agent = makeMultiLoopAgent()->fakeResponses([
        makeMultiResponse('Final answer from ReAct.'),
    ]);

    $result = $agent->reactLoop('Hello');

    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->response->text)->toBe('Final answer from ReAct.');
    expect($result->iterations)->toBe(1);
    expect($result->completed())->toBeTrue();
});

test('chainOfThought works correctly on a multi-loop agent', function () {
    $agent = makeMultiLoopAgent()->fakeResponses([
        makeMultiResponse('I am confident in this. Final Answer: Done.'),
    ]);

    $result = $agent->chainOfThought('What is 1 + 1?');

    expect($result)->toBeInstanceOf(CoTResult::class);
    expect($result->text())->toContain('Final Answer: Done.');
    expect($result->reasoningIterations)->toBe(1);
    expect($result->completed())->toBeTrue();
});

test('shared callback registrations only bind once when both loops used', function () {
    $iterationStartFired = 0;
    $beforeActionFired = 0;
    $maxIterationsReachedFired = 0;

    $agent = makeMultiLoopAgent()
        ->withTools([new AdditionTool])
        ->maxIterations(1)
        ->fakeResponses([
            makeMultiResponse('Calling tool', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 1]],
            ]),
        ])
        ->onIterationStart(function () use (&$iterationStartFired) {
            $iterationStartFired++;
        })
        ->onBeforeAction(function () use (&$beforeActionFired) {
            $beforeActionFired++;
        })
        ->onMaxIterationsReached(function () use (&$maxIterationsReachedFired) {
            $maxIterationsReachedFired++;
        });

    $agent->reactLoop('Test');

    // Each callback should fire exactly once — not duplicated due to shared trait
    expect($iterationStartFired)->toBe(1);
    expect($beforeActionFired)->toBe(1);
    expect($maxIterationsReachedFired)->toBe(1);
});

test('loop-specific callbacks are isolated between loops on same agent', function () {
    $agent = makeMultiLoopAgent();

    // onBeforeThought is ReAct-specific
    expect(method_exists($agent, 'onBeforeThought'))->toBeTrue();

    // onBeforeReasoning is CoT-specific
    expect(method_exists($agent, 'onBeforeReasoning'))->toBeTrue();

    // onPlanCreated is PlanExecute-specific
    expect(method_exists($agent, 'onPlanCreated'))->toBeTrue();

    // Shared callbacks exist
    expect(method_exists($agent, 'onMaxIterationsReached'))->toBeTrue();
    expect(method_exists($agent, 'onIterationStart'))->toBeTrue();
    expect(method_exists($agent, 'onBeforeAction'))->toBeTrue();
    expect(method_exists($agent, 'onAfterAction'))->toBeTrue();
    expect(method_exists($agent, 'onLoopStart'))->toBeTrue();
    expect(method_exists($agent, 'onLoopComplete'))->toBeTrue();
});

test('reactLoop observation prompt includes ReAct guidance when multiple loops composed', function () {
    $observations = [];

    $agent = makeMultiLoopAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeMultiResponse('Adding numbers', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 2, 'b' => 3]],
            ]),
            makeMultiResponse('The sum is 5.'),
        ])
        ->onObservation(function (string $obs) use (&$observations) {
            $observations[] = $obs;
        });

    $agent->reactLoop('What is 2 + 3?');

    expect($observations[0])->toContain('Observation:');
    expect($observations[0])->toContain('[add_numbers]: 5');
    expect($observations[0])->toContain('goal');
});
