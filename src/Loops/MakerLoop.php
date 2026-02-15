<?php

declare(strict_types=1);

namespace Laragentic\Loops;

use Closure;
use Laragentic\Concerns\HasCallbacks;
use Laragentic\Exceptions\MaxIterationsExceededException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\Request;

/**
 * Adds a MAKER (Massively Decomposed Agentic Processes with first-to-ahead-by-K
 * Error correction and Red-flagging) loop to any Laravel AI SDK agent.
 *
 * The MAKER loop implements the framework from the paper "Solving a Million-Step
 * LLM Task with Zero Errors" which achieves near-zero error rates through:
 *
 * 1. Maximal Decomposition: Breaking tasks into atomic subtasks
 * 2. First-to-Ahead-by-K Voting: Multi-agent consensus for error correction
 * 3. Red-Flagging: Detecting and retrying uncertain responses
 *
 * Flow:
 *     User Task
 *       ↓
 *     Should Decompose?
 *       ├─ Yes → Decompose with Voting → Execute Subtasks → Compose Results
 *       └─ No  → Execute Atomic Task with Voting
 *
 * Usage:
 *
 *     class FactorialAgent implements Agent
 *     {
 *         use Promptable, MakerLoop;
 *
 *         public function instructions(): string { ... }
 *     }
 *
 *     $result = (new FactorialAgent)
 *         ->votingK(3)
 *         ->enableRedFlagging(true)
 *         ->makerLoop('Calculate 15! step by step');
 */
trait MakerLoop
{
    use HasCallbacks;

    /**
     * The voting K parameter: first answer to achieve a lead of K votes wins.
     */
    protected ?int $votingKValue = null;

    /**
     * Whether red-flagging (uncertainty detection) is enabled.
     */
    protected ?bool $redFlaggingEnabled = null;

    /**
     * Maximum decomposition depth (recursion limit).
     */
    protected ?int $maxDecompDepth = null;

    /**
     * Maximum total iterations (steps) across all operations.
     */
    protected ?int $maxMakerIters = null;

    /**
     * Current execution statistics.
     *
     * @var array{total_steps: int, atomic_executions: int, decompositions: int, compositions: int, subtasks_created: int, votes_cast: int, red_flags_detected: int, max_depth_reached: int}
     */
    protected array $makerStats = [
        'total_steps' => 0,
        'atomic_executions' => 0,
        'decompositions' => 0,
        'compositions' => 0,
        'subtasks_created' => 0,
        'votes_cast' => 0,
        'red_flags_detected' => 0,
        'max_depth_reached' => 0,
    ];

    /**
     * Steps collected during execution.
     *
     * @var list<MakerStep>
     */
    protected array $makerSteps = [];

    // ─── Configuration Methods ──────────────────────────────────────

    /**
     * Set the voting K parameter (first-to-ahead-by-K threshold).
     *
     * Higher K = more reliable but slower. Recommended values:
     * - K=2: Fast, moderate reliability
     * - K=3: Balanced (default)
     * - K=4: Very reliable but slow
     */
    public function votingK(int $k): static
    {
        $this->votingKValue = $k;

        return $this;
    }

    /**
     * Enable or disable red-flagging (uncertainty detection).
     */
    public function enableRedFlagging(bool $enabled): static
    {
        $this->redFlaggingEnabled = $enabled;

        return $this;
    }

    /**
     * Set the maximum decomposition depth (recursion limit).
     */
    public function maxDecompositionDepth(int $depth): static
    {
        $this->maxDecompDepth = $depth;

        return $this;
    }

    /**
     * Set the maximum total iterations across all operations.
     */
    public function maxMakerIterations(int $max): static
    {
        $this->maxMakerIters = $max;

        return $this;
    }

    // ─── Callback Registration ──────────────────────────────────────

    /**
     * Register a callback for when a task is decomposed into subtasks.
     *
     * Receives: (array $subtasks, int $depth, int $iteration)
     */
    public function onDecomposition(Closure $callback): static
    {
        $this->loopCallbacks['decomposition'][] = $callback;

        return $this;
    }

    /**
     * Register a callback before each vote is cast.
     *
     * Receives: (string $prompt, int $voteNumber, int $iteration)
     */
    public function onBeforeVote(Closure $callback): static
    {
        $this->loopCallbacks['beforeVote'][] = $callback;

        return $this;
    }

