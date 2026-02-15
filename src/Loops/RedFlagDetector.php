<?php

declare(strict_types=1);

namespace Laragentic\Loops;

/**
 * Detects linguistic markers of uncertainty and circular reasoning
 * in LLM responses, implementing red-flagging from the MAKER paper.
 *
 * Red flags indicate an unreliable response that should be retried.
 */
class RedFlagDetector
{
    /**
     * Uncertainty markers and their weights from the MAKER paper.
     *
     * Higher weights indicate stronger signals of uncertainty.
     *
     * @var array<string, float>
     */
    private const UNCERTAINTY_MARKERS = [
        'wait, maybe' => 1.0,
        'not as we think' => 1.0,
        'let me reconsider' => 1.0,
        'on second thought' => 1.0,
        'i need to rethink' => 1.0,
        'actually' => 0.5,
        'wait' => 0.3,
        'hmm' => 0.3,
        'let me think' => 0.4,
        'i\'m not sure' => 0.8,
        'maybe' => 0.4,
        'perhaps' => 0.3,
        'possibly' => 0.3,
        'i think i made a mistake' => 1.0,
        'that doesn\'t seem right' => 0.7,
    ];

    /**
     * Threshold for red flag score.
     *
     * When the accumulated score reaches this threshold, the response
     * is considered unreliable and should be retried.
     */
    private const RED_FLAG_THRESHOLD = 1.0;

    /**
     * Threshold for circular reasoning detection.
     *
     * If the ratio of unique sentences to total sentences falls below
     * this threshold, circular reasoning is detected.
     */
    private const CIRCULAR_REASONING_THRESHOLD = 0.7;

    /**
     * Detect red flags in a response.
     *
     * Returns the red flag score. A score >= 1.0 indicates the response
     * should be retried.
     */
    public static function detect(string $response): float
    {
        $score = 0.0;

        // Check for uncertainty markers
        $score += self::detectUncertaintyMarkers($response);

        // Check for circular reasoning
        if (self::hasCircularReasoning($response)) {
            $score += 0.5;
        }

        return $score;
    }

    /**
     * Check if the response contains red flags (score >= threshold).
     */
    public static function hasRedFlags(string $response): bool
    {
        return self::detect($response) >= self::RED_FLAG_THRESHOLD;
    }

    /**
     * Detect uncertainty markers and calculate their weighted score.
     */
    private static function detectUncertaintyMarkers(string $response): float
    {
        $lowerResponse = strtolower($response);
        $score = 0.0;

        foreach (self::UNCERTAINTY_MARKERS as $marker => $weight) {
            if (str_contains($lowerResponse, $marker)) {
                $score += $weight;
            }
        }

        return $score;
    }

    /**
     * Detect circular reasoning by checking for repetitive sentences.
     *
     * If a significant portion of sentences are duplicates, this indicates
     * the LLM is stuck in a loop and the response is unreliable.
     */
    public static function hasCircularReasoning(string $response): bool
    {
        // Split into sentences
        $sentences = preg_split('/[.!?]+/', $response, -1, PREG_SPLIT_NO_EMPTY);

        if ($sentences === false || count($sentences) <= 3) {
            // Too short to detect patterns
            return false;
        }

        // Normalize sentences (lowercase, trim)
        $normalized = array_map(
            fn ($s) => strtolower(trim($s)),
            $sentences
        );

        // Calculate uniqueness ratio
        $uniqueSentences = count(array_unique($normalized));
        $totalSentences = count($normalized);

        $uniquenessRatio = $uniqueSentences / $totalSentences;

        return $uniquenessRatio < self::CIRCULAR_REASONING_THRESHOLD;
    }

    /**
     * Get the list of uncertainty markers with their weights.
     *
     * Useful for debugging and testing.
     *
     * @return array<string, float>
     */
    public static function getUncertaintyMarkers(): array
    {
        return self::UNCERTAINTY_MARKERS;
    }

    /**
     * Get the red flag threshold.
     */
    public static function getThreshold(): float
    {
        return self::RED_FLAG_THRESHOLD;
    }
}
