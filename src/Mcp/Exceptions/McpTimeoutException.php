<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Exceptions;

use RuntimeException;

/**
 * Thrown when an MCP request times out.
 */
class McpTimeoutException extends RuntimeException {}
