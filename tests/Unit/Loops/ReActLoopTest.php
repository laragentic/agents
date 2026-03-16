<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Contracts\PausesLoop;
use Laragentic\Exceptions\MaxIterationsExceededException;
use Laragentic\Loops\LoopResult;
use Laragentic\Signals\AskHumanSignal;
use Laragentic\Signals\HumanQuestion;
use Laragentic\Signals\QuestionType;
use Laragentic\Tests\Fixtures\AdditionTool;
use Laragentic\Tests\Fixtures\FailingTool;
use Laragentic\Tests\Fixtures\PausingTool;
use Laragentic\Tests\Fixtures\TestAgent;
use Laragentic\Tools\AskHumanTool;

// ─── Helpers ────────────────────────────────────────────────────────

function makeResponse(string $text, array $toolCalls = []): AgentResponse
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

function makeAgent(): TestAgent
{
    return new TestAgent;
}

// ─── ReAct Flow: Thought → Action → Observation ─────────────────────

test('completes immediately when LLM returns no tool calls (goal satisfied on first thought)', function () {
    $agent = makeAgent()->fakeResponses([
        makeResponse('Hello! Here is my answer.'),
    ]);

    $result = $agent->reactLoop('Say hello');

    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->response->text)->toBe('Hello! Here is my answer.');
    expect($result->iterations)->toBe(1);
    expect($result->completed())->toBeTrue();
    expect($result->reachedMaxIterations)->toBeFalse();
    expect($agent->getPromptCallCount())->toBe(1);
});

test('follows Thought → Action → Observation → Thought cycle', function () {
    $prompts = [];

    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            // Thought 1: LLM decides to call a tool
            makeResponse('I need to add numbers.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 2, 'b' => 3]],
            ]),
            // Thought 2: LLM sees observation and gives final answer
            makeResponse('The sum of 2 and 3 is 5.'),
        ]);

    // Capture what prompts are sent to the LLM
    $agent->onBeforeThought(function (string $prompt) use (&$prompts) {
        $prompts[] = $prompt;
    });

    $result = $agent->reactLoop('What is 2 + 3?');

    // First prompt is the user's original question
    expect($prompts[0])->toBe('What is 2 + 3?');

    // Second prompt is the observation (tool results fed back)
    expect($prompts[1])->toContain('Observation:');
    expect($prompts[1])->toContain('[add_numbers]: 5');

    expect($result->iterations)->toBe(2);
    expect($result->completed())->toBeTrue();
    expect($result->response->text)->toBe('The sum of 2 and 3 is 5.');
});

test('observation is stored on the LoopStep', function () {
    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Adding', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
            makeResponse('Done.'),
        ]);

    $result = $agent->reactLoop('Test');

    // Step 1 had tool calls → has observation
    expect($result->steps[0]->hasObservation())->toBeTrue();
    expect($result->steps[0]->observation)->toContain('[add_numbers]: 3');

    // Step 2 was final answer → no observation
    expect($result->steps[1]->hasObservation())->toBeFalse();
    expect($result->steps[1]->observation)->toBeNull();
});

test('multi-step: weather example — Thought/Action/Observation until goal met', function () {
    // Simulates: "What's the weather in Tokyo and should I bring an umbrella?"
    $agent = makeAgent()
        ->withTools([new AdditionTool]) // stand-in for weather tool
        ->fakeResponses([
            // Thought 1: I need to check the weather → calls tool
            makeResponse('I need to check the weather in Tokyo.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 85, 'b' => 0]], // simulates rain %
            ]),
            // Thought 2: LLM sees "85" in observation, decides goal is met
            makeResponse('The rain probability is 85%. Yes, bring an umbrella.'),
        ]);

    $result = $agent->reactLoop("What's the weather in Tokyo and should I bring an umbrella?");

    expect($result->iterations)->toBe(2);
    expect($result->completed())->toBeTrue();
    // The observation from step 1 contained the tool result
    expect($result->steps[0]->observation)->toContain('85');
    // The final answer is the LLM's synthesis after seeing the observation
    expect($result->response->text)->toContain('umbrella');
});

