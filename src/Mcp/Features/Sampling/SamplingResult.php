<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Sampling;

/**
 * Result returned to the server for a sampling/createMessage request.
 */
class SamplingResult
{
    public function __construct(
        public readonly string $role,
        public readonly array $content,
        public readonly string $model,
        public readonly ?string $stopReason = null,
    ) {}

    /**
     * Create a text result.
     */
    public static function text(string $text, string $model, ?string $stopReason = 'endTurn'): self
    {
        return new self(
            role: 'assistant',
            content: ['type' => 'text', 'text' => $text],
            model: $model,
            stopReason: $stopReason,
        );
    }

    /**
     * Serialize to array for the JSON-RPC response.
     */
    public function toArray(): array
    {
        $data = [
            'role' => $this->role,
            'content' => $this->content,
            'model' => $this->model,
        ];

        if ($this->stopReason !== null) {
            $data['stopReason'] = $this->stopReason;
        }

        return $data;
    }
}
