<?php

declare(strict_types=1);

namespace Laragentic\Signals;

use Laragentic\Contracts\PausesLoop;

/**
 * Static helper for detecting PausesLoop markers in stringified tool results.
 *
 * When the Laravel AI SDK's Prism gateway resolves tool calls internally,
 * it stringifies the tool result before storing it in the AgentResponse.
 * This means the original PausesLoop object is lost and instanceof checks
 * fail. PauseSignal::isSignal() provides string-based detection using the
 * JSON marker key that all PausesLoop implementations must include.
 *
 * @see PausesLoop::MARKER_KEY
 */
class PauseSignal
{
    /**
     * Check whether a string value is a PausesLoop JSON marker.
     */
    public static function isSignal(string $value): bool
    {
        if (! str_starts_with(trim($value), '{')) {
            return false;
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) && ($decoded[PausesLoop::MARKER_KEY] ?? false) === true;
    }
}
