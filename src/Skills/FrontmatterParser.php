<?php

declare(strict_types=1);

namespace Laragentic\Skills;

use Laragentic\Exceptions\SkillValidationException;

/**
 * Parses YAML frontmatter from SKILL.md files.
 *
 * Frontmatter format:
 *
 *     ---
 *     name: skill-name
 *     description: Skill description
 *     tags: [tag1, tag2]
 *     version: 1.0.0
 *     author: Author Name
 *     ---
 *
 *     # Skill Instructions
 *     ...
 */
final class FrontmatterParser
{
    /**
     * Parse a SKILL.md file and extract frontmatter + content.
     *
     * @return array{metadata: array<string, mixed>, content: string}
     *
     * @throws SkillValidationException
     */
    public static function parse(string $markdown): array
    {
        $lines = explode("\n", $markdown);

        // Check for frontmatter delimiter
        if (! isset($lines[0]) || trim($lines[0]) !== '---') {
            throw new SkillValidationException(
                'Skill file must start with YAML frontmatter delimiter (---)'
            );
        }

        // Find the closing delimiter
        $closingIndex = null;
        for ($i = 1; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '---') {
                $closingIndex = $i;
                break;
            }
        }

        if ($closingIndex === null) {
            throw new SkillValidationException(
                'Skill file frontmatter must have closing delimiter (---)'
            );
        }

        // Extract YAML content
        $yamlLines = array_slice($lines, 1, $closingIndex - 1);
        $yamlContent = implode("\n", $yamlLines);

        // Parse YAML (simple key-value parser for basic needs)
        $metadata = self::parseYaml($yamlContent);

        // Extract remaining content
        $contentLines = array_slice($lines, $closingIndex + 1);
        $content = trim(implode("\n", $contentLines));

        return [
            'metadata' => $metadata,
            'content' => $content,
        ];
    }

    /**
     * Simple YAML parser for frontmatter.
     *
     * Supports:
     * - key: value
     * - key: [val1, val2]
     * - key: |
     *     multiline
     *     value
     *
     * @return array<string, mixed>
     */
    private static function parseYaml(string $yaml): array
    {
        $data = [];
        $lines = explode("\n", $yaml);
        $currentKey = null;
        $multilineValue = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines and comments
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // Check for key: value
            if (str_contains($trimmed, ':')) {
                // Save any pending multiline value
                if ($currentKey !== null && ! empty($multilineValue)) {
                    $data[$currentKey] = trim(implode("\n", $multilineValue));
                    $multilineValue = [];
                    $currentKey = null;
                }

                [$key, $value] = explode(':', $trimmed, 2);
                $key = trim($key);
                $value = trim($value);

                // Handle different value types
                if ($value === '|' || $value === '>') {
                    // Multiline value starts
                    $currentKey = $key;
                    continue;
                }

                if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
                    // Array value
                    $value = substr($value, 1, -1);
                    $data[$key] = array_map('trim', explode(',', $value));
                } elseif ($value === 'true' || $value === 'false') {
                    // Boolean value
                    $data[$key] = $value === 'true';
                } elseif (is_numeric($value)) {
                    // Numeric value
                    $data[$key] = str_contains($value, '.') ? (float) $value : (int) $value;
                } else {
                    // String value (remove quotes if present)
                    $data[$key] = trim($value, '"\'');
                }
            } elseif ($currentKey !== null) {
                // Continuation of multiline value
                $multilineValue[] = $line;
            }
        }

        // Save any pending multiline value
        if ($currentKey !== null && ! empty($multilineValue)) {
            $data[$currentKey] = trim(implode("\n", $multilineValue));
        }

        return $data;
    }
}
