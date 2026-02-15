<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Features\Sampling;

use Closure;
use Laragentic\Mcp\McpClient;
use Laragentic\Mcp\Transport\JsonRpcMessage;

/**
 * Handles sampling/createMessage requests from the MCP server.
 *
 * When the server needs LLM access, it sends a sampling request.
 * This handler delegates to a configured callback (typically the
 * host agent's LLM) to generate the response.
 */
class SamplingHandler
{
    /** @var Closure|null */
    private ?Closure $delegate = null;

    /** @var list<Closure> */
    private array $requestHandlers = [];

    private bool $autoApprove = false;

    public function __construct(
        private readonly McpClient $client,
    ) {
        $this->autoApprove = (bool) config('agentic.mcp.sampling.auto_approve', false);
    }

    /**
     * Set the sampling delegate that will process requests.
     *
     * The delegate receives a SamplingRequest and returns a SamplingResult.
     */
    public function setDelegate(Closure $delegate): void
    {
        $this->delegate = $delegate;
    }

    /**
     * Handle a sampling/createMessage request from the server.
     */
    public function handleRequest(JsonRpcMessage $message): array
    {
        $request = SamplingRequest::fromArray($message->params ?? []);

        // Fire request handlers for logging/approval
        foreach ($this->requestHandlers as $handler) {
            $handler($request);
        }

        if ($this->delegate === null) {
            return SamplingResult::text(
                'Sampling not configured. No LLM delegate set.',
                'none',
                'error',
            )->toArray();
        }

        /** @var SamplingResult $result */
        $result = ($this->delegate)($request);

        return $result->toArray();
    }

    /**
     * Register a handler for sampling requests.
     */
    public function onRequest(Closure $handler): void
    {
        $this->requestHandlers[] = $handler;
    }

    /**
     * Set whether to auto-approve sampling requests.
     */
    public function setAutoApprove(bool $autoApprove): void
    {
        $this->autoApprove = $autoApprove;
    }

    /**
     * Whether auto-approve is enabled.
     */
    public function isAutoApprove(): bool
    {
        return $this->autoApprove;
    }
}
