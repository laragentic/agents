<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Utilities;

use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Bidirectional ping/pong handler for keepalive.
 */
class PingHandler
{
    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * Send a ping to the server and wait for the response.
     */
    public function ping(?float $timeout = null): bool
    {
        try {
            $this->client->request('ping', [], $timeout ?? 5.0);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Handle a ping request from the server.
     *
     * Returns an empty result per the spec.
     */
    public function handlePing(JsonRpcMessage $message): \stdClass
    {
        return new \stdClass;
    }
}
