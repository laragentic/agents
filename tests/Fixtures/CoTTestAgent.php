<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Loops\ChainOfThoughtLoop;

/**
 * A test agent that uses ChainOfThoughtLoop with controllable prompt responses.
 *
 * Instead of making real LLM calls, this agent returns pre-configured
 * AgentResponse objects in sequence, allowing us to test loop behavior.
 */
class CoTTestAgent
{
    use ChainOfThoughtLoop;

    /** @var list<AgentResponse> */
    protected array $fakeResponses = [];

    protected int $promptCallCount = 0;

    /** @var list<Tool> */
    protected array $agentTools = [];

    /**
     * Set the sequence of responses the agent will return.
     *
     * @param  list<AgentResponse>  $responses
     */
    public function fakeResponses(array $responses): static
    {
        $this->fakeResponses = $responses;
        $this->promptCallCount = 0;

        return $this;
    }

    /**
     * Set the tools available to this agent.
     *
     * @param  list<Tool>  $tools
     */
    public function withTools(array $tools): static
    {
        $this->agentTools = $tools;

        return $this;
    }

    /**
     * Simulate the prompt method — returns the next fake response.
     */
    public function prompt(
        string $prompt,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        $index = $this->promptCallCount++;

        if (isset($this->fakeResponses[$index])) {
            return $this->fakeResponses[$index];
        }

        // Default: return an empty response (final answer, no tool calls)
        return new AgentResponse(
            invocationId: 'test-' . $index,
            text: 'Final Answer: Default response',
            usage: new Usage(),
            meta: new Meta(),
        );
    }

    /**
     * Return the tools configured for this test agent.
     *
     * @return list<Tool>
     */
    public function tools(): iterable
    {
        return $this->agentTools;
    }

    /**
     * Get the number of times prompt() was called.
     */
    public function getPromptCallCount(): int
    {
        return $this->promptCallCount;
    }
}
