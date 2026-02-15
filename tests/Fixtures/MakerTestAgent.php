<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\AgentResponse;
use Laragentic\Loops\MakerLoop;

/**
 * Test agent for MAKER loop with controllable responses.
 *
 * Allows tests to fake LLM responses to verify loop logic
 * without making real API calls.
 */
class MakerTestAgent implements Agent
{
    use MakerLoop;
    use Promptable;

    /**
     * Fake responses to return in sequence.
     *
     * @var array<int, string>
     */
    protected array $fakeResponses = [];

    /**
     * Current index in fake responses array.
     */
    protected int $responseIndex = 0;

    /**
     * Set fake responses for testing.
     *
     * @param  array<int, string>  $responses
     */
    public function fakeResponses(array $responses): self
    {
        $this->fakeResponses = $responses;
        $this->responseIndex = 0;

        return $this;
    }

    /**
     * Override prompt to return fake responses.
     */
    public function prompt(
        string $prompt,
        ?string $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        if (empty($this->fakeResponses)) {
            return parent::prompt($prompt, $provider, $model, $timeout);
        }

        $response = $this->fakeResponses[$this->responseIndex] ?? 'No more fake responses';
        $this->responseIndex++;

        return new AgentResponse(
            text: $response,
            toolCalls: collect([]),
            toolResults: collect([]),
            usage: null,
            conversationId: null,
        );
    }

    public function instructions(): string
    {
        return 'You are a test agent for MAKER loop verification.';
    }
}
