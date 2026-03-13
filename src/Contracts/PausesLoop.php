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
 * can be stored as a tool result string.
 */
interface PausesLoop extends \Stringable {}
