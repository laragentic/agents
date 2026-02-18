<?php

declare(strict_types=1);

namespace Laragentic\Concerns;

use Closure;

/**
 * Provides callback registration methods that are shared across
 * iteration-based loop traits (ReActLoop, ChainOfThoughtLoop).
 *
 * Extracting these into a shared concern prevents PHP fatal errors
 * when multiple loop traits are composed on the same agent class,
 * because PHP de-duplicates traits that appear in multiple used traits.
 */
trait HasIterationCallbacks
{
    use HasCallbacks;

    // ─── Shared Iteration Callback Registration ──────────────────────

    /**
     * Register a callback invoked when the max iteration limit is reached.
     *
     * Receives: (AgentResponse $response, int $totalIterations)
     */
    public function onMaxIterationsReached(Closure $callback): static
    {
        $this->loopCallbacks['maxIterationsReached'][] = $callback;

        return $this;
    }

    /**
     * Register a callback for the start of each iteration.
     *
     * Receives: (int $iteration)
     */
    public function onIterationStart(Closure $callback): static
    {
        $this->loopCallbacks['iterationStart'][] = $callback;

        return $this;
    }

    /**
     * Register a callback for the end of each iteration.
     *
     * Receives: (int $iteration, AgentResponse $response)
     */
    public function onIterationEnd(Closure $callback): static
    {
        $this->loopCallbacks['iterationEnd'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked before executing a tool.
     *
     * Receives: (string $toolName, array $arguments, int $iteration)
     */
    public function onBeforeAction(Closure $callback): static
    {
        $this->loopCallbacks['beforeAction'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked after a tool returns its result.
     *
     * Receives: (string $toolName, array $arguments, string $result, int $iteration)
     */
    public function onAfterAction(Closure $callback): static
    {
        $this->loopCallbacks['afterAction'][] = $callback;

        return $this;
    }
}