test('executes multiple tool calls in a single action step', function () {
    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('I need to add two pairs.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
                ['name' => 'add_numbers', 'arguments' => ['a' => 3, 'b' => 4]],
            ]),
            makeResponse('Results: 3 and 7.'),
        ]);

    $result = $agent->reactLoop('Add 1+2 and 3+4');

    expect($result->iterations)->toBe(2);
    expect($result->steps[0]->toolCalls)->toHaveCount(2);
    expect($result->steps[0]->toolCalls[0]['result'])->toBe('3');
    expect($result->steps[0]->toolCalls[1]['result'])->toBe('7');
    // Observation contains both results
    expect($result->steps[0]->observation)->toContain('[add_numbers]: 3');
    expect($result->steps[0]->observation)->toContain('[add_numbers]: 7');
});

test('runs multiple Thought → Action → Observation cycles', function () {
    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Step 1: add', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
            makeResponse('Step 2: add again', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 3, 'b' => 4]],
            ]),
            makeResponse('Step 3: add once more', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 6]],
            ]),
            makeResponse('Done. Results: 3, 7, 11.'),
        ]);

    $result = $agent->reactLoop('Do three additions');

    expect($result->iterations)->toBe(4);
    expect($result->completed())->toBeTrue();
    expect($result->steps)->toHaveCount(4);
    expect($agent->getPromptCallCount())->toBe(4);
});

// ─── Max Iterations ─────────────────────────────────────────────────

test('stops at max iterations and returns last response', function () {
    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->maxIterations(2)
        ->fakeResponses([
            makeResponse('Keep going', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 1]],
            ]),
            makeResponse('Still going', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 2, 'b' => 2]],
            ]),
            makeResponse('You will never see this'),
        ]);

    $result = $agent->reactLoop('Loop forever');

    expect($result->iterations)->toBe(2);
    expect($result->reachedMaxIterations)->toBeTrue();
    expect($result->completed())->toBeFalse();
    expect($result->response->text)->toBe('Still going');
    expect($agent->getPromptCallCount())->toBe(2);
});

test('throws MaxIterationsExceededException when configured', function () {
    config()->set('agentic.react.throw_on_max_iterations', true);

    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->maxIterations(1)
        ->fakeResponses([
            makeResponse('Need more', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 1]],
            ]),
        ]);

    $agent->reactLoop('Do something');
})->throws(MaxIterationsExceededException::class, 'maximum of 1 iterations');

test('fluent maxIterations overrides config default', function () {
    config()->set('agentic.react.max_iterations', 100);

    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->maxIterations(1)
        ->fakeResponses([
            makeResponse('Tool call', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 1]],
            ]),
        ]);

    $result = $agent->reactLoop('Test');

    expect($result->iterations)->toBe(1);
    expect($result->reachedMaxIterations)->toBeTrue();
});

// ─── Tool Execution ─────────────────────────────────────────────────

test('handles missing tool gracefully', function () {
    $agent = makeAgent()
        ->withTools([])
        ->fakeResponses([
            makeResponse('Calling unknown tool', [
                ['name' => 'nonexistent_tool', 'arguments' => []],
            ]),
            makeResponse('Done.'),
        ]);

    $result = $agent->reactLoop('Test');

    expect($result->steps[0]->toolCalls[0]['result'])
        ->toContain("Error: Tool 'nonexistent_tool' not found.");
});

test('handles tool exceptions gracefully', function () {
    $agent = makeAgent()
        ->withTools([new FailingTool])
        ->fakeResponses([
            makeResponse('Calling failing tool', [
                ['name' => 'failing_tool', 'arguments' => []],
            ]),
            makeResponse('Done.'),
        ]);

    $result = $agent->reactLoop('Test');

    expect($result->steps[0]->toolCalls[0]['result'])
        ->toContain('Error executing tool')
        ->toContain('Something went wrong');
});

