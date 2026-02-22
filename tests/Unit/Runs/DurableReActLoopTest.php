<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Exceptions\RunCancelledException;
use Laragentic\Exceptions\RunNotFoundException;
use Laragentic\Loops\LoopResult;
use Laragentic\Runs\AgentCheckpoint;
use Laragentic\Runs\AgentRun;
use Laragentic\Runs\AgentToolCall;
use Laragentic\Runs\RunStatus;
use Laragentic\Tests\Fixtures\AdditionTool;
use Laragentic\Tests\Fixtures\DurableTestAgent;

// ─── Setup: run the real package migrations ──────────────────────────

beforeEach(function () {
    $migrations = glob(__DIR__ . '/../../../database/migrations/*.php');
    sort($migrations);
    foreach ($migrations as $file) {
        (require $file)->up();
    }
});

afterEach(function () {
    Schema::dropIfExists('agent_tool_calls');
    Schema::dropIfExists('agent_checkpoints');
    Schema::dropIfExists('agent_runs');
});

// ─── Helpers ────────────────────────────────────────────────────────

function durableResponse(string $text, array $toolCalls = []): AgentResponse
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

function durableAgent(): DurableTestAgent
{
    return new DurableTestAgent;
}

// ─── Run Creation ────────────────────────────────────────────────────

test('creates a new AgentRun record on first execution', function () {
    $agent = durableAgent()->fakeResponses([
        durableResponse('Done!'),
    ]);

    $agent->durableReactLoop('Do something', runId: 'run-001');

    $run = AgentRun::where('run_id', 'run-001')->first();

    expect($run)->not->toBeNull();
    expect($run->run_id)->toBe('run-001');
    expect($run->initial_prompt)->toBe('Do something');
    expect($run->status)->toBe(RunStatus::Completed);
    expect($run->loop_type)->toBe('react');
});

test('auto-generates a run_id when none is supplied', function () {
    $agent = durableAgent()->fakeResponses([
        durableResponse('Done!'),
    ]);

    $result = $agent->durableReactLoop('Auto ID test');

    expect(AgentRun::count())->toBe(1);
    expect(AgentRun::first()->run_id)->not->toBeEmpty();
});

test('run is marked completed with final response text', function () {
    $agent = durableAgent()->fakeResponses([
        durableResponse('The answer is 42.'),
    ]);

    $agent->durableReactLoop('What is the answer?', runId: 'run-002');

    $run = AgentRun::where('run_id', 'run-002')->first();

    expect($run->status)->toBe(RunStatus::Completed);
    expect($run->final_response)->toBe('The answer is 42.');
    expect($run->completed_at)->not->toBeNull();
});

// ─── Checkpointing ───────────────────────────────────────────────────

test('saves a checkpoint after each iteration', function () {
    $agent = durableAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            durableResponse('Calling add.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 3, 'b' => 4]],
            ]),
            durableResponse('The sum is 7.'),
        ]);

    $agent->durableReactLoop('Add 3 and 4', runId: 'run-ckpt');

    $checkpoints = AgentCheckpoint::where('run_id', 'run-ckpt')
        ->orderBy('iteration')
        ->get();

    // Two iterations: one with tool call, one with final answer
    expect($checkpoints->count())->toBe(2);

    $first = $checkpoints->first();
    expect($first->iteration)->toBe(1);
    expect($first->prompt)->toBe('Add 3 and 4');
    expect($first->tool_calls)->not->toBeNull();
    expect($first->observation)->not->toBeNull();
    expect($first->next_prompt)->not->toBeNull();

    $second = $checkpoints->last();
    expect($second->iteration)->toBe(2);
    expect($second->response_text)->toBe('The sum is 7.');
});

test('checkpoint stores tool call records', function () {
    $agent = durableAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            durableResponse('Adding.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
            durableResponse('Result is 3.'),
        ]);

    $agent->durableReactLoop('Compute', runId: 'run-tc');

    $checkpoint = AgentCheckpoint::where(['run_id' => 'run-tc', 'iteration' => 1])->first();

    expect($checkpoint->tool_calls)->toBeArray();
    expect($checkpoint->tool_calls[0]['tool'])->toBe('add_numbers');
    expect($checkpoint->tool_calls[0]['result'])->toBe('3');
});

// ─── Resume ──────────────────────────────────────────────────────────

