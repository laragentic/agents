<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Concerns\HasDurableRuns;
use Laragentic\Loops\ReActLoop;

/**
 * Test agent that composes ReActLoop + HasDurableRuns with faked LLM responses.
 */
class DurableTestAgent
{
    use ReActLoop;
    use HasDurableRuns;

    /** @var list<AgentResponse> */
    protected array $fakeResponses = [];

    protected int $promptCallCount = 0;

    /** @var list<Tool> */
    protected array $agentTools = [];

    /**
     * @param  list<AgentResponse>  $responses
     */
    public function fakeResponses(array $responses): static
    {
        $this->fakeResponses = $responses;
        $this->promptCallCount = 0;

        return $this;
    }

    public function withTools(array $tools): static
    {
        $this->agentTools = $tools;

        return $this;
    }

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

        return new AgentResponse(
            invocationId: 'durable-test-' . $index,
            text: '',
            usage: new Usage(),
            meta: new Meta(),
        );
    }

    public function tools(): iterable
    {
        return $this->agentTools;
    }

    public function getPromptCallCount(): int
    {
        return $this->promptCallCount;
    }
}
