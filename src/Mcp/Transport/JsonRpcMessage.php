<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Transport;

use InvalidArgumentException;

/**
 * JSON-RPC 2.0 message value object.
 *
 * Represents all four JSON-RPC message types:
 * - Request: has id + method
 * - Notification: has method, no id
 * - Success Response: has id + result
 * - Error Response: has id + error
 */
class JsonRpcMessage
{
    private static int $nextId = 1;

    public function __construct(
        public readonly string $jsonrpc = '2.0',
        public readonly string|int|null $id = null,
        public readonly ?string $method = null,
        public readonly ?array $params = null,
        public readonly mixed $result = null,
        public readonly ?array $error = null,
    ) {}

    /**
     * Create a JSON-RPC request message.
     */
    public static function request(string $method, array $params = [], string|int|null $id = null): self
    {
        return new self(
            id: $id ?? self::$nextId++,
            method: $method,
            params: $params ?: null,
        );
    }

    /**
     * Create a JSON-RPC notification (no id, no response expected).
     */
    public static function notification(string $method, array $params = []): self
    {
        return new self(
            method: $method,
            params: $params ?: null,
        );
    }

    /**
     * Create a JSON-RPC success response.
     */
    public static function result(string|int $id, mixed $result): self
    {
        return new self(
            id: $id,
            result: $result,
        );
    }

    /**
     * Create a JSON-RPC error response.
     */
    public static function error(string|int $id, int $code, string $message, mixed $data = null): self
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($data !== null) {
            $error['data'] = $data;
        }

        return new self(
            id: $id,
            error: $error,
        );
    }

    /**
     * Is this a request (has id and method)?
     */
    public function isRequest(): bool
    {
        return $this->id !== null && $this->method !== null;
    }

    /**
     * Is this a notification (has method but no id)?
     */
    public function isNotification(): bool
    {
        return $this->id === null && $this->method !== null;
    }

    /**
     * Is this a success response (has id and result)?
     */
    public function isResponse(): bool
    {
        return $this->id !== null && $this->method === null && $this->error === null;
    }

    /**
     * Is this an error response (has id and error)?
     */
    public function isError(): bool
    {
        return $this->id !== null && $this->error !== null;
    }

    /**
     * Get the error code, if this is an error response.
     */
    public function errorCode(): ?int
    {
        return $this->error['code'] ?? null;
    }

    /**
     * Get the error message, if this is an error response.
     */
    public function errorMessage(): ?string
    {
        return $this->error['message'] ?? null;
    }

    /**
     * Serialize to array for JSON encoding.
     */
    public function toArray(): array
    {
        $data = ['jsonrpc' => $this->jsonrpc];

        if ($this->id !== null) {
            $data['id'] = $this->id;
        }

        if ($this->method !== null) {
            $data['method'] = $this->method;
        }

        if ($this->params !== null) {
            $data['params'] = $this->params;
        }

        if ($this->result !== null) {
            $data['result'] = $this->result;
        }

        if ($this->error !== null) {
            $data['error'] = $this->error;
        }

        // A response with no result and no error should still include result
        if ($this->isResponse() && $this->result === null) {
            $data['result'] = new \stdClass;
        }

        return $data;
    }

    /**
     * Serialize to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Deserialize from an associative array.
     */
    public static function fromArray(array $data): self
    {
        if (($data['jsonrpc'] ?? null) !== '2.0') {
            throw new InvalidArgumentException('Invalid JSON-RPC version: expected "2.0".');
        }

        return new self(
            jsonrpc: $data['jsonrpc'],
            id: $data['id'] ?? null,
            method: $data['method'] ?? null,
            params: $data['params'] ?? null,
            result: $data['result'] ?? null,
            error: $data['error'] ?? null,
        );
    }

    /**
     * Deserialize from a JSON string.
     */
    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return self::fromArray($data);
    }

    /**
     * Reset the auto-incrementing ID counter (useful for testing).
     */
    public static function resetIdCounter(): void
    {
        self::$nextId = 1;
    }
}
