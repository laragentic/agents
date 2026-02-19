<?php

declare(strict_types=1);

namespace Laragentic\Signals;

/**
 * Signal returned by AskHumanTool to interrupt the agentic loop and
 * request input from the human.
 *
 * When the LLM determines it needs clarification, it calls the ask_human
 * tool. AskHumanTool::handle() builds this signal from the LLM-provided
 * arguments. The loop detects it (instanceof check before string cast),
 * fires the onAskHuman callback, and terminates without a second LLM call.
 *
 * Two modes:
 *   - Free-text: a single open-ended question string
 *   - Structured: an ordered list of HumanQuestion objects that the
 *     frontend can render as specific input widgets (dropdowns, checkboxes, etc.)
 *
 * Implements Stringable so it can be safely cast to string wherever the
 * loop stores tool results. The string representation is a JSON marker
 * that PlanExecuteLoop uses to detect the signal in SDK-resolved toolResults.
 */
class AskHumanSignal implements \Stringable
{
    private const MARKER_KEY = '__laragentic_ask_human';

    /**
     * @param  list<HumanQuestion>  $questions  Populated for structured mode.
     */
    public function __construct(
        private readonly string $mode,
        private readonly ?string $freeTextQuestion = null,
        private readonly array $questions = [],
    ) {}

    /**
     * Create a simple free-text signal — the LLM provides one open question.
     */
    public static function freeText(string $question): static
    {
        return new static('free_text', freeTextQuestion: $question);
    }

    /**
     * Create a structured signal — the LLM provides an ordered list of questions.
     *
     * @param  list<HumanQuestion>  $questions
     */
    public static function structured(array $questions): static
    {
        return new static('structured', questions: $questions);
    }

    /**
     * Determine if this signal carries structured questions.
     */
    public function isStructured(): bool
    {
        return $this->mode === 'structured';
    }

    /**
     * Get the free-text question string (null for structured mode).
     */
    public function getFreeTextQuestion(): ?string
    {
        return $this->freeTextQuestion;
    }

    /**
     * Get the structured questions (empty array for free-text mode).
     *
     * @return list<HumanQuestion>
     */
    public function getQuestions(): array
    {
        return $this->questions;
    }

    /**
     * Serialize to a JSON-compatible array suitable for API responses.
     *
     * @return array{mode: string, question: string|null, questions: list<array{type: string, question: string, options: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'question' => $this->freeTextQuestion,
            'questions' => array_map(fn (HumanQuestion $q) => $q->toArray(), $this->questions),
        ];
    }

    /**
     * Check whether a string value is an AskHumanSignal JSON marker.
     *
     * Used by PlanExecuteLoop to detect signals in SDK-resolved toolResults,
     * where the loop does not call executeLoopTool() itself.
     */
    public static function isSignal(string $value): bool
    {
        if (! str_starts_with(trim($value), '{')) {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && ($decoded[self::MARKER_KEY] ?? false) === true;
    }

    /**
     * Deserialize an AskHumanSignal from its JSON marker string.
     *
     * @throws \InvalidArgumentException  If the string is not a valid signal.
     */
    public static function fromString(string $json): static
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data[self::MARKER_KEY] ?? false) !== true) {
            throw new \InvalidArgumentException('String is not a valid AskHumanSignal marker.');
        }

        $mode = $data['mode'] ?? 'free_text';

        if ($mode === 'structured') {
            $questions = array_map(
                fn (array $q) => HumanQuestion::fromArray($q),
                $data['questions'] ?? [],
            );

            return static::structured($questions);
        }

        return static::freeText($data['question'] ?? '');
    }

    /**
     * Serialize to the JSON marker string used as a tool result placeholder.
     *
     * The marker key ensures this string is unambiguously identifiable
     * across all loop types.
     */
    public function __toString(): string
    {
        $payload = [
            self::MARKER_KEY => true,
            'mode' => $this->mode,
            'question' => $this->freeTextQuestion,
            'questions' => array_map(fn (HumanQuestion $q) => $q->toArray(), $this->questions),
        ];

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }
}
