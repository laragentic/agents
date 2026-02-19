<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Exceptions\MaxStepsExceededException;
use Laragentic\Loops\PlanResult;
use Laragentic\Loops\PlanStep;
use Laragentic\Signals\AskHumanSignal;
use Laragentic\Signals\QuestionType;
use Laragentic\Tests\Fixtures\PlanTestAgent;

// ─── Helpers ────────────────────────────────────────────────────────

function makePlanResponse(string $text): AgentResponse
{
    return new AgentResponse(
        invocationId: 'inv-' . uniqid(),
        text: $text,
        usage: new Usage(promptTokens: 10, completionTokens: 20),
        meta: new Meta(provider: 'test', model: 'test-model'),
    );
}

/**
 * Build a plan step response that includes an SDK-resolved ask_human tool result.
 *
 * PlanExecuteLoop uses prompt() (SDK-resolved tool calls), so signals
 * arrive in toolResults rather than being executed by our loop directly.
 */
function makePlanResponseWithAskHuman(string $text, AskHumanSignal $signal): AgentResponse
{
    $response = makePlanResponse($text);

    $toolCall = new ToolCall(
        id: 'tc-ask-' . uniqid(),
        name: 'ask_human',
        arguments: [],
    );

    $toolResult = new ToolResult(
        id: 'tr-ask-' . uniqid(),
        name: 'ask_human',
        arguments: [],
        result: (string) $signal,
    );

    $response->toolCalls = new Collection([$toolCall]);
    $response->toolResults = new Collection([$toolResult]);

    return $response;
}

function makePlanAgent(): PlanTestAgent
{
    return new PlanTestAgent;
}

// ─── Plan → Execute → Synthesize Flow ───────────────────────────────

test('basic plan-execute-synthesize flow with 2 steps', function () {
    $agent = makePlanAgent()->fakeResponses([
        // Planning response
        makePlanResponse("1. Research the topic\n2. Write the summary"),
        // Step 1 execution
        makePlanResponse('I researched the topic and found key facts A, B, C.'),
        // Step 2 execution
        makePlanResponse('Here is the summary based on the research.'),
        // Synthesis
        makePlanResponse('Final comprehensive answer combining research and summary.'),
    ]);

    $result = $agent->planExecute('Research and summarize AI trends');

    expect($result)->toBeInstanceOf(PlanResult::class);
    expect($result->completed())->toBeTrue();
    expect($result->text())->toBe('Final comprehensive answer combining research and summary.');
    expect($result->plan)->toBe(['Research the topic', 'Write the summary']);
    expect($result->steps)->toHaveCount(2);
    expect($result->stepsExecuted())->toBe(2);
    expect($result->totalPlannedSteps())->toBe(2);
    expect($result->wasReplanned())->toBeFalse();
    expect($agent->getPromptCallCount())->toBe(4); // plan + 2 steps + synthesis
});

test('plan with 3 steps calls prompt correct number of times', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. Step one\n2. Step two\n3. Step three"),
        makePlanResponse('Result of step 1.'),
        makePlanResponse('Result of step 2.'),
        makePlanResponse('Result of step 3.'),
        makePlanResponse('Synthesized final answer.'),
    ]);

    $result = $agent->planExecute('Do three things');

    expect($agent->getPromptCallCount())->toBe(5); // plan + 3 steps + synthesis
    expect($result->steps)->toHaveCount(3);
    expect($result->plan)->toHaveCount(3);
});

test('single-step plan works correctly', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse('1. Do the thing'),
        makePlanResponse('I did the thing.'),
        makePlanResponse('Final: the thing is done.'),
    ]);

    $result = $agent->planExecute('Do one thing');

    expect($result->plan)->toHaveCount(1);
    expect($result->steps)->toHaveCount(1);
    expect($result->stepsExecuted())->toBe(1);
    expect($agent->getPromptCallCount())->toBe(3);
});

