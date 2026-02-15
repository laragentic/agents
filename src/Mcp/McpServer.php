<?php

declare(strict_types=1);

namespace Laragentic\Mcp;

/**
 * MCP server configuration value object.
 *
 * Defines how to connect to an MCP server, including transport type
 * and connection parameters.
 */
class McpServer
{
    public function __construct(
        public readonly string $name,
        public readonly string $transport,
        public readonly string $command = '',
        public readonly array $args = [],
        public readonly ?string $url = null,
        public readonly ?array $env = null,
        public readonly ?string $cwd = null,
        public readonly array $headers = [],
        public readonly bool $trusted = false,
    ) {}

    /**
     * Create a stdio server configuration.
     */
    public static function stdio(
        string $name,
        string $command,
        array $args = [],
        ?array $env = null,
        ?string $cwd = null,
        bool $trusted = false,
    ): self {
        return new self(
            name: $name,
            transport: 'stdio',
            command: $command,
            args: $args,
            env: $env,
            cwd: $cwd,
            trusted: $trusted,
        );
    }

    /**
     * Create a Streamable HTTP server configuration.
     */
    public static function http(
        string $name,
        string $url,
        array $headers = [],
        bool $trusted = false,
    ): self {
        return new self(
            name: $name,
            transport: 'http',
            url: $url,
            headers: $headers,
            trusted: $trusted,
        );
    }

    /**
     * Check if this is a stdio transport.
     */
    public function isStdio(): bool
    {
        return $this->transport === 'stdio';
    }

    /**
     * Check if this is an HTTP transport.
     */
    public function isHttp(): bool
    {
        return $this->transport === 'http';
    }
}
