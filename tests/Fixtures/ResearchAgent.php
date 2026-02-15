<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\PlanExecuteLoop;
use Stringable;

class ResearchAgent implements Agent, HasTools
{
    use Promptable, PlanExecuteLoop;

    public function instructions(): Stringable|string
    {
        return <<<'PROMPT'
        You are a research assistant. When given a task, create a clear plan and
        execute each step methodically. Use the calculator tool for any arithmetic.
        Be thorough but concise in your responses.
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
