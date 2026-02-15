<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Sampling;

/**
 * Model preferences from an MCP sampling request.
 */
class ModelPreferences
{
    public function __construct(
        public readonly array $hints = [],
        public readonly ?float $costPriority = null,
        public readonly ?float $speedPriority = null,
        public readonly ?float $intelligencePriority = null,
    ) {}

    /**
     * Parse from the sampling request model preferences.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            hints: $data['hints'] ?? [],
            costPriority: $data['costPriority'] ?? null,
            speedPriority: $data['speedPriority'] ?? null,
            intelligencePriority: $data['intelligencePriority'] ?? null,
        );
    }

    /**
     * Get model name hints for matching.
     */
    public function modelHints(): array
    {
        return array_map(fn ($hint) => $hint['name'] ?? '', $this->hints);
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = [];

        if (! empty($this->hints)) {
            $data['hints'] = $this->hints;
        }

        if ($this->costPriority !== null) {
            $data['costPriority'] = $this->costPriority;
        }

        if ($this->speedPriority !== null) {
            $data['speedPriority'] = $this->speedPriority;
        }

        if ($this->intelligencePriority !== null) {
            $data['intelligencePriority'] = $this->intelligencePriority;
        }

        return $data;
    }
}
