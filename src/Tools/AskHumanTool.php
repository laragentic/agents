<?php

declare(strict_types=1);

namespace Laragentic\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laragentic\Signals\AskHumanSignal;
use Laragentic\Signals\HumanQuestion;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Built-in Laragentic tool that lets the LLM ask the human for clarification.
 *
 * Add this to your agent's tools() to give the LLM the ability to pause
 * the loop and request human input. The tool's schema teaches the LLM
 * exactly how to structure the questions — the LLM decides at runtime
 * what to ask and in what format. Developers never write question code.
 *
 * The LLM will call this tool in one of two modes:
 *
 *   Free-text (default):
 *     {"mode": "free_text", "question": "What city should I check the weather for?"}
 *
 *   Structured (when specific choices are appropriate):
 *     {
 *       "mode": "structured",
 *       "questions": [
 *         {"type": "single_choice", "question": "Preferred unit?", "options": ["Celsius", "Fahrenheit"]},
 *         {"type": "multiple_choice", "question": "Features needed?", "options": ["Auth", "Payments"]},
 *         {"type": "free_text", "question": "Any other requirements?"}
 *       ]
 *     }
 *
 * Usage:
 *
 *     class MyAgent implements Agent {
 *         public function tools(): iterable {
 *             return [
 *                 new AskHumanTool(),
 *                 new WeatherTool(),
 *             ];
 *         }
 *     }
 *
 *     $result = (new MyAgent)
 *         ->onAskHuman(fn (AskHumanSignal $signal) => broadcast(new HumanInputRequired($signal->toArray())))
 *         ->reactLoop('What should I wear today?');
 *
 *     if ($result->askedHuman()) {
 *         return response()->json($result->askHumanSignal->toArray());
 *     }
 */
class AskHumanTool implements Tool
{
    public function name(): string
    {
        return 'ask_human';
    }

    public function description(): Stringable|string
    {
        return 'Ask the human for clarification or additional information needed to '
            . 'complete the task. Use this when you cannot proceed without input from '
            . 'the user. Choose free_text for open-ended questions or structured to '
            . 'present the human with specific choices (dropdowns, checkboxes, etc). '
            . 'Calling this tool immediately pauses the current task.';
    }

    /**
     * Build an AskHumanSignal from the LLM-provided arguments.
     *
     * The loop detects the returned AskHumanSignal (instanceof check before
     * string cast), fires the onAskHuman callback, and terminates without
     * making another LLM call.
     */
    public function handle(Request $request): AskHumanSignal
    {
        $mode = $request['mode'] ?? 'free_text';

        if ($mode === 'structured') {
            $rawQuestions = $request['questions'] ?? [];

            $questions = array_map(
                fn (array $q) => HumanQuestion::fromArray($q),
                $rawQuestions,
            );

            return AskHumanSignal::structured($questions);
        }

        return AskHumanSignal::freeText($request['question'] ?? '');
    }

    /**
     * Define the tool schema so the LLM knows exactly how to call it.
     *
     * The schema describes both question modes and all supported question
     * types, giving the LLM full control over what to ask at runtime.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()
                ->enum(['free_text', 'structured'])
                ->description('The question mode. Use free_text for a single open-ended question, or structured to present multiple questions with specific input types.')
                ->required(),

            'question' => $schema->string()
                ->description('The question to ask the human. Required when mode is free_text.'),

            'questions' => $schema->array()
                ->items(
                    $schema->object([
                        'type' => $schema->string()
                            ->enum(['free_text', 'single_choice', 'multiple_choice'])
                            ->description('The input type: free_text for open input, single_choice for one selection, multiple_choice for multiple selections.')
                            ->required(),

                        'question' => $schema->string()
                            ->description('The question text to display to the human.')
                            ->required(),

                        'options' => $schema->array()
                            ->items($schema->string())
                            ->description('The selectable options. Required for single_choice and multiple_choice types.'),
                    ])
                )
                ->description('The list of questions to ask. Required when mode is structured.'),
        ];
    }
}
