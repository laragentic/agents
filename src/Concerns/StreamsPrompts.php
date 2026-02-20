<?php

declare(strict_types=1);

namespace Laragentic\Concerns;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;

/**
 * Provides a streaming prompt helper for loop traits.
 *
 * Used by streaming loop variants (e.g. reactLoopStream) to optionally
 * enable token-by-token LLM output via `$this->stream()`. Only activates
 * when the agent supports streaming AND has `onTextDelta` callbacks
 * registered. Otherwise, falls back to the standard `$this->prompt()`.
 *
 * The final AgentResponse is accessible via Generator::getReturn()
 * after the generator completes. Usage:
 *
 *     $gen = $this->streamingPrompt($prompt, $iteration, ...);
 *     yield from $gen;
 *     $response = $gen->getReturn();
 */
trait StreamsPrompts
{
    /**
     * Execute a prompt, using token streaming only when appropriate.
     *
     * Token streaming activates when both conditions are met:
     * 1. The agent has a stream() method (e.g. via Promptable)
     * 2. At least one onTextDelta callback is registered
     *
     * Otherwise, falls back to the standard prompt() call.
     *
     * @return \Generator<int, mixed, mixed, AgentResponse>
     */
    protected function streamingPrompt(
        string $prompt,
        int $iteration,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): \Generator {
        $shouldStream = method_exists($this, 'stream')
            && ! empty($this->loopCallbacks['textDelta'] ?? []);

        if (! $shouldStream) {
            return $this->prompt(
                prompt: $prompt,
                provider: $provider,
                model: $model,
                timeout: $timeout,
            );
        }

        $streamable = $this->stream(
            prompt: $prompt,
            provider: $provider,
            model: $model,
            timeout: $timeout,
        );

        $agentResponse = null;

        $streamable->then(function ($response) use (&$agentResponse) {
            $agentResponse = $response;
        });

        foreach ($streamable as $event) {
            if ($event instanceof TextDelta) {
                yield from $this->fireStreamCallbacks('textDelta', $event->delta, $iteration);
            }
        }

        return $agentResponse;
    }
}
