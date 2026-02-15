<?php

declare(strict_types=1);

namespace Laragentic\Tests\Fixtures\Mcp;

use Closure;
use Laragentic\Mcp\Transport\JsonRpcMessage;
use Laragentic\Mcp\Transport\TransportInterface;

/**
 * Mock transport for testing MCP client behavior without real servers.
 *
 * Allows pre-configuring responses and capturing sent messages.
 */
class MockTransport implements TransportInterface
{
    private bool $connected = false;

    /** @var list<JsonRpcMessage> Messages sent through this transport */
    public array $sentMessages = [];

    /** @var list<JsonRpcMessage> Messages queued to be received */
    public array $receiveQueue = [];

    /** @var list<Closure> */
    private array $messageListeners = [];

    /** @var array<string, Closure> Method-based response handlers */
    private array $responseHandlers = [];

    public function connect(): void
    {
        $this->connected = true;
    }

    public function send(JsonRpcMessage $message): void
    {
        $this->sentMessages[] = $message;

        // Auto-respond if a handler is registered for this method
        if ($message->isRequest() && isset($this->responseHandlers[$message->method])) {
            $handler = $this->responseHandlers[$message->method];
            $result = $handler($message);

            $response = JsonRpcMessage::result($message->id, $result);
            $this->receiveQueue[] = $response;
        }
    }

    public function receive(): ?JsonRpcMessage
    {
        if (empty($this->receiveQueue)) {
            return null;
        }

        $message = array_shift($this->receiveQueue);

        foreach ($this->messageListeners as $listener) {
            $listener($message);
        }

        return $message;
    }

    public function receiveBlocking(float $timeout): ?JsonRpcMessage
    {
        return $this->receive();
    }

    public function onMessage(Closure $callback): void
    {
        $this->messageListeners[] = $callback;
    }

    public function disconnect(): void
    {
        $this->connected = false;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    // ─── Test Helpers ───────────────────────────────────────────────

    /**
     * Queue a message to be received.
     */
    public function queueResponse(JsonRpcMessage $message): void
    {
        $this->receiveQueue[] = $message;
    }

    /**
     * Register an auto-response handler for a method.
     */
    public function onRequest(string $method, Closure $handler): void
    {
        $this->responseHandlers[$method] = $handler;
    }

    /**
     * Get all sent messages.
     */
    public function sent(): array
    {
        return $this->sentMessages;
    }

    /**
     * Get the last sent message.
     */
    public function lastSent(): ?JsonRpcMessage
    {
        return end($this->sentMessages) ?: null;
    }

    /**
     * Get sent messages by method name.
     */
    public function sentByMethod(string $method): array
    {
        return array_values(array_filter(
            $this->sentMessages,
            fn (JsonRpcMessage $msg) => $msg->method === $method,
        ));
    }

    /**
     * Reset all state.
     */
    public function reset(): void
    {
        $this->sentMessages = [];
        $this->receiveQueue = [];
        $this->responseHandlers = [];
    }
}
