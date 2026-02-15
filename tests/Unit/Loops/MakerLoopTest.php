<?php

declare(strict_types=1);

use Laravel\Ai\Responses\AgentResponse;
use Laragentic\Loops\MakerResult;
use Laragentic\Loops\RedFlagDetector;
use Tests\Fixtures\MakerTestAgent;

// ─── RedFlagDetector Tests ────────────────────────────────────────

test('RedFlagDetector detects strong uncertainty markers', function () {
    $response = 'wait, maybe this is not correct';
    expect(RedFlagDetector::detect($response))->toBeGreaterThanOrEqual(1.0);
    expect(RedFlagDetector::hasRedFlags($response))->toBeTrue();
});

test('RedFlagDetector detects weak uncertainty markers', function () {
    $response = 'hmm, let me think about this';
    $score = RedFlagDetector::detect($response);
    expect($score)->toBeGreaterThan(0)->toBeLessThan(1.0);
    expect(RedFlagDetector::hasRedFlags($response))->toBeFalse();
});

test('RedFlagDetector does not flag confident responses', function () {
    $response = 'The answer is 42. This is correct.';
    expect(RedFlagDetector::detect($response))->toBe(0.0);
    expect(RedFlagDetector::hasRedFlags($response))->toBeFalse();
});

test('RedFlagDetector detects circular reasoning', function () {
    $response = 'This is the answer. This is the answer. This is the answer. This is the answer.';
    expect(RedFlagDetector::hasCircularReasoning($response))->toBeTrue();
    expect(RedFlagDetector::detect($response))->toBeGreaterThan(0);
});

test('RedFlagDetector does not flag varied responses', function () {
    $response = 'First, we calculate X. Then we add Y. Finally, we get Z. The answer is complete.';
    expect(RedFlagDetector::hasCircularReasoning($response))->toBeFalse();
});

// ─── Basic MAKER Loop Tests ────────────────────────────────────────

test('MakerLoop executes simple atomic task without decomposition', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        'The answer is 42', // Atomic execution response
        'Final answer: 42', // Final composition
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(0) // Force no decomposition
        ->makerLoop('What is 6 x 7?');

    expect($result)->toBeInstanceOf(MakerResult::class);
    expect($result->completed())->toBeTrue();
    expect($result->totalSteps)->toBeGreaterThan(0);
});

test('MakerLoop decomposes complex task into subtasks', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        // Decomposition voting (3 votes for K=2)
        "1. Calculate 5!\n2. Calculate 3!\n3. Add results",
        "1. Calculate 5!\n2. Calculate 3!\n3. Add results", // Consensus reached
        "1. Calculate 5!\n2. Calculate 3!\n3. Add results",
        // Subtask 1 execution
        '120',
        '120',
        // Subtask 2 execution
        '6',
        '6',
        // Subtask 3 execution
        '126',
        '126',
        // Composition
        'Final answer: 126',
        'Final answer: 126',
        // Final response
        'The result is 126',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(1)
        ->makerLoop('Calculate 5! + 3!');

    expect($result)->toBeInstanceOf(MakerResult::class);
    expect($result->executionStats['decompositions'])->toBeGreaterThan(0);
    expect($result->executionStats['subtasks_created'])->toBeGreaterThan(0);
});

// ─── Voting Tests ───────────────────────────────────────────────────

test('MakerLoop achieves consensus with first-to-ahead-by-K voting', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        '42', // Vote 1
        '42', // Vote 2 - candidate A has 2 votes
        '41', // Vote 3 - candidate B has 1 vote
        // With K=2, candidate A (42) wins: 2 - 1 = 1 >= K(2)? No, need more votes
        '42', // Vote 4 - candidate A now has 3 votes
        // 3 - 1 = 2 >= K(2)? Yes! Consensus reached
        'Final: 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->makerLoop('What is the answer?');

    expect($result->executionStats['votes_cast'])->toBeGreaterThanOrEqual(4);
});

test('MakerLoop with higher K requires more votes', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        '42', // Vote 1
        '42', // Vote 2
        '42', // Vote 3
        '41', // Vote 4
        '42', // Vote 5 - A has 4, B has 1, lead = 3
        // With K=3, need lead of 3, so 4 - 1 = 3 >= 3, consensus!
        'Final: 42',
    ]);

    $result = $agent
        ->votingK(3)
        ->maxDecompositionDepth(0)
        ->makerLoop('What is the answer?');

    expect($result->executionStats['votes_cast'])->toBeGreaterThanOrEqual(5);
});

// ─── Red Flag Tests ─────────────────────────────────────────────────

