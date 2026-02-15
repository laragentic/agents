<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Laragentic\Loops\MakerLoop;

/**
 * Factorial calculation agent using MAKER loop.
 *
 * Used for integration tests with real LLM calls.
 *
 * Example:
 *     $agent = new FactorialAgent();
 *     $result = $agent->makerLoop('Calculate 5! step by step');
 */
class FactorialAgent implements Agent
{
    use MakerLoop;
    use Promptable;

    public function instructions(): string
    {
        return <<<INSTRUCTIONS
You are a mathematical computation assistant specialized in factorial calculations.

When asked to calculate factorials:
- Break down the calculation into clear steps
- Show your work step by step
- Provide the final numerical answer

For decomposition tasks:
- Break complex calculations into smaller steps
- Each step should be a single multiplication or sub-calculation

For atomic execution tasks:
- Perform the calculation and return just the numerical result
- Be precise and accurate

For composition tasks:
- Combine the step results into a final answer
- Show the complete calculation path
INSTRUCTIONS;
    }
}
