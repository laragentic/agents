<?php

declare(strict_types=1);

namespace Laragentic\Mcp;

use Closure;
use Laragentic\Mcp\Exceptions\McpCapabilityException;
use Laragentic\Mcp\Exceptions\McpConnectionException;
use Laragentic\Mcp\Exceptions\McpProtocolException;
use Laragentic\Mcp\Exceptions\McpTimeoutException;
use Laragentic\Mcp\Features\Prompts\PromptDefinition;
use Laragentic\Mcp\Features\Elicitation\ElicitationHandler;
use Laragentic\Mcp\Features\Prompts\PromptManager;
use Laragentic\Mcp\Features\Resources\ResourceContents;
use Laragentic\Mcp\Features\Resources\ResourceManager;
use Laragentic\Mcp\Features\Roots\Root;
use Laragentic\Mcp\Features\Roots\RootsProvider;
use Laragentic\Mcp\Features\Sampling\SamplingHandler;
use Laragentic\Mcp\Features\Tools\McpToolProxy;
use Laragentic\Mcp\Protocol\ClientCapabilities;
use Laragentic\Mcp\Protocol\InitializeParams;
use Laragentic\Mcp\Protocol\InitializeResult;
use Laragentic\Mcp\Protocol\ProtocolVersion;
use Laragentic\Mcp\Protocol\ServerCapabilities;
use Laragentic\Mcp\Transport\JsonRpcMessage;
use Laragentic\Mcp\Transport\StdioTransport;
use Laragentic\Mcp\Transport\StreamableHttpTransport;
use Laragentic\Mcp\Transport\TransportInterface;
use Laragentic\Mcp\Utilities\CancellationManager;
use Laragentic\Mcp\Utilities\CompletionClient;
use Laragentic\Mcp\Utilities\LogReceiver;
use Laragentic\Mcp\Utilities\PingHandler;
use Laragentic\Mcp\Utilities\ProgressTracker;

/**
 * Core MCP client implementation.
 *
 * Manages a single stateful 1:1 session with an MCP server,
 * handling lifecycle, capability negotiation, and message routing.
 */
class McpClient
{
    private TransportInterface $transport;

    private McpSession $session;

    private ClientCapabilities $clientCapabilities;

    private McpToolProxy $toolProxy;

    private ResourceManager $resourceManager;

    private PromptManager $promptManager;

    private RootsProvider $rootsProvider;

    private SamplingHandler $samplingHandler;

    private ElicitationHandler $elicitationHandler;

    private PingHandler $pingHandler;

    private ProgressTracker $progressTracker;

    private CancellationManager $cancellationManager;

    private LogReceiver $logReceiver;

    private CompletionClient $completionClient;

    /** @var array<string|int, Closure> Pending request callbacks by ID */
    private array $pendingRequests = [];

    /** @var array<string|int, JsonRpcMessage> Received responses by request ID */
    private array $responses = [];

    /** @var list<Closure> */
    private array $notificationHandlers = [];

    private float $requestTimeout;

    public function __construct(
        private readonly McpServer $serverConfig,
        ?ClientCapabilities $capabilities = null,
    ) {
        $this->clientCapabilities = $capabilities ?? ClientCapabilities::all();
        $this->session = new McpSession;
        $this->transport = $this->createTransport();
        $this->toolProxy = new McpToolProxy($this);
        $this->resourceManager = new ResourceManager($this);
        $this->promptManager = new PromptManager($this);
        $this->rootsProvider = new RootsProvider($this);
        $this->samplingHandler = new SamplingHandler($this);
        $this->elicitationHandler = new ElicitationHandler($this);
        $this->pingHandler = new PingHandler($this);
        $this->progressTracker = new ProgressTracker($this);
        $this->cancellationManager = new CancellationManager($this);
        $this->logReceiver = new LogReceiver($this);
        $this->completionClient = new CompletionClient($this);
        $this->requestTimeout = (float) config('agentic.mcp.protocol.request_timeout', 30);
    }

    // ─── Lifecycle ──────────────────────────────────────────────────

    /**
     * Connect to the MCP server and perform the initialization handshake.
     */
    public function connect(): void
    {
        if ($this->session->isConnected()) {
            return;
        }

        $this->transport->connect();
        $this->session->beginInitialization();

        // Register message handler for incoming server messages
        $this->transport->onMessage(fn (JsonRpcMessage $msg) => $this->handleIncomingMessage($msg));

        // Step 1: Send initialize request
        $params = new InitializeParams(
            protocolVersion: ProtocolVersion::LATEST,
            capabilities: $this->clientCapabilities,
            clientName: config('agentic.mcp.client_info.name', 'laragentic'),
            clientVersion: config('agentic.mcp.client_info.version', '1.0.0'),
        );

        $response = $this->request('initialize', $params->toArray());

        // Step 2: Parse server capabilities
        $result = InitializeResult::fromArray($response);

        // Verify protocol version compatibility
        $negotiated = ProtocolVersion::negotiate($result->protocolVersion);
        if ($negotiated === null) {
            $this->transport->disconnect();
            $this->session->disconnect();

            throw new McpProtocolException(
                "Unsupported protocol version: {$result->protocolVersion}. "
                . 'Supported: ' . implode(', ', ProtocolVersion::SUPPORTED)
            );
        }

        $this->session->completeInitialization($result);

        // Step 3: Send initialized notification
        $this->notify('notifications/initialized');
        $this->session->becomeOperational();
    }

