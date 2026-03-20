<?php

declare(strict_types=1);

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Concerns\HasDurableRuns;
use Laragentic\Exceptions\RunCancelledException;
use Laragentic\Loops\LoopResult;
use Laragentic\Loops\ReActLoop;
use Laragentic\Runs\AgentCheckpoint;
use Laragentic\Runs\AgentRun;
use Laragentic\Runs\RunStatus;
use Illuminate\Support\Facades\Schema;
use Laragentic\Tests\Fixtures\CalculatorTool;

/*
|--------------------------------------------------------------------------
| Durable Runs Integration Tests — Real API Calls + Real Database
|--------------------------------------------------------------------------
|
| These tests call a real LLM API and write to an in-memory SQLite
| database. They verify the full persistence lifecycle end-to-end:
| run creation, checkpoint writing, status transitions, and lookups.
|
| Run:   vendor/bin/pest --group=integration
| Skip:  vendor/bin/pest --exclude-group=integration
|
*/

// ─── Database Setup: run the real package migrations ─────────────────

beforeEach(function () {
    $migrations = glob(__DIR__ . '/../../database/migrations/*.php');
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

if (! function_exists('skipWithoutApiKey')) {
    function skipWithoutApiKey(string $key): void
    {
        if (empty(env($key))) {
            test()->markTestSkipped("Skipping: {$key} not set in .env");
        }
    }
}

// ─── Inline Agent ────────────────────────────────────────────────────

class DurableMathIntegrationAgent implements Agent, HasTools
{
    use Promptable, ReActLoop, HasDurableRuns;

    public function instructions(): string
    {
        return <<<'PROMPT'
        You are a math assistant. You MUST use the calculator tool to solve
        any arithmetic problems. Do not calculate in your head — always use
        the tool for accuracy. After receiving the result from the calculator,
        provide the final answer clearly to the user.
        PROMPT;
    }

    public function tools(): iterable
    {
        return [new CalculatorTool];
    }
}

// ─── Tests ───────────────────────────────────────────────────────────

test('durable run creates an AgentRun record and completes', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $result = (new DurableMathIntegrationAgent)
        ->maxIterations(3)
        ->durableReactLoop(
            'What is 12 multiplied by 15? Use the calculator.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result)->toBeInstanceOf(LoopResult::class);
    expect($result->completed())->toBeTrue();
    expect($result->text())->toContain('180');

    // Exactly one AgentRun row was persisted
    expect(AgentRun::count())->toBe(1);

    $run = AgentRun::first();
    expect($run->status)->toBe(RunStatus::Completed);
    expect($run->agent_class)->toBe(DurableMathIntegrationAgent::class);
    expect($run->loop_type)->toBe('react');
    expect($run->final_response)->toContain('180');
    expect($run->completed_at)->not->toBeNull();
    expect($run->initial_prompt)->toContain('12 multiplied by 15');
})->group('integration');

test('durable run persists at least one checkpoint per iteration', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $result = (new DurableMathIntegrationAgent)
        ->maxIterations(3)
        ->durableReactLoop(
            'What is 9 plus 6? Use the calculator.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result->completed())->toBeTrue();

    // At least one checkpoint was written
    $checkpointCount = AgentCheckpoint::count();
    expect($checkpointCount)->toBeGreaterThanOrEqual(1);

    // Every checkpoint belongs to the run
    $run = AgentRun::first();
    expect(AgentCheckpoint::where('run_id', $run->run_id)->count())
        ->toBe($checkpointCount);

    // Each checkpoint stores the prompt it received
    $checkpoint = AgentCheckpoint::where('run_id', $run->run_id)
        ->orderBy('iteration')
        ->first();
    expect($checkpoint->prompt)->not->toBeEmpty();
    expect($checkpoint->response_text)->not->toBeEmpty();
    expect($checkpoint->iteration)->toBe(1);
})->group('integration');

test('auto-generated run_id is a valid UUID and unique per execution', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    // First run — no explicit run_id
    (new DurableMathIntegrationAgent)
        ->maxIterations(3)
        ->durableReactLoop(
            'What is 3 plus 4? Use the calculator.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    // Second run — also auto-generated
    (new DurableMathIntegrationAgent)
        ->maxIterations(3)
        ->durableReactLoop(
            'What is 5 plus 2? Use the calculator.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    $runs = AgentRun::all();
    expect($runs)->toHaveCount(2);

    $ids = $runs->pluck('run_id')->toArray();
    // Both are valid UUID v4 format
    foreach ($ids as $id) {
        expect($id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    }

    // They are distinct
    expect($ids[0])->not->toBe($ids[1]);
})->group('integration');

test('explicit run_id is stored and retrievable via findRun()', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $runId = 'integration-test-run-' . uniqid();

    $result = (new DurableMathIntegrationAgent)
        ->maxIterations(3)
        ->durableReactLoop(
            'What is 100 divided by 4? Use the calculator.',
            runId: $runId,
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result->completed())->toBeTrue();
    expect($result->text())->toContain('25');

    // findRun() returns the same run
    $run = DurableMathIntegrationAgent::findRun($runId);

    expect($run)->toBeInstanceOf(AgentRun::class);
    expect($run->run_id)->toBe($runId);
    expect($run->isCompleted())->toBeTrue();
    expect($run->final_response)->toContain('25');
    expect($run->initial_prompt)->toContain('100 divided by 4');
})->group('integration');

test('completed run cannot be resumed and throws a RuntimeException', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $runId = 'completed-run-' . uniqid();

    // First call completes the run
    (new DurableMathIntegrationAgent)
        ->maxIterations(3)
        ->durableReactLoop(
            'What is 8 multiplied by 7? Use the calculator.',
            runId: $runId,
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    $run = DurableMathIntegrationAgent::findRun($runId);
    expect($run->isCompleted())->toBeTrue();

    // Attempting to resume a completed run must throw
    expect(fn () => (new DurableMathIntegrationAgent)
        ->durableReactLoop(
            'What is 8 multiplied by 7? Use the calculator.',
            runId: $runId,
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        )
    )->toThrow(RuntimeException::class);
})->group('integration');

test('cancelRun marks an in-progress run as cancelled', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    // Create a run record in a pending/running state directly,
    // so we can test cancellation without needing real async execution.
    $runId = 'cancel-test-run-' . uniqid();

    $run = AgentRun::create([
        'run_id'         => $runId,
        'agent_class'    => DurableMathIntegrationAgent::class,
        'loop_type'      => 'react',
        'status'         => RunStatus::Running,
        'initial_prompt' => 'Test cancellation',
        'started_at'     => now(),
    ]);

    expect($run->isRunning())->toBeTrue();

    // Cancel from outside (e.g. from an HTTP endpoint)
    DurableMathIntegrationAgent::cancelRun($runId);

    // The run is now cancelled in the database
    $refreshed = DurableMathIntegrationAgent::findRun($runId);
    expect($refreshed->isCancelled())->toBeTrue();
    expect($refreshed->cancelled_at)->not->toBeNull();
})->group('integration');

test('lifecycle callbacks fire during a durable run', function () {
    skipWithoutApiKey('ANTHROPIC_API_KEY');

    $callbacksFired = [];

    $result = (new DurableMathIntegrationAgent)
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
        ->onLoopComplete(function ($response, int $iterations) use (&$callbacksFired) {
            $callbacksFired['loopComplete'] = $iterations;
        })
        ->durableReactLoop(
            'What is 11 plus 22? Use the calculator.',
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
        );

    expect($result->completed())->toBeTrue();
    expect($result->text())->toContain('33');

    expect($callbacksFired)->toHaveKey('loopStart');
    expect($callbacksFired)->toHaveKey('iterationStart');
    expect($callbacksFired)->toHaveKey('beforeThought');
    expect($callbacksFired)->toHaveKey('afterThought');
    expect($callbacksFired)->toHaveKey('iterationEnd');
    expect($callbacksFired)->toHaveKey('loopComplete');
    expect($callbacksFired['loopComplete'])->toBe(1);
})->group('integration');
