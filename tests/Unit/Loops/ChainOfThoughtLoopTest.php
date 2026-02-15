<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Exceptions\MaxIterationsExceededException;
use Laragentic\Loops\CoTResult;
use Laragentic\Tests\Fixtures\AdditionTool;
use Laragentic\Tests\Fixtures\CoTTestAgent;

// ─── Helpers ────────────────────────────────────────────────────────

function makeCoTResponse(string $text, array $toolCalls = []): AgentResponse
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
                fn(array $tc) => new ToolCall(
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

function makeCoTAgent(): CoTTestAgent
{
    return new CoTTestAgent;
}

// ─── Chain-of-Thought Flow: Reasoning → Self-Evaluation → Confidence ─

test('completes immediately when agent is confident on first iteration', function () {
    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse('Let me analyze this. After thinking it through, I am confident I can solve it. Final Answer: 42'),
    ]);

    $result = $agent->chainOfThought('What is the answer?');

    expect($result)->toBeInstanceOf(CoTResult::class);
    expect($result->text())->toContain('Final Answer: 42');
    expect($result->reasoningIterations)->toBe(1);
    expect($result->completed())->toBeTrue();
    expect($result->reachedMaxIterations)->toBeFalse();
    expect($agent->getPromptCallCount())->toBe(1);
});

test('iterates multiple times when agent is not confident', function () {
    $agent = makeCoTAgent()->fakeResponses([
        // Iteration 1: Not confident yet
        makeCoTResponse('I need to think about this more. I still need to understand the context.'),
        // Iteration 2: Still not confident
        makeCoTResponse('Let me break this down further. I am uncertain about some aspects.'),
        // Iteration 3: Now confident
        makeCoTResponse('Now I understand! I am confident I can solve this. Final Answer: The solution is clear.'),
    ]);

    $result = $agent->chainOfThought('Complex problem');

    expect($result->reasoningIterations)->toBe(3);
    expect($result->completed())->toBeTrue();
    expect($result->steps)->toHaveCount(3);
    expect($result->steps[0]->confident)->toBeFalse();
    expect($result->steps[1]->confident)->toBeFalse();
    expect($result->steps[2]->confident)->toBeTrue();
});

test('executes tools during reasoning iterations', function () {
    $agent = makeCoTAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            // Iteration 1: Agent needs information, calls tool
            makeCoTResponse('I need to calculate. Let me use the tool.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 3]],
            ]),
            // Iteration 2: Agent has info, now confident
            makeCoTResponse('Now I have the result. I am confident. Final Answer: 8'),
        ]);

    $result = $agent->chainOfThought('What is 5 + 3?');

    expect($result->reasoningIterations)->toBe(2);
    expect($result->steps[0]->hasToolCalls())->toBeTrue();
    expect($result->steps[0]->toolCalls)->toHaveCount(1);
    expect($result->steps[0]->toolCalls[0]['tool'])->toBe('add_numbers');
    expect($result->steps[0]->toolCalls[0]['result'])->toBe('8');
    expect($result->steps[0]->hasObservation())->toBeTrue();
});

test('observation is fed back for reflection in next iteration', function () {
    $prompts = [];

    $agent = makeCoTAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeCoTResponse('I need data', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 2, 'b' => 2]],
            ]),
            makeCoTResponse('I am confident. Final Answer: 4'),
        ]);

    $agent->onBeforeReasoning(function (string $prompt) use (&$prompts) {
        $prompts[] = $prompt;
    });

    $result = $agent->chainOfThought('Calculate');

    // First prompt includes initial problem
    expect($prompts[0])->toContain('Calculate');
    expect($prompts[0])->toContain('Chain-of-Thought');

    // Second prompt includes reflection guidance and tool observation
    expect($prompts[1])->toContain('Continue your reasoning');
    expect($prompts[1])->toContain('[add_numbers]: 4');
});

test('respects max reasoning iterations limit', function () {
    $agent = makeCoTAgent()
        ->maxReasoningIterations(3)
        ->fakeResponses([
            makeCoTResponse('Iteration 1: I need more time'),
            makeCoTResponse('Iteration 2: Still thinking'),
            makeCoTResponse('Iteration 3: Still not confident'),
            makeCoTResponse('This should not be reached'),
        ]);

    $result = $agent->chainOfThought('Hard problem');

    expect($result->reasoningIterations)->toBe(3);
    expect($result->reachedMaxIterations)->toBeTrue();
    expect($result->completed())->toBeFalse();
    expect($agent->getPromptCallCount())->toBe(3);
});

test('throws exception on max iterations when configured', function () {
    config(['agentic.chain_of_thought.throw_on_max_iterations' => true]);

    $agent = makeCoTAgent()
        ->maxReasoningIterations(2)
        ->fakeResponses([
            makeCoTResponse('Not confident'),
            makeCoTResponse('Still not confident'),
        ]);

    $agent->chainOfThought('Problem');
})->throws(MaxIterationsExceededException::class);

