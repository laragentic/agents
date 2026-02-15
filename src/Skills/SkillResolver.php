<?php

declare(strict_types=1);

namespace Laragentic\Skills;

/**
 * Resolves relevant skills for a given query using scoring algorithms.
 *
 * The resolver implements the progressive disclosure pattern:
 * 1. Load only skill summaries (name + description)
 * 2. Score each skill against the query
 * 3. Return top-scoring skills above threshold
 * 4. Full instructions are loaded only for selected skills
 */
final class SkillResolver
{
    /**
     * @var array<string>  Common words to ignore in relevance scoring
     */
    private const STOP_WORDS = [
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for',
        'of', 'with', 'by', 'from', 'as', 'is', 'was', 'are', 'were', 'be',
        'been', 'being', 'have', 'has', 'had', 'do', 'does', 'did', 'will',
        'would', 'should', 'could', 'may', 'might', 'can', 'this', 'that',
    ];

    /**
     * Resolve the most relevant skills for a query.
     *
     * @param  array<string, array{name: string, description: string, tags: array<string>}>  $availableSkills  Skill summaries keyed by name
     * @param  float  $threshold  Minimum score (0.0 - 1.0) to include a skill
     * @param  int  $limit  Maximum number of skills to return
     * @return array<string>  Skill names ordered by relevance (highest first)
     */
    public function resolve(
        array $availableSkills,
        string $query,
        float $threshold = 0.3,
        int $limit = 3,
    ): array {
        $scores = [];

        foreach ($availableSkills as $name => $summary) {
            $score = $this->calculateRelevance($summary, $query);

            if ($score >= $threshold) {
                $scores[$name] = $score;
            }
        }

        // Sort by score descending
        arsort($scores);

        // Return top N skill names
        return array_slice(array_keys($scores), 0, $limit);
    }

    /**
     * Calculate relevance score for a skill against a query.
     *
     * Scoring algorithm:
     * - Exact name match: +1.0
     * - Partial name match: +0.5
     * - Description keyword match: +0.7 per keyword
     * - Tag match: +0.5 per tag
     *
     * @param  array{name: string, description: string, tags: array<string>}  $summary
     */
    public function calculateRelevance(array $summary, string $query): float
    {
        $score = 0.0;
        $queryLower = strtolower($query);
        $queryWords = $this->extractKeywords($queryLower);

        $nameLower = strtolower($summary['name']);
        $descriptionLower = strtolower($summary['description']);

        // Exact name match
        if (str_contains($queryLower, $nameLower)) {
            $score += 1.0;
        }

        // Partial name match (any word from name in query)
        $nameWords = $this->extractKeywords($nameLower);
        foreach ($nameWords as $word) {
            if (in_array($word, $queryWords, true)) {
                $score += 0.5;
                break;
            }
        }

        // Description keyword matches
        $descriptionWords = $this->extractKeywords($descriptionLower);
        $matchedKeywords = array_intersect($queryWords, $descriptionWords);
        $score += count($matchedKeywords) * 0.7;

        // Tag matches
        foreach ($summary['tags'] as $tag) {
            $tagLower = strtolower($tag);
            if (in_array($tagLower, $queryWords, true)) {
                $score += 0.5;
            }
        }

        // Normalize score to 0-1 range (cap at 1.0)
        return min(1.0, $score);
    }

    /**
     * Extract meaningful keywords from text.
     *
     * Filters out:
     * - Stop words (common words like "the", "and", etc.)
     * - Short words (< 3 characters)
     * - Punctuation
     *
     * @return array<string>
     */
    private function extractKeywords(string $text): array
    {
        // Remove punctuation and split into words
        $text = preg_replace('/[^\w\s-]/', ' ', $text);
        $words = preg_split('/\s+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        // Filter stop words and short words
        return array_values(array_filter(
            $words,
            fn ($word) => strlen($word) >= 3 && ! in_array($word, self::STOP_WORDS, true)
        ));
    }
}