test('MakerLoop retries when red flag detected', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        'wait, maybe the answer is 42', // Red flag! Skip this vote
        '42', // Vote 1 (counted)
        '42', // Vote 2 - consensus with K=2
        'Final: 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->enableRedFlagging(true)
        ->maxDecompositionDepth(0)
        ->makerLoop('What is the answer?');

    expect($result->executionStats['red_flags_detected'])->toBeGreaterThan(0);
    expect($result->executionStats['votes_cast'])->toBeGreaterThanOrEqual(2);
});

test('MakerLoop can disable red flagging', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        'wait, maybe the answer is 42', // Not skipped when disabled
        'wait, maybe the answer is 42', // Consensus reached
        'Final: wait, maybe the answer is 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->enableRedFlagging(false)
        ->maxDecompositionDepth(0)
        ->makerLoop('What is the answer?');

    expect($result->executionStats['red_flags_detected'])->toBe(0);
});

// ─── Callback Tests ─────────────────────────────────────────────────

test('MakerLoop fires onLoopStart callback', function () {
    $fired = false;
    $receivedPrompt = null;

    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->onLoopStart(function ($prompt) use (&$fired, &$receivedPrompt) {
            $fired = true;
            $receivedPrompt = $prompt;
        })
        ->makerLoop('Test prompt');

    expect($fired)->toBeTrue();
    expect($receivedPrompt)->toBe('Test prompt');
});

test('MakerLoop fires onLoopComplete callback', function () {
    $fired = false;
    $receivedResponse = null;
    $receivedSteps = null;

    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->onLoopComplete(function ($response, $steps) use (&$fired, &$receivedResponse, &$receivedSteps) {
            $fired = true;
            $receivedResponse = $response;
            $receivedSteps = $steps;
        })
        ->makerLoop('Test');

    expect($fired)->toBeTrue();
    expect($receivedResponse)->toBeInstanceOf(AgentResponse::class);
    expect($receivedSteps)->toBeGreaterThan(0);
});

test('MakerLoop fires onBeforeVote and onAfterVote callbacks', function () {
    $beforeVotes = [];
    $afterVotes = [];

    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->onBeforeVote(function ($prompt, $voteNum, $iteration) use (&$beforeVotes) {
            $beforeVotes[] = $voteNum;
        })
        ->onAfterVote(function ($response, $voteNum, $iteration) use (&$afterVotes) {
            $afterVotes[] = $voteNum;
        })
        ->makerLoop('Test');

    expect($beforeVotes)->not->toBeEmpty();
    expect($afterVotes)->not->toBeEmpty();
    expect(count($beforeVotes))->toBe(count($afterVotes));
});

test('MakerLoop fires onConsensus callback', function () {
    $fired = false;
    $winner = null;
    $votes = null;

    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->onConsensus(function ($w, $v, $iteration) use (&$fired, &$winner, &$votes) {
            $fired = true;
            $winner = $w;
            $votes = $v;
        })
        ->makerLoop('Test');

    expect($fired)->toBeTrue();
    expect($winner)->not->toBeNull();
    expect($votes)->toBeGreaterThan(0);
});

test('MakerLoop fires onRedFlag callback when flag detected', function () {
    $fired = false;
    $flaggedResponse = null;
    $score = null;

    $agent = (new MakerTestAgent)->fakeResponses([
        'wait, maybe 42',
        '42',
        '42',
        'Final: 42',
    ]);

    $agent
        ->votingK(2)
        ->enableRedFlagging(true)
        ->maxDecompositionDepth(0)
        ->onRedFlag(function ($response, $s, $iteration) use (&$fired, &$flaggedResponse, &$score) {
            $fired = true;
            $flaggedResponse = $response;
            $score = $s;
        })
        ->makerLoop('Test');

    expect($fired)->toBeTrue();
    expect($flaggedResponse)->toContain('wait, maybe');
    expect($score)->toBeGreaterThan(0);
});

test('MakerLoop fires onDecomposition callback', function () {
    $fired = false;
    $subtasks = null;

    $agent = (new MakerTestAgent)->fakeResponses([
        "1. Task A\n2. Task B",
        "1. Task A\n2. Task B",
        'Result A',
        'Result A',
        'Result B',
        'Result B',
        'Combined result',
        'Combined result',
        'Final',
    ]);

    $agent
        ->votingK(2)
        ->maxDecompositionDepth(1)
        ->onDecomposition(function ($st, $depth, $iteration) use (&$fired, &$subtasks) {
            $fired = true;
            $subtasks = $st;
        })
        ->makerLoop('Complex task');

    expect($fired)->toBeTrue();
    expect($subtasks)->not->toBeEmpty();
});