test('uses config for max iterations when not set on instance', function () {
    config(['agentic.chain_of_thought.max_reasoning_iterations' => 2]);

    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse('Iteration 1'),
        makeCoTResponse('Iteration 2'),
        makeCoTResponse('Should not reach'),
    ]);

    $result = $agent->chainOfThought('Test');

    expect($result->reasoningIterations)->toBe(2);
    expect($result->reachedMaxIterations)->toBeTrue();
});

// ─── Confidence Detection ───────────────────────────────────────────

test('detects confidence from explicit markers', function () {
    $markers = [
        'I am confident in this solution',
        'I now understand the problem completely',
        'I have sufficient understanding to solve this',
        'Final Answer: Here is the solution',
    ];

    foreach ($markers as $text) {
        $agent = makeCoTAgent()->fakeResponses([
            makeCoTResponse($text),
        ]);

        $result = $agent->chainOfThought('Test');

        expect($result->reasoningIterations)->toBe(1)
            ->and($result->completed())->toBeTrue();
    }
});

test('continues reasoning when uncertainty markers present', function () {
    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse('I need more information about this. I am uncertain.'),
        makeCoTResponse('I am confident now. Final Answer: Done.'),
    ]);

    $result = $agent->chainOfThought('Test');

    expect($result->reasoningIterations)->toBe(2);
    expect($result->steps[0]->confident)->toBeFalse();
    expect($result->steps[1]->confident)->toBeTrue();
});

test('substantial response without tool calls is considered final', function () {
    $longResponse = str_repeat('This is a detailed analysis. ', 20) . ' Therefore, the answer is clear.';

    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse($longResponse),
    ]);

    $result = $agent->chainOfThought('Test');

    expect($result->reasoningIterations)->toBe(1);
    expect($result->completed())->toBeTrue();
});

// ─── Callbacks ──────────────────────────────────────────────────────

test('fires all reasoning lifecycle callbacks', function () {
    $fired = [];

    $agent = makeCoTAgent()
        ->fakeResponses([
            makeCoTResponse('I am confident. Final Answer: Yes'),
        ])
        ->onLoopStart(function () use (&$fired) {
            $fired[] = 'loopStart';
        })
        ->onIterationStart(function () use (&$fired) {
            $fired[] = 'iterationStart';
        })
        ->onBeforeReasoning(function () use (&$fired) {
            $fired[] = 'beforeReasoning';
        })
        ->onAfterReasoning(function () use (&$fired) {
            $fired[] = 'afterReasoning';
        })
        ->onIterationEnd(function () use (&$fired) {
            $fired[] = 'iterationEnd';
        })
        ->onLoopComplete(function () use (&$fired) {
            $fired[] = 'loopComplete';
        });

    $agent->chainOfThought('Test');

    expect($fired)->toBe([
        'loopStart',
        'iterationStart',
        'beforeReasoning',
        'afterReasoning',
        'iterationEnd',
        'loopComplete',
    ]);
});

test('fires action callbacks when tools are called', function () {
    $actions = [];

    $agent = makeCoTAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeCoTResponse('Calculating', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
            makeCoTResponse('Final Answer: 3'),
        ])
        ->onBeforeAction(function (string $tool, array $args) use (&$actions) {
            $actions[] = ['before', $tool, $args];
        })
        ->onAfterAction(function (string $tool, array $args, string $result) use (&$actions) {
            $actions[] = ['after', $tool, $result];
        });

    $agent->chainOfThought('Test');

    expect($actions)->toHaveCount(2);
    expect($actions[0])->toBe(['before', 'add_numbers', ['a' => 1, 'b' => 2]]);
    expect($actions[1])->toBe(['after', 'add_numbers', '3']);
});

test('fires reflection callback when building next iteration prompt', function () {
    $reflections = [];

    $agent = makeCoTAgent()
        ->fakeResponses([
            makeCoTResponse('Not confident yet'),
            makeCoTResponse('I am confident. Final Answer: Done'),
        ])
        ->onReflection(function (string $prompt, int $iteration) use (&$reflections) {
            $reflections[] = ['iteration' => $iteration, 'has_reflection' => str_contains($prompt, 'Reflect')];
        });

    $agent->chainOfThought('Test');

    expect($reflections)->toHaveCount(1);
    expect($reflections[0]['iteration'])->toBe(1);
    expect($reflections[0]['has_reflection'])->toBeTrue();
});

test('fires max iterations callback when limit reached', function () {
    $maxReached = false;

    $agent = makeCoTAgent()
        ->maxReasoningIterations(1)
        ->fakeResponses([
            makeCoTResponse('Not confident'),
        ])
        ->onMaxIterationsReached(function () use (&$maxReached) {
            $maxReached = true;
        });

    $agent->chainOfThought('Test');

    expect($maxReached)->toBeTrue();
});