test('unparseable plan is treated as a single step', function () {
    $agent = makePlanAgent()->fakeResponses([
        // No numbered list — just prose
        makePlanResponse('I will research the topic thoroughly and write up findings.'),
        // Step 1 execution (the whole plan text becomes the step)
        makePlanResponse('Here are my findings.'),
        // Synthesis
        makePlanResponse('Final answer.'),
    ]);

    $result = $agent->planExecute('Research something');

    expect($result->plan)->toHaveCount(1);
    expect($result->steps)->toHaveCount(1);
    expect($result->completed())->toBeTrue();
});

// ─── PlanStep Value Object ──────────────────────────────────────────

test('PlanStep tracks execution state', function () {
    $unexecuted = new PlanStep(number: 1, description: 'Do something');
    expect($unexecuted->isExecuted())->toBeFalse();
    expect($unexecuted->result())->toBeNull();

    $response = makePlanResponse('Done!');
    $executed = $unexecuted->withResponse($response);

    expect($executed->isExecuted())->toBeTrue();
    expect($executed->result())->toBe('Done!');
    expect($executed->number)->toBe(1);
    expect($executed->description)->toBe('Do something');
    expect($executed->triggeredReplan)->toBeFalse();
});

test('PlanStep tracks replan trigger', function () {
    $step = new PlanStep(number: 2, description: 'Failing step');
    $failed = $step->withResponse(makePlanResponse('Error: failed'), triggeredReplan: true);

    expect($failed->triggeredReplan)->toBeTrue();
});

// ─── PlanResult Value Object ────────────────────────────────────────

test('PlanResult delegates property access to AgentResponse', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse('1. Do it'),
        makePlanResponse('Did it.'),
        makePlanResponse('Final answer text.'),
    ]);

    $result = $agent->planExecute('Test');

    expect($result->text())->toBe('Final answer text.');
    expect((string) $result)->toBe('Final answer text.');
    expect($result->invocationId)->toBeString();
});

test('PlanResult::stepResults returns structured data', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. First\n2. Second"),
        makePlanResponse('Result A'),
        makePlanResponse('Result B'),
        makePlanResponse('Final'),
    ]);

    $result = $agent->planExecute('Test');
    $stepResults = $result->stepResults();

    expect($stepResults)->toHaveCount(2);
    expect($stepResults[0]['step'])->toBe(1);
    expect($stepResults[0]['description'])->toBe('First');
    expect($stepResults[0]['result'])->toBe('Result A');
    expect($stepResults[1]['step'])->toBe(2);
    expect($stepResults[1]['description'])->toBe('Second');
    expect($stepResults[1]['result'])->toBe('Result B');
});

// ─── Step Execution Context ─────────────────────────────────────────

test('step execution prompt includes previous step results', function () {
    $promptsSent = [];

    $agent = makePlanAgent()
        ->fakeResponses([
            makePlanResponse("1. Research\n2. Analyze\n3. Report"),
            makePlanResponse('Research findings: X, Y, Z'),
            makePlanResponse('Analysis: X is most important'),
            makePlanResponse('Report complete'),
            makePlanResponse('Final synthesis'),
        ]);

    // Capture all prompts
    $originalPrompt = (new ReflectionClass($agent))->getMethod('prompt');
    // We'll use onBeforeStep to inspect what's happening
    $agent->onBeforeStep(function (int $step, string $desc, int $total) use (&$promptsSent) {
        $promptsSent[] = ['step' => $step, 'description' => $desc, 'total' => $total];
    });

    $result = $agent->planExecute('Research task');

    expect($promptsSent)->toHaveCount(3);
    expect($promptsSent[0]['step'])->toBe(1);
    expect($promptsSent[0]['total'])->toBe(3);
    expect($promptsSent[2]['step'])->toBe(3);
});

// ─── Plan Parsing ───────────────────────────────────────────────────

test('parses numbered plans with period format', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("Here's my plan:\n1. First step\n2. Second step\n3. Third step"),
        makePlanResponse('Step 1 done'),
        makePlanResponse('Step 2 done'),
        makePlanResponse('Step 3 done'),
        makePlanResponse('Synthesized'),
    ]);

    $result = $agent->planExecute('Test');
    expect($result->plan)->toBe(['First step', 'Second step', 'Third step']);
});

