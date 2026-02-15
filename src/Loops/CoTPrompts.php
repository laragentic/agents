<?php

declare(strict_types=1);

namespace Laragentic\Loops;

/**
 * Provides prompt templates for Chain-of-Thought reasoning with self-reflection.
 *
 * These prompts guide the LLM to:
 * - Reason through problems step-by-step
 * - Evaluate its own understanding
 * - Determine when it has sufficient confidence to provide a final answer
 */
class CoTPrompts
{
    /**
     * Get the core reasoning guidance to include in system instructions.
     *
     * This instructs the LLM to think systematically and evaluate
     * its understanding after each reasoning step.
     */
    public static function reasoningGuidance(): string
    {
        return <<<'PROMPT'
You are an expert problem solver who uses Chain-of-Thought reasoning. When analyzing problems:

1. **Break down the problem**: Identify what is being asked and what information you have.
2. **Reason step-by-step**: Work through the problem methodically, showing your thinking.
3. **Identify gaps**: Be explicit about what you know and what you still need.
4. **Use tools when needed**: If you need information, call the appropriate tools.
5. **Evaluate your understanding**: After each step, assess whether you can solve the problem.
6. **Be honest about confidence**: Only provide a final answer when you're confident.

Your reasoning should be clear, systematic, and build progressively toward the solution.
PROMPT;
    }

    /**
     * Get instructions for self-evaluation and confidence assessment.
     *
     * This guides the LLM to explicitly check if it has enough understanding
     * to solve the problem or if more reasoning/information is needed.
     */
    public static function confidenceEvaluation(): string
    {
        return <<<'PROMPT'
After reasoning through this step, evaluate your understanding:

**Confidence Check:**
- Do I understand all aspects of this problem?
- Do I have all the information I need?
- Can I confidently provide a complete answer?

If YES: Provide your final answer with complete reasoning.
If NO: Explain what you still need to understand or what information is missing.
PROMPT;
    }

    /**
     * Build a reflection prompt for continuing reasoning after an iteration.
     *
     * This prompt is used after each iteration to guide the LLM to:
     * - Consider new information from tool calls
     * - Reflect on current understanding
     * - Decide whether to continue reasoning or provide final answer
     */
    public static function reflectionPrompt(?string $observation = null): string
    {
        $prompt = "Continue your reasoning from where you left off.\n\n";

        if ($observation !== null) {
            $prompt .= "**New Information:**\n{$observation}\n\n";
        }

        $prompt .= <<<'PROMPT'
**Reflect on your current understanding:**
- What do I now know about this problem?
- What gaps or uncertainties remain?
- Do I need more information? If so, what?
- Am I confident I can solve this problem?

If you are confident and have sufficient understanding, provide your **Final Answer** with complete reasoning.

If you need more information or deeper analysis, continue reasoning and use available tools as needed.
PROMPT;

        return $prompt;
    }

    /**
     * Get guidance about tool usage during reasoning.
     *
     * This reminds the LLM that tools are available for gathering
     * information during the reasoning process.
     */
    public static function toolGuidance(): string
    {
        return <<<'PROMPT'
**Available Tools:**
You have access to tools that can help gather information. Use them when:
- You need external data or calculations
- You're uncertain about facts
- Information would strengthen your reasoning

Don't hesitate to call tools multiple times if needed. Each tool call helps build your understanding.
PROMPT;
    }

    /**
     * Get the marker text that indicates final answer in responses.
     *
     * Used for detecting when the agent has reached a conclusion.
     */
    public static function finalAnswerMarker(): string
    {
        return 'Final Answer:';
    }

    /**
     * Get common confidence markers to look for in responses.
     *
     * These phrases indicate the agent is confident in its understanding.
     *
     * @return array<int, string>
     */
    public static function confidenceMarkers(): array
    {
        return [
            'i am confident',
            'i now understand',
            'i have sufficient understanding',
            'i can confidently',
            'final answer:',
        ];
    }

    /**
     * Get uncertainty markers that indicate more reasoning is needed.
     *
     * These phrases suggest the agent needs more information or analysis.
     *
     * @return array<int, string>
     */
    public static function uncertaintyMarkers(): array
    {
        return [
            'i need more information',
            'i am uncertain',
            'i still need to',
            'what i still need',
            'gaps remain',
            'i don\'t yet know',
        ];
    }
}