test('resumes from last checkpoint when run already has checkpoints', function () {
    // Seed a run at iteration 1 (paused mid-loop)
    $run = AgentRun::create([
        'run_id'         => 'run-resume',
        'agent_class'    => DurableTestAgent::class,
        'loop_type'      => 'react',
        'status'         => RunStatus::Paused,
        'initial_prompt' => 'Original prompt',
    ]);

    AgentCheckpoint::create([
        'run_id'        => 'run-resume',
        'iteration'     => 1,
        'prompt'        => 'Original prompt',
        'response_text' => 'I called a tool.',
        'tool_calls'    => [['tool' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2], 'result' => '3']],
        'observation'   => 'Observation: [add_numbers]: 3',
        'next_prompt'   => 'Observation: [add_numbers]: 3\n\nBased on...',
    ]);

    // Resume: agent should start from iteration 2
    $agent = durableAgent()->fakeResponses([
        durableResponse('Resumed and finished.'),
    ]);

    $result = $agent->durableReactLoop('Original prompt', runId: 'run-resume');

    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->completed())->toBeTrue();
    // Only ONE prompt call because we resumed from checkpoint
    expect($agent->getPromptCallCount())->toBe(1);

    // Run should now be completed
    $run->refresh();
    expect($run->status)->toBe(RunStatus::Completed);
});

test('humanReply is appended to the resumed prompt when provided', function () {
    AgentRun::create([
        'run_id'         => 'run-human',
        'agent_class'    => DurableTestAgent::class,
        'loop_type'      => 'react',
        'status'         => RunStatus::Paused,
        'initial_prompt' => 'Original question',
    ]);

    AgentCheckpoint::create([
        'run_id'        => 'run-human',
        'iteration'     => 1,
        'prompt'        => 'Original question',
        'response_text' => 'Awaiting human.',
        'next_prompt'   => 'What colour is the sky?',
    ]);

    $receivedPrompt = null;

    $agent = durableAgent()->fakeResponses([
        durableResponse('The sky is blue.'),
    ]);

    $agent->onBeforeThought(function (string $prompt) use (&$receivedPrompt) {
        $receivedPrompt = $prompt;
    });

    $agent->durableReactLoop(
        'Original question',
        runId: 'run-human',
        humanReply: 'Blue.',
    );

    expect($receivedPrompt)->toContain('What colour is the sky?');
    expect($receivedPrompt)->toContain('Human reply: Blue.');
});

test('returning to a completed run throws', function () {
    AgentRun::create([
        'run_id'         => 'run-done',
        'agent_class'    => DurableTestAgent::class,
        'loop_type'      => 'react',
        'status'         => RunStatus::Completed,
        'initial_prompt' => 'done',
        'final_response' => 'Already done.',
    ]);

    $agent = durableAgent()->fakeResponses([durableResponse('Ignored.')]);

    expect(fn () => $agent->durableReactLoop('done', runId: 'run-done'))
        ->toThrow(RuntimeException::class, 'terminal');
});

// ─── Cancellation ────────────────────────────────────────────────────

test('throws RunCancelledException when run is cancelled between iterations', function () {
    // The agent makes two prompt calls: first returns a tool call (iteration 1),
    // second would return a final answer (iteration 2 — but should be cancelled first).
    $agent = durableAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            durableResponse('Calling add.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 1, 'b' => 2]],
            ]),
            durableResponse('Final answer.'),
        ]);

    // After iteration 1's observation fires (between iterations), mark the run cancelled.
    // The poll at the top of iteration 2 will then detect Cancelled and throw.
    $agent->onObservation(function () {
        AgentRun::where('run_id', 'run-cancel')->update([
            'status'       => RunStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);
    });

    expect(fn () => $agent->durableReactLoop('Should be cancelled', runId: 'run-cancel'))
        ->toThrow(RunCancelledException::class);
});

test('cancelRun marks a run as cancelled in the database', function () {
    AgentRun::create([
        'run_id'         => 'run-to-cancel',
        'agent_class'    => DurableTestAgent::class,
        'loop_type'      => 'react',
        'status'         => RunStatus::Running,
        'initial_prompt' => 'Running…',
        'started_at'     => now(),
    ]);

    DurableTestAgent::cancelRun('run-to-cancel');

    $run = AgentRun::where('run_id', 'run-to-cancel')->first();

    expect($run->status)->toBe(RunStatus::Cancelled);
    expect($run->cancelled_at)->not->toBeNull();
});

test('cancelRun throws RunNotFoundException for unknown run', function () {
    expect(fn () => DurableTestAgent::cancelRun('nonexistent'))
        ->toThrow(RunNotFoundException::class);
});

// ─── Idempotency ─────────────────────────────────────────────────────