test('parses numbered plans with parenthesis format', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1) Gather data\n2) Process data\n3) Present results"),
        makePlanResponse('Gathered'),
        makePlanResponse('Processed'),
        makePlanResponse('Presented'),
        makePlanResponse('Final'),
    ]);

    $result = $agent->planExecute('Test');
    expect($result->plan)->toBe(['Gather data', 'Process data', 'Present results']);
});

test('parses numbered plans with mixed formatting', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("I'll create a plan:\n\n1. Research the market\n2. Identify competitors\n3. Draft strategy\n\nLet me begin."),
        makePlanResponse('R1'),
        makePlanResponse('R2'),
        makePlanResponse('R3'),
        makePlanResponse('Final'),
    ]);

    $result = $agent->planExecute('Test');
    expect($result->plan)->toBe(['Research the market', 'Identify competitors', 'Draft strategy']);
});

// ─── Max Steps ──────────────────────────────────────────────────────

test('stops at max steps and returns partial result', function () {
    $agent = makePlanAgent()
        ->maxSteps(2)
        ->fakeResponses([
            makePlanResponse("1. Step A\n2. Step B\n3. Step C\n4. Step D"),
            makePlanResponse('Result A'),
            makePlanResponse('Result B'),
            // Steps C and D should not execute — no synthesis either
        ]);

    $result = $agent->planExecute('Many steps');

    // Plan retains all parsed steps
    expect($result->plan)->toHaveCount(4);
    // Only 2 were executed
    expect($result->stepsExecuted())->toBe(2);
    expect($result->reachedMaxSteps)->toBeTrue();
    expect($result->completed())->toBeFalse();
});

test('throws MaxStepsExceededException when configured', function () {
    config()->set('agentic.plan_execute.throw_on_max_steps', true);
    config()->set('agentic.plan_execute.allow_replan', false);

    $agent = makePlanAgent()
        ->maxSteps(1)
        ->allowReplan(false)
        ->fakeResponses([
            makePlanResponse("1. Step A\n2. Step B"),
            makePlanResponse('Result A'),
        ]);

    $agent->planExecute('Test');
})->throws(MaxStepsExceededException::class, 'maximum of 1 steps');

test('fluent maxSteps overrides config default', function () {
    config()->set('agentic.plan_execute.max_steps', 100);

    $agent = makePlanAgent()
        ->maxSteps(2)
        ->fakeResponses([
            makePlanResponse("1. A\n2. B\n3. C"),
            makePlanResponse('RA'),
            makePlanResponse('RB'),
        ]);

    $result = $agent->planExecute('Test');

    expect($result->plan)->toHaveCount(3); // Full plan preserved
    expect($result->stepsExecuted())->toBe(2); // But only 2 executed
    expect($result->reachedMaxSteps)->toBeTrue();
});

// ─── Replanning ─────────────────────────────────────────────────────

test('replans when a step fails and replanning is enabled', function () {
    $replanEvents = [];

    $agent = makePlanAgent()
        ->allowReplan()
        ->maxReplans(1)
        ->fakeResponses([
            // Plan
            makePlanResponse("1. Try approach A\n2. Finalize"),
            // Step 1 fails (contains "error")
            makePlanResponse('Error: approach A is not available.'),
            // Replan prompt
            makePlanResponse("1. Try approach B\n2. Finalize with B"),
            // Execute revised step 1
            makePlanResponse('Approach B succeeded.'),
            // Execute revised step 2
            makePlanResponse('Finalized with B.'),
            // Synthesis
            makePlanResponse('Final answer using approach B.'),
        ])
        ->onReplan(function (array $newSteps, int $count) use (&$replanEvents) {
            $replanEvents[] = ['steps' => $newSteps, 'count' => $count];
        });

    $result = $agent->planExecute('Complete the task');

    expect($result->wasReplanned())->toBeTrue();
    expect($result->replans)->toBe(1);
    expect($replanEvents)->toHaveCount(1);
    expect($replanEvents[0]['count'])->toBe(1);
    expect($result->completed())->toBeTrue();
});