test('passes arguments to tool via Request object', function () {
    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Adding', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 10, 'b' => 20]],
            ]),
            makeResponse('The answer is 30.'),
        ]);

    $result = $agent->reactLoop('Add 10 and 20');

    expect($result->steps[0]->toolCalls[0]['result'])->toBe('30');
    expect($result->steps[0]->toolCalls[0]['arguments'])->toBe(['a' => 10, 'b' => 20]);
});

// ─── Callbacks ──────────────────────────────────────────────────────

test('fires all lifecycle callbacks in correct ReAct order', function () {
    $events = [];

    $agent = makeAgent()
        ->fakeResponses([makeResponse('Done.')])
        ->onLoopStart(function () use (&$events) {
            $events[] = 'loopStart';
        })
        ->onIterationStart(function () use (&$events) {
            $events[] = 'iterationStart';
        })
        ->onBeforeThought(function () use (&$events) {
            $events[] = 'beforeThought';
        })
        ->onAfterThought(function () use (&$events) {
            $events[] = 'afterThought';
        })
        ->onIterationEnd(function () use (&$events) {
            $events[] = 'iterationEnd';
        })
        ->onLoopComplete(function () use (&$events) {
            $events[] = 'loopComplete';
        });

    $agent->reactLoop('Test');

    expect($events)->toBe([
        'loopStart',
        'iterationStart',
        'beforeThought',
        'afterThought',
        'iterationEnd',
        'loopComplete',
    ]);
});

test('fires Thought → Action → Observation callbacks in correct order', function () {
    $events = [];

    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Adding', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
            makeResponse('Done.'),
        ])
        ->onBeforeThought(function () use (&$events) {
            $events[] = 'thought:before';
        })
        ->onAfterThought(function () use (&$events) {
            $events[] = 'thought:after';
        })
        ->onBeforeAction(function (string $tool) use (&$events) {
            $events[] = "action:before:{$tool}";
        })
        ->onAfterAction(function (string $tool, array $args, string $result) use (&$events) {
            $events[] = "action:after:{$tool}={$result}";
        })
        ->onObservation(function (string $obs) use (&$events) {
            $events[] = 'observation';
        });

    $agent->reactLoop('Test');

    expect($events)->toBe([
        // Iteration 1: Thought → Action → Observation
        'thought:before',
        'thought:after',
        'action:before:add_numbers',
        'action:after:add_numbers=3',
        'observation',
        // Iteration 2: Thought (final answer, no action/observation)
        'thought:before',
        'thought:after',
    ]);
});

test('onObservation receives the formatted observation string', function () {
    $receivedObservation = null;

    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Adding', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 3]],
            ]),
            makeResponse('Done.'),
        ])
        ->onObservation(function (string $observation) use (&$receivedObservation) {
            $receivedObservation = $observation;
        });

    $agent->reactLoop('Test');

    expect($receivedObservation)->toContain('Observation:');
    expect($receivedObservation)->toContain('[add_numbers]: 8');
    expect($receivedObservation)->toContain('goal');
});

test('fires onMaxIterationsReached callback', function () {
    $reached = false;
    $receivedIterations = null;

    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->maxIterations(1)
        ->fakeResponses([
            makeResponse('Tool call', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 1]],
            ]),
        ])
        ->onMaxIterationsReached(function ($response, $iterations) use (&$reached, &$receivedIterations) {
            $reached = true;
            $receivedIterations = $iterations;
        });

    $agent->reactLoop('Test');

    expect($reached)->toBeTrue();
    expect($receivedIterations)->toBe(1);
});

