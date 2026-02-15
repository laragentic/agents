<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Transport;

use Closure;
use Laragentic\Mcp\Exceptions\McpConnectionException;
use Laragentic\Mcp\Exceptions\McpTransportException;

/**
 * stdio transport for MCP.
 *
 * Launches the MCP server as a subprocess and communicates via
 * stdin/stdout using newline-delimited JSON-RPC messages.
 *
 * Per the spec:
 * - Client writes to the server process's stdin
 * - Client reads from the server process's stdout
 * - stderr is captured for logging but NOT treated as protocol messages
 * - Shutdown: close stdin → wait → SIGTERM → wait → SIGKILL
 */
class StdioTransport implements TransportInterface
{
    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private bool $connected = false;

    private string $readBuffer = '';

    /** @var list<Closure> */
    private array $messageListeners = [];

    public function __construct(
        private readonly string $command,
        private readonly array $args = [],
        private readonly ?array $env = null,
        private readonly ?string $cwd = null,
        private readonly float $shutdownTimeout = 5.0,
        private readonly float $sigtermTimeout = 3.0,
    ) {}

    public function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $fullCommand = escapeshellcmd($this->command);
        foreach ($this->args as $arg) {
            $fullCommand .= ' ' . escapeshellarg($arg);
        }

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'r'], // stdout
            2 => ['pipe', 'r'], // stderr
        ];

        $env = $this->env;

        $this->process = proc_open(
            $fullCommand,
            $descriptors,
            $pipes,
            $this->cwd,
            $env,
        );

        if (! is_resource($this->process)) {
            throw new McpConnectionException(
                "Failed to start MCP server process: {$fullCommand}"
            );
        }

        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2];

        // Set stdout and stderr to non-blocking for polling
        stream_set_blocking($this->stdout, false);
        stream_set_blocking($this->stderr, false);

        $this->connected = true;
    }

    public function send(JsonRpcMessage $message): void
    {
        $this->ensureConnected();

        $json = $message->toJson();

        // Messages are newline-delimited, no embedded newlines allowed
        $json = str_replace(["\r\n", "\r", "\n"], '', $json);

        $written = @fwrite($this->stdin, $json . "\n");

        if ($written === false) {
            throw new McpTransportException('Failed to write to MCP server stdin.');
        }

        @fflush($this->stdin);
    }

    public function receive(): ?JsonRpcMessage
    {
        $this->ensureConnected();

        return $this->readMessage(blocking: false);
    }

    public function receiveBlocking(float $timeout): ?JsonRpcMessage
    {
        $this->ensureConnected();

        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            $message = $this->readMessage(blocking: false);

            if ($message !== null) {
                return $message;
            }

            // Small sleep to avoid busy-waiting
            usleep(10_000); // 10ms
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

        // Step 1: Close stdin to signal the server
        if (is_resource($this->stdin)) {
            @fclose($this->stdin);
            $this->stdin = null;
        }

        // Step 2: Wait for graceful shutdown
        $deadline = microtime(true) + $this->shutdownTimeout;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->process);
            if (! $status['running']) {
                break;
            }
            usleep(50_000); // 50ms
        }

        // Step 3: SIGTERM if still running
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, 15); // SIGTERM

            $deadline = microtime(true) + $this->sigtermTimeout;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->process);
                if (! $status['running']) {
                    break;
                }
                usleep(50_000);
            }
        }

        // Step 4: SIGKILL if still running
        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, 9); // SIGKILL
        }

        // Clean up pipes
        if (is_resource($this->stdout)) {
            @fclose($this->stdout);
        }
        if (is_resource($this->stderr)) {
            @fclose($this->stderr);
        }

        @proc_close($this->process);

        $this->process = null;
        $this->stdout = null;
        $this->stderr = null;
        $this->connected = false;
        $this->readBuffer = '';
    }

    public function isConnected(): bool
    {
        if (! $this->connected || ! is_resource($this->process)) {
            return false;
        }

        $status = proc_get_status($this->process);

        return $status['running'];
    }

    /**
     * Read any available stderr output (for logging).
     */
    public function readStderr(): ?string
    {
        if (! is_resource($this->stderr)) {
            return null;
        }

        $output = @stream_get_contents($this->stderr);

        return $output !== false && $output !== '' ? $output : null;
    }

    /**
     * Read a single JSON-RPC message from stdout.
     */
    private function readMessage(bool $blocking): ?JsonRpcMessage
    {
        // Check if we have a complete line in the buffer
        $newlinePos = strpos($this->readBuffer, "\n");

        if ($newlinePos !== false) {
            $line = substr($this->readBuffer, 0, $newlinePos);
            $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);
            $line = trim($line);

            if ($line !== '') {
                return $this->parseMessage($line);
            }
        }

        // Try to read more data
        if (! is_resource($this->stdout)) {
            return null;
        }

        if ($blocking) {
            stream_set_blocking($this->stdout, true);
        }

        $chunk = @fread($this->stdout, 65536);

        if ($blocking) {
            stream_set_blocking($this->stdout, false);
        }

        if ($chunk === false || $chunk === '') {
            return null;
        }

        $this->readBuffer .= $chunk;

        // Try again with the new data
        $newlinePos = strpos($this->readBuffer, "\n");

        if ($newlinePos !== false) {
            $line = substr($this->readBuffer, 0, $newlinePos);
            $this->readBuffer = substr($this->readBuffer, $newlinePos + 1);
            $line = trim($line);

            if ($line !== '') {
                return $this->parseMessage($line);
            }
        }

        return null;
    }

    /**
     * Parse a JSON string into a JsonRpcMessage.
     */
    private function parseMessage(string $json): ?JsonRpcMessage
    {
        try {
            $message = JsonRpcMessage::fromJson($json);

            // Notify listeners
            foreach ($this->messageListeners as $listener) {
                $listener($message);
            }

            return $message;
        } catch (\Throwable $e) {
            // Invalid JSON or non-JSON-RPC message — skip
            return null;
        }
    }

    /**
     * Ensure the transport is connected.
     */
    private function ensureConnected(): void
    {
        if (! $this->connected) {
            throw new McpConnectionException('MCP stdio transport is not connected.');
        }
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