test('MakerLoop fires onAtomicExecution callback', function () {
    $fired = false;
    $task = null;
    $result = null;

    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->onAtomicExecution(function ($t, $r, $iteration) use (&$fired, &$task, &$result) {
            $fired = true;
            $task = $t;
            $result = $r;
        })
        ->makerLoop('Simple task');

    expect($fired)->toBeTrue();
    expect($task)->not->toBeNull();
    expect($result)->not->toBeNull();
});

test('MakerLoop fires onComposition callback', function () {
    $fired = false;

    $agent = (new MakerTestAgent)->fakeResponses([
        "1. Task A\n2. Task B",
        "1. Task A\n2. Task B",
        'Result A',
        'Result A',
        'Result B',
        'Result B',
        'Combined',
        'Combined',
        'Final',
    ]);

    $agent
        ->votingK(2)
        ->maxDecompositionDepth(1)
        ->onComposition(function ($task, $result, $iteration) use (&$fired) {
            $fired = true;
        })
        ->makerLoop('Complex task');

    expect($fired)->toBeTrue();
});

// ─── Result and Step Tests ──────────────────────────────────────────

test('MakerResult provides execution statistics', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->makerLoop('Test');

    expect($result->executionStats)->toHaveKeys([
        'total_steps',
        'atomic_executions',
        'decompositions',
        'compositions',
        'subtasks_created',
        'votes_cast',
        'red_flags_detected',
        'max_depth_reached',
    ]);
});

test('MakerResult calculates error rate', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->makerLoop('Test');

    $errorRate = $result->errorRate();
    expect($errorRate)->toBeGreaterThanOrEqual(0.0);
    expect($errorRate)->toBeLessThanOrEqual(1.0);
});

test('MakerResult provides step summaries', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final: 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->makerLoop('Test');

    $summaries = $result->stepSummaries();
    expect($summaries)->toBeArray();
    expect($summaries)->not->toBeEmpty();
});

test('MakerResult filters steps by type', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        "1. A\n2. B",
        "1. A\n2. B",
        'Result A',
        'Result A',
        'Result B',
        'Result B',
        'Combined',
        'Combined',
        'Final',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(1)
        ->makerLoop('Test');

    $decomps = $result->decompositions();
    $atomics = $result->atomicExecutions();
    $comps = $result->compositions();

    expect($decomps)->not->toBeEmpty();
    expect($atomics)->not->toBeEmpty();
});

test('MakerResult delegates to AgentResponse', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        '42',
        '42',
        'Final answer is 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(0)
        ->makerLoop('Test');

    expect($result->text())->toContain('42');
    expect($result->conversationId())->toBeNull();
});

// ─── Configuration Tests ────────────────────────────────────────────

test('MakerLoop respects voting K configuration', function () {
    $agent = (new MakerTestAgent)->fakeResponses(array_fill(0, 20, '42'));

    $result = $agent
        ->votingK(4) // Higher K requires more votes
        ->maxDecompositionDepth(0)
        ->makerLoop('Test');

    // With K=4, need at least 7 votes (2*4-1)
    expect($result->executionStats['votes_cast'])->toBeGreaterThanOrEqual(7);
});

test('MakerLoop respects max decomposition depth', function () {
    $agent = (new MakerTestAgent)->fakeResponses(array_fill(0, 20, 'Result'));

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(2)
        ->makerLoop('Very complex task with multiple steps and stages');

    expect($result->maxDepthReached())->toBeLessThanOrEqual(2);
});

// ─── Edge Cases ─────────────────────────────────────────────────────

test('MakerLoop handles empty decomposition response', function () {
    $agent = (new MakerTestAgent)->fakeResponses([
        '', // Empty decomposition response
        '42', // Falls back to atomic execution
        '42',
        'Final: 42',
    ]);

    $result = $agent
        ->votingK(2)
        ->maxDecompositionDepth(1)
        ->makerLoop('Test');

    expect($result->completed())->toBeTrue();
});

test('MakerLoop handles max votes without consensus', function () {
    // Provide 15 different responses (max_votes default is 15)
    $responses = [];
    for ($i = 0; $i < 15; $i++) {
        $responses[] = "Answer {$i}";
    }
    $responses[] = 'Final: no consensus';

    $agent = (new MakerTestAgent)->fakeResponses($responses);

    $result = $agent
        ->votingK(10) // Very high K to prevent consensus
        ->maxDecompositionDepth(0)
        ->makerLoop('Test');

    // Should return most voted candidate even without reaching K
    expect($result->completed())->toBeTrue();
    expect($result->executionStats['votes_cast'])->toBe(15);
});
