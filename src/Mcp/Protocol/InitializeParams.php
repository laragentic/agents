<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Protocol;

/**
 * Parameters for the initialize request sent by the client.
 */
class InitializeParams
{
    public function __construct(
        public readonly string $protocolVersion,
        public readonly ClientCapabilities $capabilities,
        public readonly string $clientName,
        public readonly string $clientVersion,
    ) {}

    /**
     * Create from config defaults.
     */
    public static function fromConfig(?ClientCapabilities $capabilities = null): self
    {
        $config = config('agentic.mcp', []);

        return new self(
            protocolVersion: $config['protocol']['version'] ?? ProtocolVersion::LATEST,
            capabilities: $capabilities ?? ClientCapabilities::fromConfig(),
            clientName: $config['client_info']['name'] ?? 'laragentic',
            clientVersion: $config['client_info']['version'] ?? '1.0.0',
        );
    }

    /**
     * Serialize to array for the JSON-RPC request params.
     */
    public function toArray(): array
    {
        return [
            'protocolVersion' => $this->protocolVersion,
            'capabilities' => $this->capabilities->toArray(),
            'clientInfo' => [
                'name' => $this->clientName,
                'version' => $this->clientVersion,
            ],
        ];
    }
}