    /**
     * Register a callback after each vote is received.
     *
     * Receives: (string $response, int $voteNumber, int $iteration)
     */
    public function onAfterVote(Closure $callback): static
    {
        $this->loopCallbacks['afterVote'][] = $callback;

        return $this;
    }

    /**
     * Register a callback when consensus is reached through voting.
     *
     * Receives: (string $winner, int $votes, int $iteration)
     */
    public function onConsensus(Closure $callback): static
    {
        $this->loopCallbacks['consensus'][] = $callback;

        return $this;
    }

    /**
     * Register a callback when a red flag is detected.
     *
     * Receives: (string $response, float $score, int $iteration)
     */
    public function onRedFlag(Closure $callback): static
    {
        $this->loopCallbacks['redFlag'][] = $callback;

        return $this;
    }

    /**
     * Register a callback when an atomic task is executed.
     *
     * Receives: (string $task, string $result, int $iteration)
     */
    public function onAtomicExecution(Closure $callback): static
    {
        $this->loopCallbacks['atomicExecution'][] = $callback;

        return $this;
    }

    /**
     * Register a callback when results are composed.
     *
     * Receives: (string $task, string $result, int $iteration)
     */
    public function onComposition(Closure $callback): static
    {
        $this->loopCallbacks['composition'][] = $callback;

        return $this;
    }

    /**
     * Register a callback when max iterations is reached.
     *
     * Receives: (AgentResponse $response, int $totalSteps)
     */
    public function onMaxIterationsReached(Closure $callback): static
    {
        $this->loopCallbacks['maxIterationsReached'][] = $callback;

        return $this;
    }

    // ─── Main Loop Methods ──────────────────────────────────────────

