<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Exceptions;

use RuntimeException;

/**
 * Thrown when an MCP protocol error occurs.
 */
class McpProtocolException extends RuntimeException
{
    private ?int $errorCode;

    private mixed $errorData;

    public function __construct(
        string $message = '',
        ?int $errorCode = null,
        mixed $errorData = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $errorCode;
        $this->errorData = $errorData;
    }

    /**
     * Create from a JSON-RPC error response.
     */
    public static function fromError(?int $code, ?string $message, mixed $data = null): self
    {
        return new self(
            message: $message ?? 'Unknown MCP protocol error',
            errorCode: $code,
            errorData: $data,
        );
    }

    /**
     * Get the JSON-RPC error code.
     */
    public function errorCode(): ?int
    {
        return $this->errorCode;
    }

    /**
     * Get the error data.
     */
    public function errorData(): mixed
    {
        return $this->errorData;
    }

    /**
     * Whether this is a "URL Elicitation Required" error (-32042).
     */
    public function isUrlElicitationRequired(): bool
    {
        return $this->errorCode === -32042;
    }
}
