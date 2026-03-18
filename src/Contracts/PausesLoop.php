<?php

declare(strict_types=1);

namespace Laragentic\Contracts;

/**
 * Marker interface for tool results that require human interaction
 * before the loop should continue executing further tools.
 *
 * When a tool returns an object implementing PausesLoop, the loop
 * stops executing any remaining tool calls in the current iteration
 * and terminates — similar to AskHumanSignal but for generic
 * "this tool needs user interaction" scenarios (e.g. an SDK app
 * that renders an interactive iframe).
 *
 * Implementations must also implement Stringable so the result
 * can be stored as a tool result string. When stringified, the
 * JSON output MUST include the MARKER_KEY so that loops using
 * SDK-resolved tool results (where the object is lost) can detect
 * the pause via string inspection.
 *
 * @see \Laragentic\Signals\PauseSignal::isSignal()
 */
interface PausesLoop extends \Stringable
{
    /**
     * JSON key that implementations MUST include in their __toString()
     * output so loops can detect the pause signal from a string result.
     *
     * Example: {"__laragentic_pauses_loop": true, "type": "sdk_app_render", ...}
     */
    public const MARKER_KEY = '__laragentic_pauses_loop';
}
