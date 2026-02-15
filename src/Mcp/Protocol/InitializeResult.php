<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Protocol;

/**
 * Parsed result from the server's initialize response.
 */
class InitializeResult
{
    public function __construct(
        public readonly string $protocolVersion,
        public readonly ServerCapabilities $capabilities,
        public readonly string $serverName,
        public readonly string $serverVersion,
        public readonly ?string $instructions = null,
    ) {}

    /**
     * Parse from the JSON-RPC result object.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            protocolVersion: $data['protocolVersion'],
            capabilities: ServerCapabilities::fromArray($data['capabilities'] ?? []),
            serverName: $data['serverInfo']['name'] ?? 'unknown',
            serverVersion: $data['serverInfo']['version'] ?? 'unknown',
            instructions: $data['instructions'] ?? null,
        );
    }

    /**
     * Serialize to array.
     */
    public function toArray(): array
    {
        $result = [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities->toArray(),
            'serverInfo' => [
                'name' => $this->serverName,
                'version' => $this->serverVersion,
            ],
        ];

        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }

        return $result;
    }
}