test('does not replan when replanning is disabled', function () {
    $agent = makePlanAgent()
        ->allowReplan(false)
        ->fakeResponses([
            makePlanResponse("1. Try something\n2. Done"),
            makePlanResponse('Error: this failed.'), // Would trigger replan
            makePlanResponse('Moved on anyway.'),
            makePlanResponse('Synthesis.'),
        ]);

    $result = $agent->planExecute('Test');

    expect($result->wasReplanned())->toBeFalse();
    expect($result->replans)->toBe(0);
    // The "error" step is still recorded
    expect($result->steps[0]->result())->toContain('Error');
});

test('respects max replan limit', function () {
    $replanCount = 0;

    $agent = makePlanAgent()
        ->allowReplan()
        ->maxReplans(1)
        ->fakeResponses([
            // Original plan
            makePlanResponse("1. Step A"),
            // Step A fails
            makePlanResponse('Error: step A failed.'),
            // Replan (1st replan)
            makePlanResponse("1. Step B"),
            // Step B also fails
            makePlanResponse('Error: step B also failed.'),
            // No more replans — maxReplans(1) reached
            // Synthesis with what we have
            makePlanResponse('Best effort synthesis.'),
        ])
        ->onReplan(function () use (&$replanCount) {
            $replanCount++;
        });

    $result = $agent->planExecute('Test');

    expect($replanCount)->toBe(1); // Only 1 replan allowed
    expect($result->replans)->toBe(1);
});

// ─── Callbacks ──────────────────────────────────────────────────────

test('fires all lifecycle callbacks in correct order', function () {
    $events = [];

    $agent = makePlanAgent()
        ->allowReplan(false)
        ->fakeResponses([
            makePlanResponse("1. Step one\n2. Step two"),
            makePlanResponse('Result 1'),
            makePlanResponse('Result 2'),
            makePlanResponse('Final answer'),
        ])
        ->onLoopStart(function () use (&$events) {
            $events[] = 'loopStart';
        })
        ->onPlanCreated(function () use (&$events) {
            $events[] = 'planCreated';
        })
        ->onBeforeStep(function (int $n) use (&$events) {
            $events[] = "beforeStep:{$n}";
        })
        ->onAfterStep(function (int $n) use (&$events) {
            $events[] = "afterStep:{$n}";
        })
        ->onBeforeSynthesis(function () use (&$events) {
            $events[] = 'beforeSynthesis';
        })
        ->onAfterSynthesis(function () use (&$events) {
            $events[] = 'afterSynthesis';
        })
        ->onLoopComplete(function () use (&$events) {
            $events[] = 'loopComplete';
        });

    $agent->planExecute('Test');

    expect($events)->toBe([
        'loopStart',
        'planCreated',
        'beforeStep:1',
        'afterStep:1',
        'beforeStep:2',
        'afterStep:2',
        'beforeSynthesis',
        'afterSynthesis',
        'loopComplete',
    ]);
});

test('onPlanCreated receives the parsed step descriptions', function () {
    $receivedPlan = null;

    $agent = makePlanAgent()
        ->fakeResponses([
            makePlanResponse("1. Alpha\n2. Beta\n3. Gamma"),
            makePlanResponse('A'),
            makePlanResponse('B'),
            makePlanResponse('C'),
            makePlanResponse('Final'),
        ])
        ->onPlanCreated(function (array $steps) use (&$receivedPlan) {
            $receivedPlan = $steps;
        });

    $agent->planExecute('Test');

    expect($receivedPlan)->toBe(['Alpha', 'Beta', 'Gamma']);
});

test('onBeforeStep and onAfterStep receive correct arguments', function () {
    $beforeArgs = [];
    $afterArgs = [];

    $agent = makePlanAgent()
        ->fakeResponses([
            makePlanResponse("1. First\n2. Second"),
            makePlanResponse('Result 1'),
            makePlanResponse('Result 2'),
            makePlanResponse('Final'),
        ])
        ->onBeforeStep(function (int $num, string $desc, int $total) use (&$beforeArgs) {
            $beforeArgs[] = compact('num', 'desc', 'total');
        })
        ->onAfterStep(function (int $num, string $desc, AgentResponse $response, int $total) use (&$afterArgs) {
            $afterArgs[] = ['num' => $num, 'desc' => $desc, 'text' => $response->text, 'total' => $total];
        });

    $agent->planExecute('Test');

    expect($beforeArgs)->toHaveCount(2);
    expect($beforeArgs[0])->toBe(['num' => 1, 'desc' => 'First', 'total' => 2]);
    expect($beforeArgs[1])->toBe(['num' => 2, 'desc' => 'Second', 'total' => 2]);

    expect($afterArgs)->toHaveCount(2);
    expect($afterArgs[0]['text'])->toBe('Result 1');
    expect($afterArgs[1]['text'])->toBe('Result 2');
});