    /**
     * Disconnect from the MCP server.
     */
    public function disconnect(): void
    {
        if (! $this->session->isConnected()) {
            return;
        }

        $this->session->beginShutdown();
        $this->transport->disconnect();
        $this->session->disconnect();
    }

    // ─── Message Sending ────────────────────────────────────────────

    /**
     * Send a JSON-RPC request and wait for the response.
     *
     * @return mixed The response result
     *
     * @throws McpTimeoutException
     * @throws McpProtocolException
     */
    public function request(string $method, array $params = [], ?float $timeout = null): mixed
    {
        $message = JsonRpcMessage::request($method, $params);
        $requestId = $message->id;
        $timeout ??= $this->requestTimeout;

        $this->transport->send($message);

        // Wait for the response with this ID
        $deadline = microtime(true) + $timeout;

        while (microtime(true) < $deadline) {
            // Check if we already have a response
            if (isset($this->responses[$requestId])) {
                $response = $this->responses[$requestId];
                unset($this->responses[$requestId]);

                if ($response->isError()) {
                    throw McpProtocolException::fromError(
                        $response->errorCode(),
                        $response->errorMessage(),
                        $response->error['data'] ?? null,
                    );
                }

                return $response->result;
            }

            // Try to read more messages
            $incoming = $this->transport->receive();
            if ($incoming !== null) {
                $this->routeMessage($incoming);
            } else {
                usleep(10_000); // 10ms
            }
        }

        throw new McpTimeoutException(
            "Request '{$method}' (id: {$requestId}) timed out after {$timeout}s."
        );
    }

    /**
     * Send a JSON-RPC notification (no response expected).
     */
    public function notify(string $method, array $params = []): void
    {
        $message = JsonRpcMessage::notification($method, $params);
        $this->transport->send($message);
    }

    /**
     * Send a JSON-RPC response to a server request.
     */
    public function respond(string|int $id, mixed $result): void
    {
        $message = JsonRpcMessage::result($id, $result);
        $this->transport->send($message);
    }

    /**
     * Send a JSON-RPC error response to a server request.
     */
    public function respondWithError(string|int $id, int $code, string $message, mixed $data = null): void
    {
        $msg = JsonRpcMessage::error($id, $code, $message, $data);
        $this->transport->send($msg);
    }

    // ─── Feature Accessors ──────────────────────────────────────────

    /**
     * Get the tool proxy for discovering and calling MCP tools.
     */
    public function tools(): McpToolProxy
    {
        return $this->toolProxy;
    }

    /**
     * Get the resource manager for listing and reading resources.
     */
    public function resources(): ResourceManager
    {
        return $this->resourceManager;
    }

    /**
     * Get the prompt manager for listing and getting prompts.
     */
    public function prompts(): PromptManager
    {
        return $this->promptManager;
    }

    /**
     * Get the roots provider.
     */
    public function roots(): RootsProvider
    {
        return $this->rootsProvider;
    }

    /**
     * Get the sampling handler.
     */
    public function sampling(): SamplingHandler
    {
        return $this->samplingHandler;
    }

    /**
     * Get the elicitation handler.
     */
    public function elicitation(): ElicitationHandler
    {
        return $this->elicitationHandler;
    }

    /**
     * Get the ping handler.
     */
    public function ping(): PingHandler
    {
        return $this->pingHandler;
    }

    /**
     * Get the progress tracker.
     */
    public function progress(): ProgressTracker
    {
        return $this->progressTracker;
    }

    /**
     * Get the cancellation manager.
     */
    public function cancellation(): CancellationManager
    {
        return $this->cancellationManager;
    }

    /**
     * Get the log receiver.
     */
    public function logging(): LogReceiver
    {
        return $this->logReceiver;
    }

    /**
     * Get the completion client.
     */
    public function completions(): CompletionClient
    {
        return $this->completionClient;
    }

    // ─── State Accessors ────────────────────────────────────────────

    /**
     * Get the session state.
     */
    public function session(): McpSession
    {
        return $this->session;
    }

    /**
     * Get the server configuration.
     */
    public function serverConfig(): McpServer
    {
        return $this->serverConfig;
    }

    /**
     * Get the server capabilities (available after connection).
     */
    public function serverCapabilities(): ?ServerCapabilities
    {
        return $this->session->serverCapabilities();
    }

    /**
     * Get the server name.
     */
    public function serverName(): string
    {
        return $this->session->serverName() ?? $this->serverConfig->name;
    }