test('callbacks receive correct arguments', function () {
    $thoughtPrompts = [];
    $actionNames = [];
    $actionResults = [];
    $finalText = null;
    $totalIterations = null;

    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Adding', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 3]],
            ]),
            makeResponse('The answer is 8.'),
        ])
        ->onBeforeThought(function (string $prompt, int $iteration) use (&$thoughtPrompts) {
            $thoughtPrompts[] = ['prompt' => $prompt, 'iteration' => $iteration];
        })
        ->onBeforeAction(function (string $name) use (&$actionNames) {
            $actionNames[] = $name;
        })
        ->onAfterAction(function (string $name, array $args, string $result) use (&$actionResults) {
            $actionResults[] = $result;
        })
        ->onLoopComplete(function (AgentResponse $response, int $total) use (&$finalText, &$totalIterations) {
            $finalText = $response->text;
            $totalIterations = $total;
        });

    $agent->reactLoop('Add 5 and 3');

    // Iteration 1: original prompt
    expect($thoughtPrompts[0]['prompt'])->toBe('Add 5 and 3');
    expect($thoughtPrompts[0]['iteration'])->toBe(1);

    // Iteration 2: observation fed back
    expect($thoughtPrompts[1]['prompt'])->toContain('Observation:');
    expect($thoughtPrompts[1]['iteration'])->toBe(2);

    expect($actionNames)->toBe(['add_numbers']);
    expect($actionResults)->toBe(['8']);
    expect($finalText)->toBe('The answer is 8.');
    expect($totalIterations)->toBe(2);
});

test('callbacks work with zero registered', function () {
    $agent = makeAgent()->fakeResponses([
        makeResponse('Done.'),
    ]);

    $result = $agent->reactLoop('Test');

    expect($result->response->text)->toBe('Done.');
});

// ─── LoopResult ─────────────────────────────────────────────────────

test('LoopResult delegates property access to AgentResponse', function () {
    $agent = makeAgent()->fakeResponses([
        makeResponse('Answer text'),
    ]);

    $result = $agent->reactLoop('Test');

    expect($result->text())->toBe('Answer text');
    expect((string) $result)->toBe('Answer text');
    expect($result->invocationId)->toBeString();
});

test('LoopResult::allToolCalls aggregates across iterations', function () {
    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Step 1', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
            makeResponse('Step 2', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 3, 'b' => 4]],
            ]),
            makeResponse('Done.'),
        ]);

    $result = $agent->reactLoop('Test');
    $allCalls = $result->allToolCalls();

    expect($allCalls)->toHaveCount(2);
    expect($allCalls[0]['tool'])->toBe('add_numbers');
    expect($allCalls[0]['iteration'])->toBe(1);
    expect($allCalls[1]['iteration'])->toBe(2);
});

// ─── LoopStep ───────────────────────────────────────────────────────

test('LoopStep identifies final answer vs tool call iterations', function () {
    $agent = makeAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            makeResponse('Calling tool', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 1]],
            ]),
            makeResponse('Final answer.'),
        ]);

    $result = $agent->reactLoop('Test');

    expect($result->steps[0]->hasToolCalls())->toBeTrue();
    expect($result->steps[0]->isFinalAnswer())->toBeFalse();
    expect($result->steps[0]->hasObservation())->toBeTrue();

    expect($result->steps[1]->hasToolCalls())->toBeFalse();
    expect($result->steps[1]->isFinalAnswer())->toBeTrue();
    expect($result->steps[1]->hasObservation())->toBeFalse();
});

// ─── AskHuman ────────────────────────────────────────────────────────

test('loop terminates immediately when LLM calls ask_human with a free-text question', function () {
    $agent = makeAgent()
        ->withTools([new AskHumanTool])
        ->fakeResponses([
            // LLM calls ask_human tool — no second iteration follows
            makeResponse('I need more information.', [
                ['name' => 'ask_human', 'arguments' => ['mode' => 'free_text', 'question' => 'What city should I check?']],
            ]),
            // This response must NOT be reached
            makeResponse('Final answer that should not appear.'),
        ]);

    $result = $agent->reactLoop('What is the weather?');

    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->askedHuman())->toBeTrue();
    expect($result->completed())->toBeFalse();
    expect($result->reachedMaxIterations)->toBeFalse();
    expect($result->iterations)->toBe(1);
    expect($agent->getPromptCallCount())->toBe(1);
});

