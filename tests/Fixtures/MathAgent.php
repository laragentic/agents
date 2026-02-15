<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\ReActLoop;
use Stringable;

class MathAgent implements Agent, HasTools
{
    use Promptable, ReActLoop;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a math assistant. You MUST use the calculator tool to solve
        any arithmetic problems. Do not calculate in your head — always use
        the tool for accuracy. After receiving the result from the calculator,
        provide the final answer clearly to the user.
        PROMPT;
    }

    /**
     * @return list<\Laravel\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return [
            new CalculatorTool,
        ];
    }
}
