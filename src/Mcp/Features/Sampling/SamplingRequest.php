<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Sampling;

/**
 * A sampling/createMessage request from the MCP server.
 */
class SamplingRequest
{
    public function __construct(
        public readonly array $messages,
        public readonly ?int $maxTokens = null,
        public readonly ?string $systemPrompt = null,
        public readonly ?ModelPreferences $modelPreferences = null,
        public readonly ?string $stopSequence = null,
        public readonly ?array $metadata = null,
        public readonly ?array $tools = null,
        public readonly ?array $toolChoice = null,
    ) {}

    /**
     * Parse from the JSON-RPC request params.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            messages: $data['messages'] ?? [],
            maxTokens: $data['maxTokens'] ?? null,
            systemPrompt: $data['systemPrompt'] ?? null,
            modelPreferences: isset($data['modelPreferences'])
                ? ModelPreferences::fromArray($data['modelPreferences'])
                : null,
            stopSequence: $data['stopSequences'][0] ?? null,
            metadata: $data['metadata'] ?? null,
            tools: $data['tools'] ?? null,
            toolChoice: $data['toolChoice'] ?? null,
        );
    }

    /**
     * Whether this request includes tools for the LLM.
     */
    public function hasTools(): bool
    {
        return ! empty($this->tools);
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $data = ['messages' => $this->messages];

        if ($this->maxTokens !== null) {
            $data['maxTokens'] = $this->maxTokens;
        }

        if ($this->systemPrompt !== null) {
            $data['systemPrompt'] = $this->systemPrompt;
        }

        if ($this->modelPreferences !== null) {
            $data['modelPreferences'] = $this->modelPreferences->toArray();
        }

        if ($this->tools !== null) {
            $data['tools'] = $this->tools;
        }

        if ($this->toolChoice !== null) {
            $data['toolChoice'] = $this->toolChoice;
        }

        return $data;
    }
}
