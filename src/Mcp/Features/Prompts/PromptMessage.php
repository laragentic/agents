<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Prompts;

/**
 * A message from an MCP prompt response.
 */
class PromptMessage
{
    public function __construct(
        public readonly string $role,
        public readonly array $content,
    ) {}

    /**
     * Parse from the prompts/get response message.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'],
            content: $data['content'] ?? [],
        );
    }

    /**
     * Get the text content of this message.
     */
    public function text(): string
    {
        if (is_string($this->content)) {
            return $this->content;
        }

        $parts = [];
        foreach ($this->content as $item) {
            if (is_string($item)) {
                $parts[] = $item;
            } elseif (($item['type'] ?? '') === 'text') {
                $parts[] = $item['text'] ?? '';
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Whether this is a user message.
     */
    public function isUser(): bool
    {
        return $this->role === 'user';
    }

    /**
     * Whether this is an assistant message.
     */
    public function isAssistant(): bool
    {
        return $this->role === 'assistant';
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
        ];
    }
}
