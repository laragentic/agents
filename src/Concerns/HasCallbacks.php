<?php

declare(strict_types=1);

namespace Laragentic\Concerns;

use Closure;

/**
 * Provides a generic callback registration and dispatch mechanism
 * for agentic loop traits.
 *
 * Each loop trait (ReActLoop, PlanExecuteLoop, etc.) uses this concern
 * for its core wiring and adds its own phase-specific callbacks.
 */
trait HasCallbacks
{
    /**
     * Registered lifecycle callbacks keyed by event name.
     *
     * @var array<string, list<Closure>>
     */
    protected array $loopCallbacks = [];

    // ─── Shared Loop-Level ──────────────────────────────────────────

    /**
     * Register a callback for the start of the loop.
     *
     * Receives: (string $prompt)
     */
    public function onLoopStart(Closure $callback): static
    {
        $this->loopCallbacks['loopStart'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when the loop completes successfully.
     *
     * Receives: (AgentResponse $response, int $totalSteps)
     */
    public function onLoopComplete(Closure $callback): static
    {
        $this->loopCallbacks['loopComplete'][] = $callback;

        return $this;
    }

    // ─── Internals ──────────────────────────────────────────────────

    /**
     * Fire all registered callbacks for the given event.
     */
    protected function fireCallbacks(string $event, mixed ...$args): void
    {
        foreach ($this->loopCallbacks[$event] ?? [] as $callback) {
            $result = $callback(...$args);

            // If the callback is a Generator function, iterate it
            // to ensure its body executes (but discard yielded values).
            if ($result instanceof \Generator) {
                iterator_to_array($result);
            }
        }
    }

    /**
     * Fire callbacks for streaming contexts, yielding any values
     * produced by Generator callbacks.
     *
     * This is used by streaming loop methods (e.g. reactLoopStream)
     * so that `yield new StreamedEvent(...)` inside a callback
     * propagates to the parent Generator and out to the HTTP response.
     */
    protected function fireStreamCallbacks(string $event, mixed ...$args): \Generator
    {
        foreach ($this->loopCallbacks[$event] ?? [] as $callback) {
            $result = $callback(...$args);

            if ($result instanceof \Generator) {
                yield from $result;
            }
        }
    }
}
