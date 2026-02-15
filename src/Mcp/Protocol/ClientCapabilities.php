<?php

declare(strict_types=1);

namespace Laragentic\Mcp\Protocol;

/**
 * Declares what features the MCP client supports.
 *
 * Sent to the server during initialization so it knows what
 * it can request from the client.
 */
class ClientCapabilities
{
    public function __construct(
        public readonly ?array $roots = null,
        public readonly ?array $sampling = null,
        public readonly ?array $elicitation = null,
    ) {}

    /**
     * Create from config defaults.
     */
    public static function fromConfig(): self
    {
        $config = config('agentic.mcp.capabilities', []);

        return new self(
            roots: ($config['roots'] ?? true)
                ? ['listChanged' => true]
                : null,
            sampling: ($config['sampling'] ?? true)
                ? ['tools' => $config['sampling_tools'] ?? true]
                : null,
            elicitation: ($config['elicitation_form'] ?? true) || ($config['elicitation_url'] ?? true)
                ? [
                    'form' => $config['elicitation_form'] ?? true,
                    'url' => $config['elicitation_url'] ?? true,
                ]
                : null,
        );
    }

    /**
     * Create with all capabilities enabled.
     */
    public static function all(): self
    {
        return new self(
            roots: ['listChanged' => true],
            sampling: ['tools' => true],
            elicitation: ['form' => true, 'url' => true],
        );
    }

    /**
     * Create with no capabilities.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Whether the client supports roots.
     */
    public function supportsRoots(): bool
    {
        return $this->roots !== null;
    }

    /**
     * Whether the client supports roots/list_changed notifications.
     */
    public function supportsRootsListChanged(): bool
    {
        return $this->roots['listChanged'] ?? false;
    }

    /**
     * Whether the client supports sampling.
     */
    public function supportsSampling(): bool
    {
        return $this->sampling !== null;
    }

    /**
     * Whether the client supports tools in sampling requests.
     */
    public function supportsSamplingTools(): bool
    {
        return $this->sampling['tools'] ?? false;
    }

    /**
     * Whether the client supports elicitation.
     */
    public function supportsElicitation(): bool
    {
        return $this->elicitation !== null;
    }

    /**
     * Serialize to array for the initialize request.
     */
    public function toArray(): array
    {
        $capabilities = [];

        if ($this->roots !== null) {
            $capabilities['roots'] = $this->roots;
        }

        if ($this->sampling !== null) {
            $capabilities['sampling'] = $this->sampling;
        }

        if ($this->elicitation !== null) {
            $capabilities['elicitation'] = $this->elicitation;
        }

        return $capabilities;
    }
}