test('onBeforeSynthesis receives executed steps', function () {
    $receivedSteps = null;

    $agent = makePlanAgent()
        ->fakeResponses([
            makePlanResponse("1. Do it"),
            makePlanResponse('Done!'),
            makePlanResponse('Final'),
        ])
        ->onBeforeSynthesis(function (array $steps) use (&$receivedSteps) {
            $receivedSteps = $steps;
        });

    $agent->planExecute('Test');

    expect($receivedSteps)->toHaveCount(1);
    expect($receivedSteps[0])->toBeInstanceOf(PlanStep::class);
    expect($receivedSteps[0]->result())->toBe('Done!');
});

test('onMaxStepsReached fires when limit is hit', function () {
    $reached = false;
    $receivedCount = null;

    $agent = makePlanAgent()
        ->maxSteps(1)
        ->allowReplan(false)
        ->fakeResponses([
            makePlanResponse("1. A\n2. B"),
            makePlanResponse('Result A'),
            // No synthesis — max steps stops the loop
        ])
        ->onMaxStepsReached(function ($response, $count) use (&$reached, &$receivedCount) {
            $reached = true;
            $receivedCount = $count;
        });

    $agent->planExecute('Test');

    expect($reached)->toBeTrue();
    expect($receivedCount)->toBe(1);
});

test('callbacks work with zero registered', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse('1. Step'),
        makePlanResponse('Done'),
        makePlanResponse('Final'),
    ]);

    $result = $agent->planExecute('Test');

    expect($result->completed())->toBeTrue();
});

// ─── Configuration ──────────────────────────────────────────────────

test('resolves max steps from config', function () {
    config()->set('agentic.plan_execute.max_steps', 2);

    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. A\n2. B\n3. C\n4. D"),
        makePlanResponse('RA'),
        makePlanResponse('RB'),
    ]);

    $result = $agent->planExecute('Test');

    expect($result->plan)->toHaveCount(4); // Full plan preserved
    expect($result->stepsExecuted())->toBe(2); // Only 2 executed
    expect($result->reachedMaxSteps)->toBeTrue();
});

test('resolves allow_replan from config', function () {
    config()->set('agentic.plan_execute.allow_replan', false);

    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. Failing step"),
        makePlanResponse('Error: failed'),
        makePlanResponse('Synthesis'),
    ]);

    $result = $agent->planExecute('Test');

    expect($result->wasReplanned())->toBeFalse();
});

test('resolves max_replans from config', function () {
    config()->set('agentic.plan_execute.max_replans', 0);

    $agent = makePlanAgent()
        ->allowReplan()
        ->fakeResponses([
            makePlanResponse("1. Fail"),
            makePlanResponse('Error: nope'),
            makePlanResponse('Synthesis'),
        ]);

    $result = $agent->planExecute('Test');

    // max_replans=0 means no replanning even though allowReplan is true
    expect($result->wasReplanned())->toBeFalse();
});

// ─── Provider / Model Pass-Through ──────────────────────────────────

test('passes provider and model to prompt calls', function () {
    $prompts = [];

    // Create a custom agent that captures prompt args
    $agent = new class extends PlanTestAgent
    {
        public array $capturedArgs = [];

        public function prompt(
            string $prompt,
            array $attachments = [],
            mixed $provider = null,
            ?string $model = null,
            ?int $timeout = null,
        ): AgentResponse {
            $this->capturedArgs[] = compact('provider', 'model', 'timeout');

            return parent::prompt($prompt, $attachments, $provider, $model, $timeout);
        }
    };

    $agent->fakeResponses([
        makePlanResponse('1. Step'),
        makePlanResponse('Done'),
        makePlanResponse('Final'),
    ]);

    $agent->planExecute('Test', provider: 'anthropic', model: 'claude-haiku', timeout: 30);

    // All prompt calls should have the same provider/model/timeout
    foreach ($agent->capturedArgs as $args) {
        expect($args['provider'])->toBe('anthropic');
        expect($args['model'])->toBe('claude-haiku');
        expect($args['timeout'])->toBe(30);
    }
});