test('stores tool call result with idempotency key', function () {
    $agent = durableAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            durableResponse('Adding.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 5, 'b' => 6]],
            ]),
            durableResponse('Sum is 11.'),
        ]);

    $agent->durableReactLoop('Add 5 and 6', runId: 'run-idempotent');

    expect(AgentToolCall::where('run_id', 'run-idempotent')->count())->toBe(1);

    $record = AgentToolCall::where('run_id', 'run-idempotent')->first();
    expect($record->tool_name)->toBe('add_numbers');
    expect($record->result)->toBe('11');
    expect($record->idempotency_key)->not->toBeEmpty();
});

test('replays stored tool call result on resume without re-executing', function () {
    $executionCount = 0;

    // Pre-seed a run and its idempotency record for iteration 1
    $run = AgentRun::create([
        'run_id'         => 'run-replay',
        'agent_class'    => DurableTestAgent::class,
        'loop_type'      => 'react',
        'status'         => RunStatus::Running,
        'initial_prompt' => 'Add numbers',
        'started_at'     => now(),
    ]);

    // Generate the same key the trait would generate
    $arguments = ['a' => 2, 'b' => 3];
    ksort($arguments);
    $payload = 'run-replay:1:add_numbers:' . json_encode($arguments);
    $key = hash('sha256', $payload);

    AgentToolCall::create([
        'run_id'          => 'run-replay',
        'idempotency_key' => $key,
        'iteration'       => 1,
        'tool_name'       => 'add_numbers',
        'arguments'       => $arguments,
        'result'          => '5',  // pre-stored result
    ]);

    AgentCheckpoint::create([
        'run_id'        => 'run-replay',
        'iteration'     => 0,  // no real prior iteration, just test scaffold
        'prompt'        => 'Add numbers',
        'response_text' => '',
        'next_prompt'   => 'Add numbers',
    ]);

    // The agent provides a tool call for iteration 1 — but the tool itself
    // should NOT execute because there's an idempotency record.
    // We track this by checking that afterAction was NOT called.
    $afterActionFired = false;

    $agent = durableAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            durableResponse('Now adding.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 2, 'b' => 3]],
            ]),
            durableResponse('Done.'),
        ]);

    $agent->onAfterAction(function () use (&$afterActionFired) {
        $afterActionFired = true;
    });

    $result = $agent->durableReactLoop('Add numbers', runId: 'run-replay');

    expect($result->completed())->toBeTrue();
    // afterAction should NOT have fired for the replayed call
    expect($afterActionFired)->toBeFalse();
});

test('idempotency key is stable across calls with same arguments', function () {
    $agent = new DurableTestAgent;

    // Use reflection to test the protected method
    $method = new ReflectionMethod($agent, 'buildIdempotencyKey');

    $key1 = $method->invoke($agent, 'run-x', 3, 'my_tool', ['z' => 1, 'a' => 2]);
    $key2 = $method->invoke($agent, 'run-x', 3, 'my_tool', ['a' => 2, 'z' => 1]); // different order

    expect($key1)->toBe($key2); // argument order must not matter
});

// ─── findRun ─────────────────────────────────────────────────────────

test('findRun returns the AgentRun for a known run_id', function () {
    AgentRun::create([
        'run_id'         => 'run-find',
        'agent_class'    => DurableTestAgent::class,
        'loop_type'      => 'react',
        'status'         => RunStatus::Running,
        'initial_prompt' => 'Find me',
        'started_at'     => now(),
    ]);

    $run = DurableTestAgent::findRun('run-find');

    expect($run)->toBeInstanceOf(AgentRun::class);
    expect($run->run_id)->toBe('run-find');
});

test('findRun throws RunNotFoundException for unknown run_id', function () {
    expect(fn () => DurableTestAgent::findRun('ghost-run'))
        ->toThrow(RunNotFoundException::class);
});

// ─── LoopResult integration ──────────────────────────────────────────

test('returns a complete LoopResult with steps from a multi-iteration durable run', function () {
    $agent = durableAgent()
        ->withTools([new AdditionTool])
        ->fakeResponses([
            durableResponse('Using add.', [
                ['name' => 'add_numbers', 'arguments' => ['a' => 10, 'b' => 20]],
            ]),
            durableResponse('The answer is 30.'),
        ]);

    $result = $agent->durableReactLoop('Add 10 and 20', runId: 'run-result');

    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->completed())->toBeTrue();
    expect($result->iterations)->toBe(2);
    expect($result->text())->toBe('The answer is 30.');
    expect($result->allToolCalls())->toHaveCount(1);
    expect($result->allToolCalls()[0]['tool'])->toBe('add_numbers');
});
