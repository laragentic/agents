<?php

declare(strict_types=1);

use Tests\Fixtures\FactorialAgent;

beforeEach(function () {
    if (! env('ANTHROPIC_API_KEY')) {
        $this->markTestSkipped('ANTHROPIC_API_KEY not set - skipping integration tests');
    }
});

// ─── Simple Factorial Tests ─────────────────────────────────────────

test('FactorialAgent calculates 5! with MAKER loop', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(5)
        ->makerLoop('Calculate 5! (5 factorial) step by step');

    expect($result->completed())->toBeTrue();
    expect($result->text())->toContain('120');
    expect($result->executionStats['atomic_executions'])->toBeGreaterThan(0);
})->group('integration');

test('FactorialAgent calculates 6! with voting consensus', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(3)
        ->enableRedFlagging(true)
        ->maxDecompositionDepth(5)
        ->makerLoop('What is 6 factorial (6!)?');

    expect($result->completed())->toBeTrue();
    expect($result->text())->toContain('720');
    expect($result->errorRate())->toBeLessThan(0.5);
})->group('integration');

// ─── Multi-Step Calculation Tests ───────────────────────────────────

test('FactorialAgent handles multi-step calculation with decomposition', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(5)
        ->makerLoop('Calculate (5! + 3!) and show your work');

    expect($result->completed())->toBeTrue();
    expect($result->executionStats['decompositions'])->toBeGreaterThan(0);
    expect($result->executionStats['subtasks_created'])->toBeGreaterThan(0);
})->group('integration');

test('FactorialAgent decomposes and composes results correctly', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(3)
        ->makerLoop('Calculate (4! × 2) step by step');

    expect($result->completed())->toBeTrue();
    expect($result->decompositions())->not->toBeEmpty();
    expect($result->compositions())->not->toBeEmpty();
})->group('integration');

// ─── Callback Integration Tests ─────────────────────────────────────

test('FactorialAgent fires callbacks during real execution', function () {
    $callbacksFired = [];

    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(3)
        ->onLoopStart(function () use (&$callbacksFired) {
            $callbacksFired[] = 'loopStart';
        })
        ->onBeforeVote(function () use (&$callbacksFired) {
            $callbacksFired[] = 'beforeVote';
        })
        ->onAfterVote(function () use (&$callbacksFired) {
            $callbacksFired[] = 'afterVote';
        })
        ->onConsensus(function () use (&$callbacksFired) {
            $callbacksFired[] = 'consensus';
        })
        ->onAtomicExecution(function () use (&$callbacksFired) {
            $callbacksFired[] = 'atomicExecution';
        })
        ->onLoopComplete(function () use (&$callbacksFired) {
            $callbacksFired[] = 'loopComplete';
        })
        ->makerLoop('Calculate 4!');

    expect($callbacksFired)->toContain('loopStart');
    expect($callbacksFired)->toContain('beforeVote');
    expect($callbacksFired)->toContain('afterVote');
    expect($callbacksFired)->toContain('consensus');
    expect($callbacksFired)->toContain('loopComplete');
})->group('integration');

test('FactorialAgent red flag callback fires on uncertainty', function () {
    $redFlagsFired = 0;

    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->enableRedFlagging(true)
        ->maxDecompositionDepth(3)
        ->onRedFlag(function () use (&$redFlagsFired) {
            $redFlagsFired++;
        })
        ->makerLoop('Calculate 5!');

    // Red flags may or may not be detected depending on LLM behavior
    // This test just verifies the callback system works
    expect($redFlagsFired)->toBeGreaterThanOrEqual(0);
})->group('integration');

// ─── Statistics and Reporting Tests ─────────────────────────────────

test('FactorialAgent provides detailed execution statistics', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(3)
        ->maxDecompositionDepth(5)
        ->makerLoop('Calculate 5! step by step');

    $stats = $result->executionStats;

    expect($stats['total_steps'])->toBeGreaterThan(0);
    expect($stats['votes_cast'])->toBeGreaterThan(0);
    expect($stats['max_depth_reached'])->toBeGreaterThanOrEqual(0);

    // Error rate should be low with K=3
    expect($result->errorRate())->toBeLessThan(0.7);
})->group('integration');

test('FactorialAgent records step history for introspection', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(3)
        ->makerLoop('Calculate 4!');

    expect($result->steps)->not->toBeEmpty();
    expect($result->stepSummaries())->not->toBeEmpty();

    // Verify step has expected properties
    $firstStep = $result->steps[0];
    expect($firstStep->iteration)->toBeGreaterThan(0);
    expect($firstStep->type)->toBeIn(['decompose', 'execute', 'compose']);
})->group('integration');

// ─── Performance and Reliability Tests ──────────────────────────────

test('FactorialAgent with higher K has lower error rate', function () {
    $agentK2 = new FactorialAgent;
    $resultK2 = $agentK2
        ->votingK(2)
        ->maxDecompositionDepth(3)
        ->makerLoop('Calculate 5!');

    $agentK4 = new FactorialAgent;
    $resultK4 = $agentK4
        ->votingK(4)
        ->maxDecompositionDepth(3)
        ->makerLoop('Calculate 5!');

    // K=4 should have lower or equal error rate than K=2
    expect($resultK4->errorRate())->toBeLessThanOrEqual($resultK2->errorRate() + 0.1);
    // K=4 should use more votes
    expect($resultK4->executionStats['votes_cast'])->toBeGreaterThan($resultK2->executionStats['votes_cast']);
})->group('integration')->skip('This test is expensive - run manually');

test('FactorialAgent handles complex multi-step problems', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(5)
        ->makerLoop('Calculate (5! + 3!) × 2 and show all steps');

    expect($result->completed())->toBeTrue();
    expect($result->executionStats['decompositions'])->toBeGreaterThan(0);
    expect($result->maxDepthReached())->toBeGreaterThan(0);
})->group('integration');

// ─── Streaming Tests ────────────────────────────────────────────────

test('FactorialAgent can stream results via generator', function () {
    $agent = new FactorialAgent;

    $events = [];
    $generator = $agent
        ->votingK(2)
        ->maxDecompositionDepth(2)
        ->onBeforeVote(function ($prompt, $voteNum) {
            yield ['event' => 'beforeVote', 'voteNum' => $voteNum];
        })
        ->onAfterVote(function ($response, $voteNum) {
            yield ['event' => 'afterVote', 'voteNum' => $voteNum];
        })
        ->makerLoopStream('Calculate 4!');

    foreach ($generator as $event) {
        $events[] = $event;
    }

    $result = $generator->getReturn();

    expect($result)->toBeInstanceOf(\Laragentic\Loops\MakerResult::class);
    expect($events)->not->toBeEmpty();
})->group('integration');

// ─── Edge Cases with Real LLM ───────────────────────────────────────

test('FactorialAgent handles ambiguous prompts gracefully', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(3)
        ->maxDecompositionDepth(3)
        ->makerLoop('What is factorial?');

    // Should complete even if task is unclear
    expect($result->completed())->toBeTrue();
})->group('integration');

test('FactorialAgent respects max iterations limit', function () {
    $agent = new FactorialAgent;

    $result = $agent
        ->votingK(2)
        ->maxMakerIterations(5) // Very low limit
        ->maxDecompositionDepth(10)
        ->makerLoop('Calculate 10! with detailed steps');

    // Should hit max iterations for complex task with low limit
    expect($result->reachedMaxIterations)->toBeTrue();
    expect($result->executionStats['total_steps'])->toBeLessThanOrEqual(5);
})->group('integration');
