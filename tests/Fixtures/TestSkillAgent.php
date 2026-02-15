<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laragentic\Loops\ReActLoop;
use Laragentic\Skills\HasAgentSkills;

/**
 * Test agent with Skills support for feature testing.
 */
class TestSkillAgent
{
    use ReActLoop, HasAgentSkills;

    protected int $promptCallCount = 0;

    /**
     * Base instructions for the agent (without skills).
     */
    protected function baseInstructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the agent instructions (enhanced with skills if loaded).
     */
    public function instructions(?string $query = null): string
    {
        return $this->enhanceInstructionsWithSkills(
            $this->baseInstructions(),
            $query ?? ''
        );
    }

    /**
     * Simulate the prompt method.
     */
    public function prompt(
        string $prompt,
        array $attachments = [],
        mixed $provider = null,
        ?string $model = null,
        ?int $timeout = null,
    ): AgentResponse {
        $this->promptCallCount++;

        // Return a simple response for testing
        return new AgentResponse(
            invocationId: 'test-' . $this->promptCallCount,
            text: 'Test response',
            usage: new Usage(),
            meta: new Meta(),
        );
    }

    /**
     * Get the number of times prompt() was called.
     */
    public function getPromptCallCount(): int
    {
        return $this->promptCallCount;
    }
}