test('askHumanSignal on result contains the free-text question', function () {
    $agent = makeAgent()
        ->withTools([new AskHumanTool])
        ->fakeResponses([
            makeResponse('Need input.', [
                ['name' => 'ask_human', 'arguments' => ['mode' => 'free_text', 'question' => 'What is your budget?']],
            ]),
        ]);

    $result = $agent->reactLoop('Help me plan.');

    expect($result->askHumanSignal)->toBeInstanceOf(AskHumanSignal::class);
    expect($result->askHumanSignal->isStructured())->toBeFalse();
    expect($result->askHumanSignal->getFreeTextQuestion())->toBe('What is your budget?');
});

test('askHumanSignal on result contains structured questions', function () {
    $agent = makeAgent()
        ->withTools([new AskHumanTool])
        ->fakeResponses([
            makeResponse('Need structured input.', [
                ['name' => 'ask_human', 'arguments' => [
                    'mode' => 'structured',
                    'questions' => [
                        ['type' => 'single_choice', 'question' => 'Timeline?', 'options' => ['1 week', '1 month', '3 months']],
                        ['type' => 'multiple_choice', 'question' => 'Features?', 'options' => ['Auth', 'Payments']],
                        ['type' => 'free_text', 'question' => 'Anything else?'],
                    ],
                ]],
            ]),
        ]);

    $result = $agent->reactLoop('Set up my project.');

    expect($result->askHumanSignal->isStructured())->toBeTrue();
    $questions = $result->askHumanSignal->getQuestions();
    expect($questions)->toHaveCount(3);
    expect($questions[0]->type)->toBe(QuestionType::SingleChoice);
    expect($questions[0]->options)->toBe(['1 week', '1 month', '3 months']);
    expect($questions[1]->type)->toBe(QuestionType::MultipleChoice);
    expect($questions[2]->type)->toBe(QuestionType::FreeText);
});

test('onAskHuman callback fires with the signal and iteration number', function () {
    $capturedSignal = null;
    $capturedIteration = null;

    $agent = makeAgent()
        ->withTools([new AskHumanTool])
        ->fakeResponses([
            makeResponse('Need input.', [
                ['name' => 'ask_human', 'arguments' => ['mode' => 'free_text', 'question' => 'What city?']],
            ]),
        ])
        ->onAskHuman(function (AskHumanSignal $signal, int $iteration) use (&$capturedSignal, &$capturedIteration) {
            $capturedSignal = $signal;
            $capturedIteration = $iteration;
        });

    $agent->reactLoop('Check weather.');

    expect($capturedSignal)->toBeInstanceOf(AskHumanSignal::class);
    expect($capturedSignal->getFreeTextQuestion())->toBe('What city?');
    expect($capturedIteration)->toBe(1);
});

test('onAskHuman fires after any regular tool calls in the same iteration', function () {
    $events = [];

    $agent = makeAgent()
        ->withTools([new AdditionTool, new AskHumanTool])
        ->fakeResponses([
            makeResponse('Adding then asking.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
                ['name' => 'ask_human', 'arguments' => ['mode' => 'free_text', 'question' => 'Continue?']],
            ]),
        ])
        ->onAfterAction(function (string $tool) use (&$events) {
            $events[] = "action:{$tool}";
        })
        ->onAskHuman(function () use (&$events) {
            $events[] = 'askHuman';
        });

    $result = $agent->reactLoop('Do stuff.');

    // add_numbers ran, then ask_human was detected, then callback fired
    expect($events)->toBe(['action:add_numbers', 'action:ask_human', 'askHuman']);
    expect($result->askedHuman())->toBeTrue();
});

test('loop does not call onLoopComplete when terminated by AskHuman', function () {
    $loopCompleted = false;

    $agent = makeAgent()
        ->withTools([new AskHumanTool])
        ->fakeResponses([
            makeResponse('Asking.', [
                ['name' => 'ask_human', 'arguments' => ['mode' => 'free_text', 'question' => 'What?']],
            ]),
        ])
        ->onLoopComplete(function () use (&$loopCompleted) {
            $loopCompleted = true;
        });

    $agent->reactLoop('Test.');

    expect($loopCompleted)->toBeFalse();
});

