<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Protocol;

/**
 * MCP protocol version negotiation.
 */
class ProtocolVersion
{
    /**
     * The latest supported protocol version.
     */
    public const LATEST = '2025-11-25';

    /**
     * All protocol versions this client supports.
     */
    public const SUPPORTED = [
        '2025-11-25',
        '2024-11-05',
    ];

    /**
     * Check if the client supports a given protocol version.
     */
    public static function isSupported(string $version): bool
    {
        return in_array($version, self::SUPPORTED, true);
    }

    /**
     * Negotiate version compatibility.
     *
     * Returns the negotiated version or null if incompatible.
     */
    public static function negotiate(string $serverVersion): ?string
    {
        if (self::isSupported($serverVersion)) {
            return $serverVersion;
        }

        return null;
    }
}
