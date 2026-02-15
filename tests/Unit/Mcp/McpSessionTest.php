<?php

declare(strict_types=1);

use Laragentic\Mcp\McpSession;
use Laragentic\Mcp\Protocol\InitializeResult;
use Laragentic\Mcp\Protocol\ServerCapabilities;

describe('McpSession', function () {
    it('starts in disconnected phase', function () {
        $session = new McpSession;

        expect($session->phase())->toBe(McpSession::PHASE_DISCONNECTED);
        expect($session->isConnected())->toBeFalse();
        expect($session->isOperational())->toBeFalse();
    });

    it('transitions through initialization phases', function () {
        $session = new McpSession;

        $session->beginInitialization();
        expect($session->phase())->toBe(McpSession::PHASE_INITIALIZING);
        expect($session->isConnected())->toBeTrue();
        expect($session->isOperational())->toBeFalse();

        $result = InitializeResult::fromArray([
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => []],
            'serverInfo' => ['name' => 'test', 'version' => '1.0'],
        ]);

        $session->completeInitialization($result);
        expect($session->phase())->toBe(McpSession::PHASE_INITIALIZED);
        expect($session->isConnected())->toBeTrue();

        $session->becomeOperational();
        expect($session->phase())->toBe(McpSession::PHASE_OPERATIONAL);
        expect($session->isOperational())->toBeTrue();
    });

    it('provides server info after initialization', function () {
        $session = new McpSession;
        $session->beginInitialization();

        $result = InitializeResult::fromArray([
            'protocolVersion' => '2025-11-25',
            'capabilities' => ['tools' => ['listChanged' => true]],
            'serverInfo' => ['name' => 'test-server', 'version' => '2.0'],
            'instructions' => 'Be careful.',
        ]);

        $session->completeInitialization($result);

        expect($session->serverName())->toBe('test-server');
        expect($session->serverVersion())->toBe('2.0');
        expect($session->protocolVersion())->toBe('2025-11-25');
        expect($session->instructions())->toBe('Be careful.');
        expect($session->serverCapabilities())->toBeInstanceOf(ServerCapabilities::class);
        expect($session->serverCapabilities()->supportsTools())->toBeTrue();
    });

    it('handles shutdown and disconnect', function () {
        $session = new McpSession;
        $session->beginInitialization();
        $result = InitializeResult::fromArray([
            'protocolVersion' => '2025-11-25',
            'capabilities' => [],
            'serverInfo' => ['name' => 'test', 'version' => '1.0'],
        ]);
        $session->completeInitialization($result);
        $session->becomeOperational();

        $session->beginShutdown();
        expect($session->phase())->toBe(McpSession::PHASE_SHUTTING_DOWN);

        $session->disconnect();
        expect($session->phase())->toBe(McpSession::PHASE_DISCONNECTED);
        expect($session->serverCapabilities())->toBeNull();
        expect($session->serverName())->toBeNull();
    });

    it('manages session ID', function () {
        $session = new McpSession;

        expect($session->sessionId())->toBeNull();

        $session->setSessionId('abc-123');
        expect($session->sessionId())->toBe('abc-123');
    });
});
