<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Utilities;

use Laragentic\Mcp\McpClient;

/**
 * Client for MCP completion/complete requests.
 *
 * Provides argument autocompletion for prompts and resources.
 */
class CompletionClient
{
    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * Request completions for a prompt argument.
     */
    public function completePromptArgument(
        string $promptName,
        string $argumentName,
        string $argumentValue,
    ): array {
        return $this->complete('ref/prompt', $promptName, $argumentName, $argumentValue);
    }

    /**
     * Request completions for a resource argument.
     */
    public function completeResourceArgument(
        string $resourceUri,
        string $argumentName,
        string $argumentValue,
    ): array {
        return $this->complete('ref/resource', $resourceUri, $argumentName, $argumentValue);
    }

    /**
     * Send a completion/complete request.
     */
    public function complete(
        string $refType,
        string $refValue,
        string $argumentName,
        string $argumentValue,
    ): array {
        $this->client->requireCapability('completions');

        $refKey = $refType === 'ref/prompt' ? 'name' : 'uri';

        $response = $this->client->request('completion/complete', [
            'ref' => [
                'type' => $refType,
                $refKey => $refValue,
            ],
            'argument' => [
                'name' => $argumentName,
                'value' => $argumentValue,
            ],
        ]);

        return [
            'values' => $response['completion']['values'] ?? [],
            'total' => $response['completion']['total'] ?? null,
            'hasMore' => $response['completion']['hasMore'] ?? false,
        ];
    }
}
