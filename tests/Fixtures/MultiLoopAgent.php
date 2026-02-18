<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Loops\ChainOfThoughtLoop;
use Laragentic\Loops\PlanExecuteLoop;
use Laragentic\Loops\ReActLoop;

/**
 * A test agent that combines all three loop traits simultaneously.
 *
 * This fixture verifies that there are no PHP trait method collision errors
 * when ReActLoop, ChainOfThoughtLoop, and PlanExecuteLoop are composed on
 * the same agent class.
 */
class MultiLoopAgent
{
    use ReActLoop;
    use ChainOfThoughtLoop;
    use PlanExecuteLoop;

    /** @var list<AgentResponse> */
    protected array $fakeResponses = [];

    protected int $promptCallCount = 0;

    /** @var list<Tool> */
    protected array $agentTools = [];

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
            invocationId: 'test-' . $index,
            text: 'Final Answer: Default response',
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
