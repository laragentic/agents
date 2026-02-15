<?php

declare(strict_types=1);

use Laragentic\Mcp\HasMcpClients;
use Laragentic\Mcp\McpServer;

describe('HasMcpClients', function () {
    it('starts with empty clients', function () {
        $agent = new class
        {
            use HasMcpClients;
        };

        expect($agent->mcpClientMap())->toBe([]);
    });

    it('adds server via fluent API', function () {
        $agent = new class
        {
            use HasMcpClients;
        };

        $server = McpServer::stdio('test', 'echo');
        $agent->withMcpServer($server);

        expect($agent->mcpClientMap())->toHaveKey('test');
        expect($agent->mcpClient('test'))->not->toBeNull();
    });

    it('adds multiple servers', function () {
        $agent = new class
        {
            use HasMcpClients;
        };

        $agent->withMcpServers([
            McpServer::stdio('server1', 'echo'),
            McpServer::stdio('server2', 'cat'),
        ]);

        expect($agent->mcpClientMap())->toHaveCount(2);
        expect($agent->mcpClient('server1'))->not->toBeNull();
        expect($agent->mcpClient('server2'))->not->toBeNull();
    });

    it('loads servers from mcpServers method', function () {
        $agent = new class
        {
            use HasMcpClients;

            public function mcpServers(): array
            {
                return [
                    McpServer::stdio('auto1', 'echo'),
                    McpServer::stdio('auto2', 'cat'),
                ];
            }
        };

        // Trigger initialization
        $agent->withMcpServer(McpServer::stdio('manual', 'ls'));

        expect($agent->mcpClientMap())->toHaveCount(3);
    });

    it('returns null for unknown server', function () {
        $agent = new class
        {
            use HasMcpClients;
        };

        expect($agent->mcpClient('nonexistent'))->toBeNull();
    });

    it('supports callback registration', function () {
        $agent = new class
        {
            use HasMcpClients;
        };

        $called = false;

        $result = $agent->onMcpServerConnected(function () use (&$called) {
            $called = true;
        });

        expect($result)->toBe($agent); // fluent return
    });

    it('supports all callback types', function () {
        $agent = new class
        {
            use HasMcpClients;
        };

        // Each should return $this for fluent API
        expect($agent->onMcpServerConnected(fn () => null))->toBe($agent);
        expect($agent->onMcpServerDisconnected(fn () => null))->toBe($agent);
        expect($agent->onMcpServerConnectionFailed(fn () => null))->toBe($agent);
        expect($agent->onMcpToolCalled(fn () => null))->toBe($agent);
        expect($agent->onMcpSamplingRequested(fn () => null))->toBe($agent);
        expect($agent->onMcpResourceUpdated(fn () => null))->toBe($agent);
    });
});
