<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Transport;

use Closure;

/**
 * Contract for MCP transport implementations.
 *
 * Transports handle the low-level communication between the MCP client
 * and server, abstracting over stdio, HTTP, and other protocols.
 */
interface TransportInterface
{
    /**
     * Establish the transport connection.
     */
    public function connect(): void;

    /**
     * Send a JSON-RPC message through the transport.
     */
    public function send(JsonRpcMessage $message): void;

    /**
     * Non-blocking poll for an incoming message.
     *
     * Returns null if no message is available.
     */
    public function receive(): ?JsonRpcMessage;

    /**
     * Blocking receive with a timeout.
     *
     * Waits up to $timeout seconds for a message.
     * Returns null if the timeout expires without a message.
     */
    public function receiveBlocking(float $timeout): ?JsonRpcMessage;

    /**
     * Register a push-based message listener.
     *
     * The callback receives a JsonRpcMessage instance.
     */
    public function onMessage(Closure $callback): void;

    /**
     * Gracefully disconnect the transport.
     */
    public function disconnect(): void;

    /**
     * Check if the transport is currently connected.
     */
    public function isConnected(): bool;
}