// ─── AskHuman ────────────────────────────────────────────────────────

test('PlanExecuteLoop terminates when a step response contains an ask_human tool result', function () {
    $signal = AskHumanSignal::freeText('What format should the report be in?');

    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. Research\n2. Write report"),
        // Step 1 execution returns an AskHumanSignal in toolResults
        makePlanResponseWithAskHuman('I need clarification.', $signal),
        // Step 2 and synthesis must NOT be reached
        makePlanResponse('Report content.'),
        makePlanResponse('Final synthesis.'),
    ]);

    $result = $agent->planExecute('Research and write a report');

    expect($result)->toBeInstanceOf(PlanResult::class);
    expect($result->askedHuman())->toBeTrue();
    expect($result->completed())->toBeFalse();
    expect($result->reachedMaxSteps)->toBeFalse();
    // Only plan + step 1 were called (step 2 and synthesis skipped)
    expect($agent->getPromptCallCount())->toBe(2);
});

test('PlanExecuteLoop askHumanSignal contains the free-text question', function () {
    $signal = AskHumanSignal::freeText('Which database should I use?');

    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. Design schema\n2. Implement"),
        makePlanResponseWithAskHuman('Need more info.', $signal),
    ]);

    $result = $agent->planExecute('Build the data layer');

    expect($result->askHumanSignal)->toBeInstanceOf(AskHumanSignal::class);
    expect($result->askHumanSignal->isStructured())->toBeFalse();
    expect($result->askHumanSignal->getFreeTextQuestion())->toBe('Which database should I use?');
});

test('PlanExecuteLoop askHumanSignal contains structured questions', function () {
    $signal = AskHumanSignal::structured([
        \Laragentic\Signals\HumanQuestion::singleChoice('Language?', ['PHP', 'Python', 'Go']),
        \Laragentic\Signals\HumanQuestion::multipleChoice('Features?', ['Auth', 'API', 'Queue']),
    ]);

    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. Choose stack\n2. Scaffold"),
        makePlanResponseWithAskHuman('Need preferences.', $signal),
    ]);

    $result = $agent->planExecute('Bootstrap a new service');

    expect($result->askHumanSignal->isStructured())->toBeTrue();
    $questions = $result->askHumanSignal->getQuestions();
    expect($questions)->toHaveCount(2);
    expect($questions[0]->type)->toBe(QuestionType::SingleChoice);
    expect($questions[0]->options)->toBe(['PHP', 'Python', 'Go']);
    expect($questions[1]->type)->toBe(QuestionType::MultipleChoice);
});

test('PlanExecuteLoop onAskHuman callback fires with signal and step number', function () {
    $capturedSignal = null;
    $capturedStep = null;

    $signal = AskHumanSignal::freeText('Confirm budget?');

    $agent = makePlanAgent()
        ->fakeResponses([
            makePlanResponse("1. Estimate costs"),
            makePlanResponseWithAskHuman('Asking.', $signal),
        ])
        ->onAskHuman(function (AskHumanSignal $s, int $step) use (&$capturedSignal, &$capturedStep) {
            $capturedSignal = $s;
            $capturedStep = $step;
        });

    $agent->planExecute('Plan the project budget');

    expect($capturedSignal)->toBeInstanceOf(AskHumanSignal::class);
    expect($capturedStep)->toBe(1);
});

test('PlanExecuteLoop askedHuman() is false for a normally completed result', function () {
    $agent = makePlanAgent()->fakeResponses([
        makePlanResponse("1. Do the work"),
        makePlanResponse('Work done.'),
        makePlanResponse('Final answer.'),
    ]);

    $result = $agent->planExecute('Simple task');

    expect($result->askedHuman())->toBeFalse();
    expect($result->askHumanSignal)->toBeNull();
    expect($result->completed())->toBeTrue();
});