    /**
     * Execute the MAKER loop with Massively Decomposed Agentic Processes.
     *
     * @throws MaxIterationsExceededException
     */
    public function makerLoop(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): MakerResult {
        $this->resetMakerStats();
        $this->makerSteps = [];

        $this->fireCallbacks('loopStart', $prompt);

        try {
            $result = $this->executeWithMDAP($prompt, 0, $provider, $model, $timeout);

            // Create final response
            $finalResponse = $this->prompt(
                prompt: "Task complete. Final result: {$result}",
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $this->fireCallbacks('loopComplete', $finalResponse, $this->makerStats['total_steps']);

            return new MakerResult(
                response: $finalResponse,
                totalSteps: $this->makerStats['total_steps'],
                executionStats: $this->makerStats,
                steps: $this->makerSteps,
                reachedMaxIterations: false,
            );
        } catch (MaxIterationsExceededException $e) {
            $this->fireCallbacks('maxIterationsReached', $e->response, $this->makerStats['total_steps']);

            if (config('agentic.maker.throw_on_max_iterations', false)) {
                throw $e;
            }

            return new MakerResult(
                response: $e->response,
                totalSteps: $this->makerStats['total_steps'],
                executionStats: $this->makerStats,
                steps: $this->makerSteps,
                reachedMaxIterations: true,
            );
        }
    }

    /**
     * Execute the MAKER loop as a Generator for streaming contexts.
     *
     * @return \Generator<int, mixed, mixed, MakerResult>
     *
     * @throws MaxIterationsExceededException
     */
    public function makerLoopStream(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $this->resetMakerStats();
        $this->makerSteps = [];

        yield from $this->fireStreamCallbacks('loopStart', $prompt);

        try {
            $result = yield from $this->executeWithMDAPStream($prompt, 0, $provider, $model, $timeout);

            // Create final response
            $finalResponse = $this->prompt(
                prompt: "Task complete. Final result: {$result}",
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            yield from $this->fireStreamCallbacks('loopComplete', $finalResponse, $this->makerStats['total_steps']);

            return new MakerResult(
                response: $finalResponse,
                totalSteps: $this->makerStats['total_steps'],
                executionStats: $this->makerStats,
                steps: $this->makerSteps,
                reachedMaxIterations: false,
            );
        } catch (MaxIterationsExceededException $e) {
            yield from $this->fireStreamCallbacks('maxIterationsReached', $e->response, $this->makerStats['total_steps']);

            if (config('agentic.maker.throw_on_max_iterations', false)) {
                throw $e;
            }

            return new MakerResult(
                response: $e->response,
                totalSteps: $this->makerStats['total_steps'],
                executionStats: $this->makerStats,
                steps: $this->makerSteps,
                reachedMaxIterations: true,
            );
        }
    }

    // ─── MDAP Core Logic ────────────────────────────────────────────

    /**
     * Execute task using Massively Decomposed Agentic Processes.
     */
    protected function executeWithMDAP(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): string {
        $this->checkMaxIterations();

        if ($this->shouldDecompose($task, $depth)) {
            return $this->decomposeAndExecute($task, $depth, $provider, $model, $timeout);
        }

        return $this->executeAtomicSubtaskWithVoting($task, $depth, $provider, $model, $timeout);
    }

    /**
     * Streaming variant of executeWithMDAP.
     *
     * @return \Generator<int, mixed, mixed, string>
     */
    protected function executeWithMDAPStream(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $this->checkMaxIterations();

        if ($this->shouldDecompose($task, $depth)) {
            return yield from $this->decomposeAndExecuteStream($task, $depth, $provider, $model, $timeout);
        }

        return yield from $this->executeAtomicSubtaskWithVotingStream($task, $depth, $provider, $model, $timeout);
    }

    /**
     * Decompose task and execute subtasks recursively.
     */
    protected function decomposeAndExecute(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): string {
        $this->makerStats['decompositions']++;
        $this->makerStats['total_steps']++;
        $this->updateMaxDepth($depth);

        $subtasks = $this->decomposeWithVoting($task, $depth, $provider, $model, $timeout);

        if (empty($subtasks)) {
            // Fallback to atomic execution
            return $this->executeAtomicSubtaskWithVoting($task, $depth, $provider, $model, $timeout);
        }

        $this->makerStats['subtasks_created'] += count($subtasks);
        $this->fireCallbacks('decomposition', $subtasks, $depth, $this->makerStats['total_steps']);

        // Execute each subtask recursively
        $subtaskResults = [];
        foreach ($subtasks as $i => $subtask) {
            $subtaskResults[] = $this->executeWithMDAP($subtask, $depth + 1, $provider, $model, $timeout);
        }

        // Compose results
        return $this->composeResults($task, $subtaskResults, $depth, $provider, $model, $timeout);
    }

    /**
     * Streaming variant of decomposeAndExecute.
     *
     * @return \Generator<int, mixed, mixed, string>
     */
    protected function decomposeAndExecuteStream(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $this->makerStats['decompositions']++;
        $this->makerStats['total_steps']++;
        $this->updateMaxDepth($depth);

        $subtasks = yield from $this->decomposeWithVotingStream($task, $depth, $provider, $model, $timeout);

        if (empty($subtasks)) {
            return yield from $this->executeAtomicSubtaskWithVotingStream($task, $depth, $provider, $model, $timeout);
        }

        $this->makerStats['subtasks_created'] += count($subtasks);
        yield from $this->fireStreamCallbacks('decomposition', $subtasks, $depth, $this->makerStats['total_steps']);

        // Execute each subtask recursively
        $subtaskResults = [];
        foreach ($subtasks as $i => $subtask) {
            $subtaskResults[] = yield from $this->executeWithMDAPStream($subtask, $depth + 1, $provider, $model, $timeout);
        }

        // Compose results
        return yield from $this->composeResultsStream($task, $subtaskResults, $depth, $provider, $model, $timeout);
    }

    /**
     * Decompose task with voting to ensure correct decomposition.
     *
     * @return array<int, string>
     */
    protected function decomposeWithVoting(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): array {
        $prompt = $this->buildDecompositionPrompt($task);
        $k = $this->resolveVotingK();
        $candidates = [];
        $n = $this->calculateMinVotes($k);
        $maxVotes = $this->resolveMaxVotes();

        for ($i = 0; $i < $maxVotes; $i++) {
            $this->fireCallbacks('beforeVote', $prompt, $i + 1, $this->makerStats['total_steps']);

            $response = $this->prompt(
                prompt: $prompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $responseText = $response->text;

            $this->fireCallbacks('afterVote', $responseText, $i + 1, $this->makerStats['total_steps']);
            $this->makerStats['votes_cast']++;

            // Check for red flags
            if ($this->isRedFlaggingEnabled() && RedFlagDetector::hasRedFlags($responseText)) {
                $score = RedFlagDetector::detect($responseText);
                $this->makerStats['red_flags_detected']++;
                $this->fireCallbacks('redFlag', $responseText, $score, $this->makerStats['total_steps']);
                continue; // Skip this vote
            }

            $subtasks = $this->parseDecomposition($responseText);
            $candidateKey = $this->hashCandidate(json_encode($subtasks));

            if (! isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = [
                    'subtasks' => $subtasks,
                    'votes' => 0,
                ];
            }

            $candidates[$candidateKey]['votes']++;

            // Check for first-to-ahead-by-k
            if ($i >= $n - 1 && $this->hasWinner($candidates, $k)) {
                $winner = $this->getWinningCandidate($candidates);
                $this->fireCallbacks('consensus', json_encode($winner['subtasks']), $winner['votes'], $this->makerStats['total_steps']);

                $this->recordStep(
                    type: 'decompose',
                    task: $task,
                    votes: $winner['votes'],
                    totalVotes: $i + 1,
                    candidates: $candidates,
                    depth: $depth,
                    subtasks: $winner['subtasks'],
                );

                return $winner['subtasks'];
            }
        }

        // Return most voted candidate
        $winner = $this->getWinningCandidate($candidates);
        $this->fireCallbacks('consensus', json_encode($winner['subtasks'] ?? []), $winner['votes'] ?? 0, $this->makerStats['total_steps']);

        $this->recordStep(
            type: 'decompose',
            task: $task,
            votes: $winner['votes'] ?? 0,
            totalVotes: $maxVotes,
            candidates: $candidates,
            depth: $depth,
            subtasks: $winner['subtasks'] ?? [],
        );

        return $winner['subtasks'] ?? [];
    }

    /**
     * Streaming variant of decomposeWithVoting.
     *
     * @return \Generator<int, mixed, mixed, array<int, string>>
     */
    protected function decomposeWithVotingStream(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $prompt = $this->buildDecompositionPrompt($task);
        $k = $this->resolveVotingK();
        $candidates = [];
        $n = $this->calculateMinVotes($k);
        $maxVotes = $this->resolveMaxVotes();

        for ($i = 0; $i < $maxVotes; $i++) {
            yield from $this->fireStreamCallbacks('beforeVote', $prompt, $i + 1, $this->makerStats['total_steps']);

            $response = $this->prompt(
                prompt: $prompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $responseText = $response->text;

            yield from $this->fireStreamCallbacks('afterVote', $responseText, $i + 1, $this->makerStats['total_steps']);
            $this->makerStats['votes_cast']++;

            // Check for red flags
            if ($this->isRedFlaggingEnabled() && RedFlagDetector::hasRedFlags($responseText)) {
                $score = RedFlagDetector::detect($responseText);
                $this->makerStats['red_flags_detected']++;
                yield from $this->fireStreamCallbacks('redFlag', $responseText, $score, $this->makerStats['total_steps']);
                continue;
            }

            $subtasks = $this->parseDecomposition($responseText);
            $candidateKey = $this->hashCandidate(json_encode($subtasks));

            if (! isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = [
                    'subtasks' => $subtasks,
                    'votes' => 0,
                ];
            }

            $candidates[$candidateKey]['votes']++;

            if ($i >= $n - 1 && $this->hasWinner($candidates, $k)) {
                $winner = $this->getWinningCandidate($candidates);
                yield from $this->fireStreamCallbacks('consensus', json_encode($winner['subtasks']), $winner['votes'], $this->makerStats['total_steps']);

                $this->recordStep(
                    type: 'decompose',
                    task: $task,
                    votes: $winner['votes'],
                    totalVotes: $i + 1,
                    candidates: $candidates,
                    depth: $depth,
                    subtasks: $winner['subtasks'],
                );

                return $winner['subtasks'];
            }
        }

        $winner = $this->getWinningCandidate($candidates);
        yield from $this->fireStreamCallbacks('consensus', json_encode($winner['subtasks'] ?? []), $winner['votes'] ?? 0, $this->makerStats['total_steps']);

        $this->recordStep(
            type: 'decompose',
            task: $task,
            votes: $winner['votes'] ?? 0,
            totalVotes: $maxVotes,
            candidates: $candidates,
            depth: $depth,
            subtasks: $winner['subtasks'] ?? [],
        );

        return $winner['subtasks'] ?? [];
    }

    /**
     * Execute atomic subtask with voting for error correction.
     */
    protected function executeAtomicSubtaskWithVoting(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): string {
        $this->makerStats['atomic_executions']++;
        $this->makerStats['total_steps']++;
        $this->updateMaxDepth($depth);

        $prompt = $this->buildExecutionPrompt($task);
        $k = $this->resolveVotingK();
        $candidates = [];
        $n = $this->calculateMinVotes($k);
        $maxVotes = $this->resolveMaxVotes();

        for ($i = 0; $i < $maxVotes; $i++) {
            $this->fireCallbacks('beforeVote', $prompt, $i + 1, $this->makerStats['total_steps']);

            $response = $this->prompt(
                prompt: $prompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $responseText = $response->text;

            $this->fireCallbacks('afterVote', $responseText, $i + 1, $this->makerStats['total_steps']);
            $this->makerStats['votes_cast']++;

            // Check for red flags
            if ($this->isRedFlaggingEnabled() && RedFlagDetector::hasRedFlags($responseText)) {
                $score = RedFlagDetector::detect($responseText);
                $this->makerStats['red_flags_detected']++;
                $this->fireCallbacks('redFlag', $responseText, $score, $this->makerStats['total_steps']);
                continue;
            }

            $candidateKey = $this->hashCandidate($responseText);

            if (! isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = [
                    'response' => $responseText,
                    'votes' => 0,
                ];
            }

            $candidates[$candidateKey]['votes']++;

            // Check for first-to-ahead-by-k
            if ($i >= $n - 1 && $this->hasWinner($candidates, $k)) {
                $winner = $this->getWinningCandidate($candidates);
                $result = $winner['response'];

                $this->fireCallbacks('consensus', $result, $winner['votes'], $this->makerStats['total_steps']);
                $this->fireCallbacks('atomicExecution', $task, $result, $this->makerStats['total_steps']);

                $this->recordStep(
                    type: 'execute',
                    task: $task,
                    result: $result,
                    votes: $winner['votes'],
                    totalVotes: $i + 1,
                    candidates: $candidates,
                    depth: $depth,
                );

                return $result;
            }
        }

        // Return most voted candidate
        $winner = $this->getWinningCandidate($candidates);
        $result = $winner['response'] ?? "Unable to reach consensus on: {$task}";

        $this->fireCallbacks('consensus', $result, $winner['votes'] ?? 0, $this->makerStats['total_steps']);
        $this->fireCallbacks('atomicExecution', $task, $result, $this->makerStats['total_steps']);

        $this->recordStep(
            type: 'execute',
            task: $task,
            result: $result,
            votes: $winner['votes'] ?? 0,
            totalVotes: $maxVotes,
            candidates: $candidates,
            depth: $depth,
        );

        return $result;
    }

    /**
     * Streaming variant of executeAtomicSubtaskWithVoting.
     *
     * @return \Generator<int, mixed, mixed, string>
     */
    protected function executeAtomicSubtaskWithVotingStream(
        string $task,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $this->makerStats['atomic_executions']++;
        $this->makerStats['total_steps']++;
        $this->updateMaxDepth($depth);

        $prompt = $this->buildExecutionPrompt($task);
        $k = $this->resolveVotingK();
        $candidates = [];
        $n = $this->calculateMinVotes($k);
        $maxVotes = $this->resolveMaxVotes();

        for ($i = 0; $i < $maxVotes; $i++) {
            yield from $this->fireStreamCallbacks('beforeVote', $prompt, $i + 1, $this->makerStats['total_steps']);

            $response = $this->prompt(
                prompt: $prompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $responseText = $response->text;

            yield from $this->fireStreamCallbacks('afterVote', $responseText, $i + 1, $this->makerStats['total_steps']);
            $this->makerStats['votes_cast']++;

            // Check for red flags
            if ($this->isRedFlaggingEnabled() && RedFlagDetector::hasRedFlags($responseText)) {
                $score = RedFlagDetector::detect($responseText);
                $this->makerStats['red_flags_detected']++;
                yield from $this->fireStreamCallbacks('redFlag', $responseText, $score, $this->makerStats['total_steps']);
                continue;
            }

            $candidateKey = $this->hashCandidate($responseText);

            if (! isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = [
                    'response' => $responseText,
                    'votes' => 0,
                ];
            }

            $candidates[$candidateKey]['votes']++;

            if ($i >= $n - 1 && $this->hasWinner($candidates, $k)) {
                $winner = $this->getWinningCandidate($candidates);
                $result = $winner['response'];

                yield from $this->fireStreamCallbacks('consensus', $result, $winner['votes'], $this->makerStats['total_steps']);
                yield from $this->fireStreamCallbacks('atomicExecution', $task, $result, $this->makerStats['total_steps']);

                $this->recordStep(
                    type: 'execute',
                    task: $task,
                    result: $result,
                    votes: $winner['votes'],
                    totalVotes: $i + 1,
                    candidates: $candidates,
                    depth: $depth,
                );

                return $result;
            }
        }

        $winner = $this->getWinningCandidate($candidates);
        $result = $winner['response'] ?? "Unable to reach consensus on: {$task}";

        yield from $this->fireStreamCallbacks('consensus', $result, $winner['votes'] ?? 0, $this->makerStats['total_steps']);
        yield from $this->fireStreamCallbacks('atomicExecution', $task, $result, $this->makerStats['total_steps']);

        $this->recordStep(
            type: 'execute',
            task: $task,
            result: $result,
            votes: $winner['votes'] ?? 0,
            totalVotes: $maxVotes,
            candidates: $candidates,
            depth: $depth,
        );

        return $result;
    }

    /**
     * Compose subtask results with voting.
     *
     * @param  array<int, string>  $subtaskResults
     */
    protected function composeResults(
        string $originalTask,
        array $subtaskResults,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): string {
        $this->makerStats['compositions']++;
        $this->makerStats['total_steps']++;

        $prompt = $this->buildCompositionPrompt($originalTask, $subtaskResults);
        $k = $this->resolveVotingK();
        $candidates = [];
        $n = $this->calculateMinVotes($k);
        $maxVotes = $this->resolveMaxVotes();

        for ($i = 0; $i < $maxVotes; $i++) {
            $this->fireCallbacks('beforeVote', $prompt, $i + 1, $this->makerStats['total_steps']);

            $response = $this->prompt(
                prompt: $prompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $responseText = $response->text;

            $this->fireCallbacks('afterVote', $responseText, $i + 1, $this->makerStats['total_steps']);
            $this->makerStats['votes_cast']++;

            // Check for red flags
            if ($this->isRedFlaggingEnabled() && RedFlagDetector::hasRedFlags($responseText)) {
                $score = RedFlagDetector::detect($responseText);
                $this->makerStats['red_flags_detected']++;
                $this->fireCallbacks('redFlag', $responseText, $score, $this->makerStats['total_steps']);
                continue;
            }

            $candidateKey = $this->hashCandidate($responseText);

            if (! isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = [
                    'response' => $responseText,
                    'votes' => 0,
                ];
            }

            $candidates[$candidateKey]['votes']++;

            if ($i >= $n - 1 && $this->hasWinner($candidates, $k)) {
                $winner = $this->getWinningCandidate($candidates);
                $result = $winner['response'];

                $this->fireCallbacks('consensus', $result, $winner['votes'], $this->makerStats['total_steps']);
                $this->fireCallbacks('composition', $originalTask, $result, $this->makerStats['total_steps']);

                $this->recordStep(
                    type: 'compose',
                    task: $originalTask,
                    result: $result,
                    votes: $winner['votes'],
                    totalVotes: $i + 1,
                    candidates: $candidates,
                    depth: $depth,
                );

                return $result;
            }
        }

        $winner = $this->getWinningCandidate($candidates);
        $result = $winner['response'] ?? implode("\n", $subtaskResults);

        $this->fireCallbacks('consensus', $result, $winner['votes'] ?? 0, $this->makerStats['total_steps']);
        $this->fireCallbacks('composition', $originalTask, $result, $this->makerStats['total_steps']);

        $this->recordStep(
            type: 'compose',
            task: $originalTask,
            result: $result,
            votes: $winner['votes'] ?? 0,
            totalVotes: $maxVotes,
            candidates: $candidates,
            depth: $depth,
        );

        return $result;
    }

    /**
     * Streaming variant of composeResults.
     *
     * @param  array<int, string>  $subtaskResults
     *
     * @return \Generator<int, mixed, mixed, string>
     */
    protected function composeResultsStream(
        string $originalTask,
        array $subtaskResults,
        int $depth,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $this->makerStats['compositions']++;
        $this->makerStats['total_steps']++;

        $prompt = $this->buildCompositionPrompt($originalTask, $subtaskResults);
        $k = $this->resolveVotingK();
        $candidates = [];
        $n = $this->calculateMinVotes($k);
        $maxVotes = $this->resolveMaxVotes();

        for ($i = 0; $i < $maxVotes; $i++) {
            yield from $this->fireStreamCallbacks('beforeVote', $prompt, $i + 1, $this->makerStats['total_steps']);

            $response = $this->prompt(
                prompt: $prompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );

            $responseText = $response->text;

            yield from $this->fireStreamCallbacks('afterVote', $responseText, $i + 1, $this->makerStats['total_steps']);
            $this->makerStats['votes_cast']++;

            if ($this->isRedFlaggingEnabled() && RedFlagDetector::hasRedFlags($responseText)) {
                $score = RedFlagDetector::detect($responseText);
                $this->makerStats['red_flags_detected']++;
                yield from $this->fireStreamCallbacks('redFlag', $responseText, $score, $this->makerStats['total_steps']);
                continue;
            }

            $candidateKey = $this->hashCandidate($responseText);

            if (! isset($candidates[$candidateKey])) {
                $candidates[$candidateKey] = [
                    'response' => $responseText,
                    'votes' => 0,
                ];
            }

            $candidates[$candidateKey]['votes']++;

            if ($i >= $n - 1 && $this->hasWinner($candidates, $k)) {
                $winner = $this->getWinningCandidate($candidates);
                $result = $winner['response'];

                yield from $this->fireStreamCallbacks('consensus', $result, $winner['votes'], $this->makerStats['total_steps']);
                yield from $this->fireStreamCallbacks('composition', $originalTask, $result, $this->makerStats['total_steps']);

                $this->recordStep(
                    type: 'compose',
                    task: $originalTask,
                    result: $result,
                    votes: $winner['votes'],
                    totalVotes: $i + 1,
                    candidates: $candidates,
                    depth: $depth,
                );

                return $result;
            }
        }

        $winner = $this->getWinningCandidate($candidates);
        $result = $winner['response'] ?? implode("\n", $subtaskResults);

        yield from $this->fireStreamCallbacks('consensus', $result, $winner['votes'] ?? 0, $this->makerStats['total_steps']);
        yield from $this->fireStreamCallbacks('composition', $originalTask, $result, $this->makerStats['total_steps']);

        $this->recordStep(
            type: 'compose',
            task: $originalTask,
            result: $result,
            votes: $winner['votes'] ?? 0,
            totalVotes: $maxVotes,
            candidates: $candidates,
            depth: $depth,
        );

        return $result;
    }

    // ─── Voting Logic ───────────────────────────────────────────────

    /**
     * Check if any candidate has won by first-to-ahead-by-k rule.
     */
    protected function hasWinner(array $candidates, int $k): bool
    {
        if (count($candidates) < 2) {
            return false;
        }

        $votes = array_column($candidates, 'votes');
        rsort($votes);

        $topVotes = $votes[0] ?? 0;
        $secondVotes = $votes[1] ?? 0;

        return ($topVotes - $secondVotes) >= $k;
    }

    /**
     * Get the winning candidate (most votes).
     */
    protected function getWinningCandidate(array $candidates): array
    {
        if (empty($candidates)) {
            return [];
        }

        uasort($candidates, function ($a, $b) {
            return $b['votes'] <=> $a['votes'];
        });

        return reset($candidates);
    }

    // ─── Overridable Methods ────────────────────────────────────────

    /**
     * Determine if task should be decomposed further.
     *
     * Override this method to implement custom decomposition logic.
     */
    protected function shouldDecompose(string $task, int $depth): bool
    {
        if ($depth >= $this->resolveMaxDecompositionDepth()) {
            return false;
        }

        // Heuristics for decomposition
        $taskLength = strlen($task);
        $hasMultipleSteps = preg_match('/\b(then|next|after|finally|also|and then)\b/i', $task);
        $hasEnumeration = preg_match('/\b(first|second|third|1\.|2\.|3\.)\b/i', $task);
        $hasSequence = preg_match('/\b(step|stage|phase)\b/i', $task);

        return $taskLength > 100 || $hasMultipleSteps || $hasEnumeration || $hasSequence;
    }

    /**
     * Build prompt for decomposing a task into subtasks.
     *
     * Override to customize decomposition instructions.
     */
    protected function buildDecompositionPrompt(string $task): string
    {
        return <<<PROMPT
Break down the following task into the smallest possible atomic subtasks.
Each subtask should be simple enough to execute independently.

Task: {$task}

Provide the subtasks as a numbered list:
1. First subtask
2. Second subtask
3. Third subtask
...

Be as granular as possible. Each subtask should be a single, clear action.
PROMPT;
    }

    /**
     * Build prompt for executing an atomic task.
     *
     * Override to customize execution instructions.
     */
    protected function buildExecutionPrompt(string $task): string
    {
        return <<<PROMPT
Execute this single, atomic task and provide the result:

Task: {$task}

Provide a clear, concise answer. Be direct and specific.
PROMPT;
    }

    /**
     * Build prompt for composing subtask results.
     *
     * Override to customize composition instructions.
     *
     * @param  array<int, string>  $subtaskResults
     */
    protected function buildCompositionPrompt(string $originalTask, array $subtaskResults): string
    {
        $resultsText = implode("\n", array_map(
            fn ($i, $result) => ($i + 1) . ". {$result}",
            array_keys($subtaskResults),
            $subtaskResults
        ));

        return <<<PROMPT
Original task: {$originalTask}

Subtask results:
{$resultsText}

Combine these results into a coherent final answer to the original task.
Be concise and clear.
PROMPT;
    }

    /**
     * Parse decomposition response into array of subtasks.
     *
     * Override to customize parsing logic.
     *
     * @return array<int, string>
     */
    protected function parseDecomposition(string $response): array
    {
        // Extract numbered items (1. xxx, 2. xxx, etc.)
        if (preg_match_all('/^\s*\d+\.\s*(.+)$/m', $response, $matches)) {
            return array_map('trim', $matches[1]);
        }

        // Fallback: split by newlines and filter
        $lines = explode("\n", $response);
        $subtasks = array_filter(
            array_map('trim', $lines),
            fn ($line) => ! empty($line) && strlen($line) > 3
        );

        return array_values($subtasks);
    }

    /**
     * Hash a candidate response for comparison.
     *
     * Normalizes the response to detect similar answers.
     */
    protected function hashCandidate(string $response): string
    {
        $normalized = strtolower(preg_replace('/\s+/', ' ', trim($response)));

        return md5($normalized);
    }

    // ─── Helper Methods ─────────────────────────────────────────────

    /**
     * Reset execution statistics.
     */
    protected function resetMakerStats(): void
    {
        $this->makerStats = [
            'total_steps' => 0,
            'atomic_executions' => 0,
            'decompositions' => 0,
            'compositions' => 0,
            'subtasks_created' => 0,
            'votes_cast' => 0,
            'red_flags_detected' => 0,
            'max_depth_reached' => 0,
        ];
    }

    /**
     * Record a step for introspection.
     */
    protected function recordStep(
        string $type,
        string $task,
        ?string $result = null,
        int $votes = 0,
        int $totalVotes = 0,
        array $candidates = [],
        int $depth = 0,
        array $subtasks = [],
    ): void {
        $this->makerSteps[] = new MakerStep(
            iteration: $this->makerStats['total_steps'],
            type: $type,
            task: $task,
            result: $result,
            votes: $votes,
            totalVotes: $totalVotes,
            candidates: $candidates,
            depth: $depth,
            subtasks: $subtasks,
        );
    }

    /**
     * Update max depth reached.
     */
    protected function updateMaxDepth(int $depth): void
    {
        if ($depth > $this->makerStats['max_depth_reached']) {
            $this->makerStats['max_depth_reached'] = $depth;
        }
    }

    /**
     * Check if max iterations reached.
     *
     * @throws MaxIterationsExceededException
     */
    protected function checkMaxIterations(): void
    {
        $maxIterations = $this->resolveMaxMakerIterations();

        if ($this->makerStats['total_steps'] >= $maxIterations) {
            $response = $this->prompt(prompt: 'Max iterations reached.');

            throw new MaxIterationsExceededException($maxIterations, $response);
        }
    }

    /**
     * Calculate minimum votes needed (2k - 1).
     */
    protected function calculateMinVotes(int $k): int
    {
        return 2 * $k - 1;
    }

    // ─── Configuration Resolution ───────────────────────────────────

    /**
     * Resolve voting K from instance or config.
     */
    protected function resolveVotingK(): int
    {
        return $this->votingKValue
            ?? (int) config('agentic.maker.voting_k', 3);
    }

    /**
     * Resolve whether red-flagging is enabled.
     */
    protected function isRedFlaggingEnabled(): bool
    {
        return $this->redFlaggingEnabled
            ?? (bool) config('agentic.maker.enable_red_flagging', true);
    }

    /**
     * Resolve max decomposition depth.
     */
    protected function resolveMaxDecompositionDepth(): int
    {
        return $this->maxDecompDepth
            ?? (int) config('agentic.maker.max_decomposition_depth', 10);
    }

    /**
     * Resolve max iterations.
     */
    protected function resolveMaxMakerIterations(): int
    {
        return $this->maxMakerIters
            ?? (int) config('agentic.maker.max_iterations', 100);
    }

    /**
     * Resolve max votes per decision.
     */
    protected function resolveMaxVotes(): int
    {
        return (int) config('agentic.maker.max_votes', 15);
    }
}
