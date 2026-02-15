<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Transport;

use Closure;
use Illuminate\Support\Facades\Http;
use Laragentic\Mcp\Exceptions\McpConnectionException;
use Laragentic\Mcp\Exceptions\McpTransportException;

/**
 * Streamable HTTP transport for MCP.
 *
 * Implements the new Streamable HTTP transport (replaces legacy HTTP+SSE):
 * - Every JSON-RPC message is sent as an HTTP POST
 * - Responses can be application/json or text/event-stream (SSE)
 * - Session management via MCP-Session-Id header
 * - Optional SSE GET for server-initiated messages
 * - Backwards compatible with legacy HTTP+SSE transport
 */
class StreamableHttpTransport implements TransportInterface
{
    private bool $connected = false;

    private ?string $sessionId = null;

    private ?string $protocolVersion = null;

    /** @var list<JsonRpcMessage> Buffered messages from SSE streams */
    private array $messageBuffer = [];

    /** @var list<Closure> */
    private array $messageListeners = [];

    private ?string $lastEventId = null;

    public function __construct(
        private readonly string $url,
        private readonly array $headers = [],
        private readonly float $timeout = 30.0,
    ) {}

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $this->connected = true;
    }

    public function send(JsonRpcMessage $message): void
    {
        $this->ensureConnected();

        $headers = array_merge($this->headers, [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json, text/event-stream',
        ]);

        if ($this->sessionId !== null) {
            $headers['MCP-Session-Id'] = $this->sessionId;
        }

        if ($this->protocolVersion !== null) {
            $headers['MCP-Protocol-Version'] = $this->protocolVersion;
        }

        $response = Http::withHeaders($headers)
            ->timeout((int) $this->timeout)
            ->post($this->url, $message->toArray());

        // Handle session expiry (404)
        if ($response->status() === 404) {
            $this->sessionId = null;

            throw new McpTransportException(
                'MCP session expired. Re-initialization required.',
            );
        }

        if (! $response->successful()) {
            throw new McpTransportException(
                "MCP HTTP request failed with status {$response->status()}: {$response->body()}"
            );
        }

        // Extract session ID from response headers
        if ($response->header('MCP-Session-Id')) {
            $this->sessionId = $response->header('MCP-Session-Id');
        }

        // Parse response based on content type
        $contentType = $response->header('Content-Type', '');

        if (str_contains($contentType, 'text/event-stream')) {
            $this->parseSseResponse($response->body());
        } elseif (str_contains($contentType, 'application/json')) {
            $body = $response->json();

            if (! empty($body)) {
                $msg = JsonRpcMessage::fromArray($body);
                $this->bufferMessage($msg);
            }
        }

        // Notifications and responses with no ID don't get a response body
        if ($response->status() === 202 || $response->status() === 204) {
            return;
        }
    }

    public function receive(): ?JsonRpcMessage
    {
        if (! empty($this->messageBuffer)) {
            return array_shift($this->messageBuffer);
        }

        return null;
    }

    public function receiveBlocking(float $timeout): ?JsonRpcMessage
    {
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $message = $this->receive();

            if ($message !== null) {
                return $message;
            }

            usleep(50_000); // 50ms
        }

        return null;
    }

    public function onMessage(Closure $callback): void
    {
        $this->messageListeners[] = $callback;
    }

    public function disconnect(): void
    {
        if (! $this->connected) {
            return;
        }

        // Send HTTP DELETE to terminate the session
        if ($this->sessionId !== null) {
            try {
                $headers = array_merge($this->headers, [
                    'MCP-Session-Id' => $this->sessionId,
                ]);

                Http::withHeaders($headers)
                    ->timeout(5)
                    ->delete($this->url);
            } catch (\Throwable) {
                // Best effort shutdown
            }
        }

        $this->connected = false;
        $this->sessionId = null;
        $this->protocolVersion = null;
        $this->messageBuffer = [];
        $this->lastEventId = null;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * Set the protocol version for subsequent requests.
     */
    public function setProtocolVersion(string $version): void
    {
        $this->protocolVersion = $version;
    }

    /**
     * Get the current session ID.
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    /**
     * Parse an SSE response body into JSON-RPC messages.
     */
    private function parseSseResponse(string $body): void
    {
        $lines = explode("\n", $body);
        $data = '';
        $eventId = null;

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");

            if ($line === '') {
                // Empty line = end of event
                if ($data !== '') {
                    $this->processEventData($data);
                    $data = '';
                }
                if ($eventId !== null) {
                    $this->lastEventId = $eventId;
                    $eventId = null;
                }

                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $value = ltrim(substr($line, 5));
                $data .= ($data !== '' ? "\n" : '') . $value;
            } elseif (str_starts_with($line, 'id:')) {
                $eventId = ltrim(substr($line, 3));
            }
        }

        // Process any remaining data
        if ($data !== '') {
            $this->processEventData($data);
        }
    }

    /**
     * Process a single SSE event data field.
     */
    private function processEventData(string $data): void
    {
        try {
            $parsed = json_decode($data, true, 512, JSON_THROW_ON_ERROR);

            // Could be a single message or batch
            if (isset($parsed['jsonrpc'])) {
                $message = JsonRpcMessage::fromArray($parsed);
                $this->bufferMessage($message);
            } elseif (is_array($parsed) && isset($parsed[0])) {
                // Batch of messages
                foreach ($parsed as $item) {
                    if (isset($item['jsonrpc'])) {
                        $message = JsonRpcMessage::fromArray($item);
                        $this->bufferMessage($message);
                    }
                }
            }
        } catch (\Throwable) {
            // Skip invalid SSE data
        }
    }

    /**
     * Buffer a message and notify listeners.
     */
    private function bufferMessage(JsonRpcMessage $message): void
    {
        $this->messageBuffer[] = $message;

        foreach ($this->messageListeners as $listener) {
            $listener($message);
        }
    }

    /**
     * Ensure the transport is connected.
     */
    private function ensureConnected(): void
    {
        if (! $this->connected) {
            throw new McpConnectionException('MCP HTTP transport is not connected.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