    /**
     * Check if the client is connected and operational.
     */
    public function isConnected(): bool
    {
        return $this->session->isOperational() && $this->transport->isConnected();
    }

    /**
     * Ensure the server supports a capability before calling it.
     *
     * @throws McpCapabilityException
     */
    public function requireCapability(string $capability): void
    {
        $caps = $this->session->serverCapabilities();

        if ($caps === null) {
            throw new McpCapabilityException(
                "Cannot check capability '{$capability}': not connected."
            );
        }

        $supported = match ($capability) {
            'tools' => $caps->supportsTools(),
            'resources' => $caps->supportsResources(),
            'prompts' => $caps->supportsPrompts(),
            'logging' => $caps->supportsLogging(),
            'completions' => $caps->supportsCompletions(),
            'resources.subscribe' => $caps->supportsResourceSubscriptions(),
            default => throw new McpCapabilityException("Unknown capability: {$capability}"),
        };

        if (! $supported) {
            throw new McpCapabilityException(
                "Server '{$this->serverName()}' does not support '{$capability}'."
            );
        }
    }

    /**
     * Register a handler for server-initiated notifications.
     */
    public function onNotification(Closure $handler): void
    {
        $this->notificationHandlers[] = $handler;
    }

    // ─── Internal Message Routing ───────────────────────────────────

    /**
     * Handle an incoming message from the transport.
     */
    private function handleIncomingMessage(JsonRpcMessage $message): void
    {
        $this->routeMessage($message);
    }

    /**
     * Route a message to the appropriate handler.
     */
    private function routeMessage(JsonRpcMessage $message): void
    {
        if ($message->isResponse() || $message->isError()) {
            // Store the response for the pending request
            if ($message->id !== null) {
                $this->responses[$message->id] = $message;
            }

            return;
        }

        if ($message->isRequest()) {
            $this->handleServerRequest($message);

            return;
        }

        if ($message->isNotification()) {
            $this->handleServerNotification($message);

            return;
        }
    }

    /**
     * Handle a request from the server (e.g., roots/list, sampling/createMessage).
     */
    private function handleServerRequest(JsonRpcMessage $message): void
    {
        try {
            $result = match ($message->method) {
                'ping' => $this->pingHandler->handlePing($message),
                'roots/list' => $this->rootsProvider->handleList($message),
                'sampling/createMessage' => $this->samplingHandler->handleRequest($message),
                'elicitation/create' => $this->elicitationHandler->handleRequest($message),
                default => throw new McpProtocolException("Unknown server request: {$message->method}"),
            };

            $this->respond($message->id, $result);
        } catch (\Throwable $e) {
            $this->respondWithError(
                $message->id,
                -32601, // Method not found
                $e->getMessage(),
            );
        }
    }

    /**
     * Handle a notification from the server.
     */
    private function handleServerNotification(JsonRpcMessage $message): void
    {
        // Route known notifications to feature handlers
        match ($message->method) {
            'notifications/tools/list_changed' => $this->toolProxy->handleListChanged(),
            'notifications/resources/list_changed' => $this->resourceManager->handleListChanged(),
            'notifications/resources/updated' => $this->resourceManager->handleResourceUpdated($message),
            'notifications/prompts/list_changed' => $this->promptManager->handleListChanged(),
            'notifications/progress' => $this->progressTracker->handleProgress($message),
            'notifications/message' => $this->logReceiver->handleLogMessage($message),
            'notifications/cancelled' => $this->cancellationManager->handleCancelled($message),
            default => null, // Unknown notifications are silently ignored per spec
        };

        // Fire generic notification handlers
        foreach ($this->notificationHandlers as $handler) {
            $handler($message);
        }
    }

    /**
     * Process any pending incoming messages (non-blocking).
     */
    public function processMessages(): void
    {
        while ($message = $this->transport->receive()) {
            $this->routeMessage($message);
        }
    }

    // ─── Transport Factory ──────────────────────────────────────────

    /**
     * Create the appropriate transport for the server config.
     */
    private function createTransport(): TransportInterface
    {
        if ($this->serverConfig->isStdio()) {
            return new StdioTransport(
                command: $this->serverConfig->command,
                args: $this->serverConfig->args,
                env: $this->serverConfig->env,
                cwd: $this->serverConfig->cwd,
                shutdownTimeout: (float) config('agentic.mcp.transports.stdio.shutdown_timeout', 5),
                sigtermTimeout: (float) config('agentic.mcp.transports.stdio.sigterm_timeout', 3),
            );
        }

        if ($this->serverConfig->isHttp()) {
            return new StreamableHttpTransport(
                url: $this->serverConfig->url,
                headers: $this->serverConfig->headers,
                timeout: (float) config('agentic.mcp.transports.http.timeout', 30),
            );
        }

        throw new McpConnectionException(
            "Unsupported transport type: {$this->serverConfig->transport}"
        );
    }

    public function __destruct()
    {
        $this->disconnect();
    }
}