test('onAskHuman callback receives the AgentResponse as the third argument', function () {
    $capturedResponse = null;

    $agent = makeAgent()
        ->withTools([new AskHumanTool])
        ->fakeResponses([
            makeResponse('I need to ask something.', [
                ['name' => 'ask_human', 'arguments' => ['mode' => 'free_text', 'question' => 'What city?']],
            ]),
        ])
        ->onAskHuman(function (AskHumanSignal $signal, int $iteration, AgentResponse $response) use (&$capturedResponse) {
            $capturedResponse = $response;
        });

    $result = $agent->reactLoop('Check weather.');

    expect($capturedResponse)->toBeInstanceOf(AgentResponse::class)
        ->and($capturedResponse->text)->toBe('I need to ask something.')
        ->and($capturedResponse)->toBe($result->response);
});

test('askedHuman() is false for a normally completed loop', function () {
    $agent = makeAgent()->fakeResponses([
        makeResponse('Done.'),
    ]);

    $result = $agent->reactLoop('Hello.');

    expect($result->askedHuman())->toBeFalse();
    expect($result->askHumanSignal)->toBeNull();
    expect($result->completed())->toBeTrue();
});

test('AskHumanSignal toArray() returns expected structure for frontend use', function () {
    $agent = makeAgent()
        ->withTools([new AskHumanTool])
        ->fakeResponses([
            makeResponse('Asking.', [
                ['name' => 'ask_human', 'arguments' => [
                    'mode' => 'structured',
                    'questions' => [
                        ['type' => 'single_choice', 'question' => 'Size?', 'options' => ['S', 'M', 'L']],
                    ],
                ]],
            ]),
        ]);

    $result = $agent->reactLoop('Order something.');
    $arr = $result->askHumanSignal->toArray();

    expect($arr['mode'])->toBe('structured');
    expect($arr['questions'][0]['type'])->toBe('single_choice');
    expect($arr['questions'][0]['options'])->toBe(['S', 'M', 'L']);
});

// ─── PausesLoop ──────────────────────────────────────────────────────

test('loop terminates when a tool returns a PausesLoop result', function () {
    $agent = makeAgent()
        ->withTools([new PausingTool])
        ->fakeResponses([
            makeResponse('Launching widget.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'calculator']],
            ]),
            makeResponse('This should not be reached.'),
        ]);

    $result = $agent->reactLoop('Open the calculator.');

    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->paused())->toBeTrue();
    expect($result->completed())->toBeFalse();
    expect($result->askedHuman())->toBeFalse();
    expect($result->reachedMaxIterations)->toBeFalse();
    expect($result->iterations)->toBe(1);
    expect($agent->getPromptCallCount())->toBe(1);
});

test('pauseSignal on result contains the PausesLoop instance', function () {
    $agent = makeAgent()
        ->withTools([new PausingTool])
        ->fakeResponses([
            makeResponse('Launching.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'stamp_duty']],
            ]),
        ]);

    $result = $agent->reactLoop('Calculate stamp duty.');

    expect($result->pauseSignal)->toBeInstanceOf(PausesLoop::class);
    expect((string) $result->pauseSignal)->toContain('stamp_duty');
});

test('pausing tool breaks execution before subsequent tools and tracks deferred tools', function () {
    $executedTools = [];

    $agent = makeAgent()
        ->withTools([new PausingTool, new AdditionTool])
        ->fakeResponses([
            makeResponse('Need to do two things.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'calculator']],
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
        ])
        ->onAfterAction(function (string $tool) use (&$executedTools) {
            $executedTools[] = $tool;
        });

    $result = $agent->reactLoop('Do two things.');

    expect($executedTools)->toBe(['interactive_widget']);
    expect($result->paused())->toBeTrue();
    expect($result->iterations)->toBe(1);
    expect($result->steps[0]->toolCalls)->toHaveCount(1);
    expect($result->deferredTools)->toBe(['add_numbers']);
});

test('regular tools before a pausing tool still execute', function () {
    $executedTools = [];

    $agent = makeAgent()
        ->withTools([new AdditionTool, new PausingTool])
        ->fakeResponses([
            makeResponse('Add then launch widget.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 3]],
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'editor']],
            ]),
        ])
        ->onAfterAction(function (string $tool) use (&$executedTools) {
            $executedTools[] = $tool;
        });

    $result = $agent->reactLoop('Add and then open editor.');

    expect($executedTools)->toBe(['add_numbers', 'interactive_widget']);
    expect($result->paused())->toBeTrue();
    expect($result->steps[0]->toolCalls)->toHaveCount(2);
    expect($result->steps[0]->toolCalls[0]['result'])->toBe('8');
});

