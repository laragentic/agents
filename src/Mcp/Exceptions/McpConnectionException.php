<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Exceptions;

use RuntimeException;

/**
 * Thrown when the MCP client cannot connect to the server.
 */
class McpConnectionException extends RuntimeException {}
