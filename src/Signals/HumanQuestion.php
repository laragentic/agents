<?php

declare(strict_types=1);

namespace Laragentic\Signals;

/**
 * Represents a single question within a structured AskHuman response.
 *
 * The LLM constructs these via the AskHumanTool schema — the developer
 * never instantiates HumanQuestion directly. When the LLM calls the
 * ask_human tool with structured questions, each question object is
 * deserialized into a HumanQuestion via fromArray().
 */
class HumanQuestion
{
    /**
     * @param  list<string>  $options  Available options for choice-type questions.
     */
    public function __construct(
        public readonly QuestionType $type,
        public readonly string $question,
        public readonly array $options = [],
    ) {}

    /**
     * Create a free-text question (open-ended, any input accepted).
     */
    public static function freeText(string $question): static
    {
        return new static(QuestionType::FreeText, $question);
    }

    /**
     * Create a single-choice question (user picks exactly one option).
     *
     * @param  list<string>  $options
     */
    public static function singleChoice(string $question, array $options): static
    {
        return new static(QuestionType::SingleChoice, $question, $options);
    }

    /**
     * Create a multiple-choice question (user picks one or more options).
     *
     * @param  list<string>  $options
     */
    public static function multipleChoice(string $question, array $options): static
    {
        return new static(QuestionType::MultipleChoice, $question, $options);
    }

    /**
     * Deserialize a question from the LLM-provided argument array.
     *
     * @param  array{type: string, question: string, options?: list<string>}  $data
     */
    public static function fromArray(array $data): static
    {
        $type = QuestionType::from($data['type'] ?? 'free_text');
        $question = $data['question'] ?? '';
        $options = $data['options'] ?? [];

        return new static($type, $question, $options);
    }

    /**
     * Serialize to a JSON-compatible array.
     *
     * @return array{type: string, question: string, options: list<string>}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'question' => $this->question,
            'options' => $this->options,
        ];
    }
}