// ─── Result Object ──────────────────────────────────────────────────

test('result provides access to all reasoning steps', function () {
    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse('Step 1 reasoning'),
        makeCoTResponse('Step 2 reasoning'),
        makeCoTResponse('Final Answer: Done'),
    ]);

    $result = $agent->chainOfThought('Test');

    $reasoningSteps = $result->reasoningSteps();

    expect($reasoningSteps)->toHaveCount(3);
    expect($reasoningSteps[0])->toBe('Step 1 reasoning');
    expect($reasoningSteps[1])->toBe('Step 2 reasoning');
    expect($reasoningSteps[2])->toBe('Final Answer: Done');
});

test('result provides tool call summary across iterations', function () {
    $agent = makeCoTAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeCoTResponse('Calculating', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 1]],
            ]),
            makeCoTResponse('More calculating', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 2, 'b' => 2]],
            ]),
            makeCoTResponse('Final Answer: Done'),
        ]);

    $result = $agent->chainOfThought('Test');

    $allToolCalls = $result->allToolCalls();

    expect($allToolCalls)->toHaveCount(2);
    expect($allToolCalls[0]['tool'])->toBe('add_numbers');
    expect($allToolCalls[0]['iteration'])->toBe(1);
    expect($allToolCalls[1]['tool'])->toBe('add_numbers');
    expect($allToolCalls[1]['iteration'])->toBe(2);
});

test('result identifies the confident step', function () {
    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse('Not confident'),
        makeCoTResponse('I am confident. Final Answer: Yes'),
    ]);

    $result = $agent->chainOfThought('Test');

    $confidentStep = $result->confidentStep();

    expect($confidentStep)->not->toBeNull();
    expect($confidentStep->iteration)->toBe(2);
    expect($confidentStep->confident)->toBeTrue();
});

test('result returns null for confident step when max iterations reached', function () {
    $agent = makeCoTAgent()
        ->maxReasoningIterations(2)
        ->fakeResponses([
            makeCoTResponse('Not confident 1'),
            makeCoTResponse('Not confident 2'),
        ]);

    $result = $agent->chainOfThought('Test');

    expect($result->confidentStep())->toBeNull();
});

test('result delegates properties to agent response', function () {
    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse('Final Answer: Test'),
    ]);

    $result = $agent->chainOfThought('Test');

    expect($result->text())->toBe('Final Answer: Test');
    expect($result->usage)->toBeInstanceOf(Usage::class);
    expect($result->meta)->toBeInstanceOf(Meta::class);
});

// ─── Streaming Variant ──────────────────────────────────────────────

test('streaming variant yields callback values', function () {
    $agent = makeCoTAgent()
        ->fakeResponses([
            makeCoTResponse('I am confident. Final Answer: Yes'),
        ])
        ->onAfterReasoning(function () {
            yield 'reasoning-complete';
        });

    $generator = $agent->chainOfThoughtStream('Test');
    $yielded = iterator_to_array($generator);

    expect($yielded)->toContain('reasoning-complete');

    $result = $generator->getReturn();
    expect($result)->toBeInstanceOf(CoTResult::class);
});

test('streaming variant executes full loop', function () {
    $agent = makeCoTAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeCoTResponse('Calculating', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 5]],
            ]),
            makeCoTResponse('Final Answer: 10'),
        ]);

    $generator = $agent->chainOfThoughtStream('Test');
    iterator_to_array($generator); // Consume generator

    $result = $generator->getReturn();

    expect($result->reasoningIterations)->toBe(2);
    expect($result->completed())->toBeTrue();
});

// ─── Error Handling ─────────────────────────────────────────────────

test('handles missing tool gracefully', function () {
    $agent = makeCoTAgent()->fakeResponses([
        makeCoTResponse('Calling tool', [
            ['name' => 'nonexistent_tool', 'arguments' => []],
        ]),
        makeCoTResponse('Final Answer: Handled'),
    ]);

    $result = $agent->chainOfThought('Test');

    expect($result->steps[0]->toolCalls[0]['result'])->toContain("Tool 'nonexistent_tool' not found");
});

test('handles tool execution errors gracefully', function () {
    $agent = makeCoTAgent()
        ->withTools([new \Laragentic\Tests\Fixtures\FailingTool])
        ->fakeResponses([
            makeCoTResponse('Calling tool', [
                ['name' => 'failing_tool', 'arguments' => []],
            ]),
            makeCoTResponse('Final Answer: Handled error'),
        ]);

    $result = $agent->chainOfThought('Test');

    expect($result->steps[0]->toolCalls[0]['result'])->toContain('Error executing tool');
});
