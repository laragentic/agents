<?php

declare(strict_types=1);

namespace Laragentic\Concerns;

use Closure;
use Laragentic\Signals\AskHumanSignal;

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

    /**
     * Register a callback invoked for each text token during streaming.
     *
     * Only fires in streaming loop variants (e.g. reactLoopStream).
     * Receives: (string $delta, int $iteration)
     */
    public function onTextDelta(Closure $callback): static
    {
        $this->loopCallbacks['textDelta'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when the LLM calls the ask_human tool.
     *
     * The loop fires this callback then terminates immediately — no second
     * LLM iteration occurs. Use this to broadcast the questions to your
     * frontend, store the pending state, etc.
     *
     * Receives: (AskHumanSignal $signal, int $iteration, AgentResponse $response)
     *
     * The AgentResponse is the last LLM response before the loop paused.
     * Use $response->conversationId to persist the conversation across
     * the ask-human round-trip (the RememberConversation middleware sets
     * this value before the ask_human callback fires).
     */
    public function onAskHuman(Closure $callback): static
    {
        $this->loopCallbacks['askHuman'][] = $callback;

        return $this;
    }

    /**
     * Register a callback invoked when a tool returns a PausesLoop result.
     *
     * The loop fires this callback then terminates — the tool requires
     * human interaction (e.g. an interactive iframe) before the loop
     * can continue. Use this to emit events to the frontend, store
     * deferred tool info in the conversation, etc.
     *
     * Receives: (PausesLoop $signal, array $deferredTools, int $iteration, AgentResponse $response)
     *
     * $deferredTools is an array of tool names the LLM requested but
     * were not executed because the loop paused on an earlier tool.
     */
    public function onPause(Closure $callback): static
    {
        $this->loopCallbacks['pause'][] = $callback;

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