test('loop fires onPause callback instead of loopComplete when paused', function () {
    $loopCompleted = false;
    $pauseFired = false;
    $pausedDeferredTools = null;

    $agent = makeAgent()
        ->withTools([new PausingTool])
        ->fakeResponses([
            makeResponse('Launching.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'widget']],
            ]),
        ])
        ->onLoopComplete(function () use (&$loopCompleted) {
            $loopCompleted = true;
        })
        ->onPause(function (PausesLoop $signal, array $deferred) use (&$pauseFired, &$pausedDeferredTools) {
            $pauseFired = true;
            $pausedDeferredTools = $deferred;
        });

    $agent->reactLoop('Open widget.');

    expect($loopCompleted)->toBeFalse();
    expect($pauseFired)->toBeTrue();
    expect($pausedDeferredTools)->toBe([]);
});

test('pause observation contains deferred tool names', function () {
    $receivedObservation = null;

    $agent = makeAgent()
        ->withTools([new PausingTool, new AdditionTool])
        ->fakeResponses([
            makeResponse('Launch widget then add.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'calc']],
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
        ])
        ->onObservation(function (string $observation) use (&$receivedObservation) {
            $receivedObservation = $observation;
        });

    $result = $agent->reactLoop('Do both.');

    expect($receivedObservation)->not->toBeNull();
    expect($receivedObservation)->toContain('paused');
    expect($receivedObservation)->toContain('add_numbers');
    expect($receivedObservation)->toContain('NOT yet executed');
    expect($result->steps[0]->hasObservation())->toBeTrue();
});

test('pause observation is stored on the LoopStep', function () {
    $agent = makeAgent()
        ->withTools([new PausingTool])
        ->fakeResponses([
            makeResponse('Launching.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'widget']],
            ]),
        ]);

    $result = $agent->reactLoop('Open widget.');

    expect($result->steps[0]->hasObservation())->toBeTrue();
    expect($result->steps[0]->observation)->toContain('paused');
    expect($result->steps[0]->observation)->toContain('interactive_widget');
});

test('deferredTools is empty when the pausing tool is the only tool', function () {
    $agent = makeAgent()
        ->withTools([new PausingTool])
        ->fakeResponses([
            makeResponse('Launching.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'widget']],
            ]),
        ]);

    $result = $agent->reactLoop('Open widget.');

    expect($result->deferredTools)->toBe([]);
});

test('onPause callback receives deferred tool names', function () {
    $capturedDeferred = null;
    $capturedSignal = null;

    $agent = makeAgent()
        ->withTools([new PausingTool, new AdditionTool])
        ->fakeResponses([
            makeResponse('Two things.', [
                ['name' => 'interactive_widget', 'arguments' => ['name' => 'calc']],
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
        ])
        ->onPause(function (PausesLoop $signal, array $deferred) use (&$capturedSignal, &$capturedDeferred) {
            $capturedSignal = $signal;
            $capturedDeferred = $deferred;
        });

    $agent->reactLoop('Do both.');

    expect($capturedSignal)->toBeInstanceOf(PausesLoop::class);
    expect($capturedDeferred)->toBe(['add_numbers']);
});

test('paused() is false for a normally completed loop', function () {
    $agent = makeAgent()->fakeResponses([
        makeResponse('Done.'),
    ]);

    $result = $agent->reactLoop('Hello.');

    expect($result->paused())->toBeFalse();
    expect($result->pauseSignal)->toBeNull();
    expect($result->completed())->toBeTrue();
});
