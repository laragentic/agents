<?php

declare(strict_types=1);

namespace Laragentic\Mcp;

use Laragentic\Mcp\Protocol\InitializeResult;
use Laragentic\Mcp\Protocol\ServerCapabilities;

/**
 * Manages the state of an MCP client session.
 *
 * Tracks the lifecycle phase, negotiated capabilities, and server info.
 */
class McpSession
{
    public const PHASE_DISCONNECTED = 'disconnected';

    public const PHASE_INITIALIZING = 'initializing';

    public const PHASE_INITIALIZED = 'initialized';

    public const PHASE_OPERATIONAL = 'operational';

    public const PHASE_SHUTTING_DOWN = 'shutting_down';

    private string $phase = self::PHASE_DISCONNECTED;

    private ?InitializeResult $initResult = null;

    private ?string $sessionId = null;

    /**
     * Transition to the initializing phase.
     */
    public function beginInitialization(): void
    {
        $this->phase = self::PHASE_INITIALIZING;
    }

    /**
     * Complete initialization with the server's response.
     */
    public function completeInitialization(InitializeResult $result): void
    {
        $this->initResult = $result;
        $this->phase = self::PHASE_INITIALIZED;
    }

    /**
     * Transition to the operational phase (after sending initialized notification).
     */
    public function becomeOperational(): void
    {
        $this->phase = self::PHASE_OPERATIONAL;
    }

    /**
     * Begin shutdown.
     */
    public function beginShutdown(): void
    {
        $this->phase = self::PHASE_SHUTTING_DOWN;
    }

    /**
     * Complete disconnection.
     */
    public function disconnect(): void
    {
        $this->phase = self::PHASE_DISCONNECTED;
        $this->initResult = null;
        $this->sessionId = null;
    }

    /**
     * Get the current lifecycle phase.
     */
    public function phase(): string
    {
        return $this->phase;
    }

    /**
     * Whether the session is operational (ready for normal requests).
     */
    public function isOperational(): bool
    {
        return $this->phase === self::PHASE_OPERATIONAL;
    }

    /**
     * Whether the session is connected (initializing or operational).
     */
    public function isConnected(): bool
    {
        return in_array($this->phase, [
            self::PHASE_INITIALIZING,
            self::PHASE_INITIALIZED,
            self::PHASE_OPERATIONAL,
        ], true);
    }

    /**
     * Get the server capabilities (available after initialization).
     */
    public function serverCapabilities(): ?ServerCapabilities
    {
        return $this->initResult?->capabilities;
    }

    /**
     * Get the initialization result.
     */
    public function initializeResult(): ?InitializeResult
    {
        return $this->initResult;
    }

    /**
     * Get the negotiated protocol version.
     */
    public function protocolVersion(): ?string
    {
        return $this->initResult?->protocolVersion;
    }

    /**
     * Get the server name.
     */
    public function serverName(): ?string
    {
        return $this->initResult?->serverName;
    }

    /**
     * Get the server version.
     */
    public function serverVersion(): ?string
    {
        return $this->initResult?->serverVersion;
    }

    /**
     * Get the server's instructions (if provided).
     */
    public function instructions(): ?string
    {
        return $this->initResult?->instructions;
    }

    /**
     * Set the HTTP session ID (for Streamable HTTP transport).
     */
    public function setSessionId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * Get the HTTP session ID.
     */
    public function sessionId(): ?string
    {
        return $this->sessionId;
    }
}
