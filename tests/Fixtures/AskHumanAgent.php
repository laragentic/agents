<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Laragentic\Loops\ReActLoop;
use Laragentic\Tools\AskHumanTool;
use Stringable;

/**
 * Integration test fixture that uses AskHumanTool.
 *
 * Strongly instructed to always ask the human for clarification before
 * proceeding, so the LLM reliably triggers ask_human in integration tests.
 */
class AskHumanAgent implements Agent, HasTools
{
    use Promptable, ReActLoop;

    public function __construct(private readonly string $questionMode = 'any') {}

    public function instructions(): Stringable|string
    {
        $modeGuidance = match ($this->questionMode) {
            'structured' => 'You MUST use structured mode. Include at least two questions with at least one '
                . 'single_choice or multiple_choice question with concrete options.',
            'free_text' => 'You MUST use free_text mode with a single focused question.',
            default => 'Choose the mode that best fits the information needed.',
        };

        return <<<PROMPT
        You are a requirements-gathering assistant. Your ONLY job is to call the
        ask_human tool to collect missing information — you NEVER answer directly.

        STRICT RULES:
        1. You MUST call the ask_human tool on your very first response.
        2. You are FORBIDDEN from providing any answer, recommendation, or
           suggestion without first calling ask_human.
        3. You MUST NOT say "I need more information" or explain yourself —
           just call the ask_human tool immediately.

        {$modeGuidance}
        PROMPT;
    }

    /**
     * @return list<\Laravel\Ai\Contracts\Tool>
     */
    public function tools(): iterable
    {
        return [
            new AskHumanTool,
            new CalculatorTool,
        ];
    }
}
