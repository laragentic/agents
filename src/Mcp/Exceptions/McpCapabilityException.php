<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Exceptions;

use RuntimeException;

/**
 * Thrown when attempting to use a feature not supported by the server.
 */
class McpCapabilityException extends RuntimeException {}
