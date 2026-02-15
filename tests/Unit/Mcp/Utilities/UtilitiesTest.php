<?php

declare(strict_types=1);

use Laragentic\Mcp\Exceptions\McpProtocolException;
use Laragentic\Mcp\Transport\JsonRpcMessage;

describe('McpProtocolException', function () {
    it('creates from error', function () {
        $exception = McpProtocolException::fromError(-32601, 'Method not found', ['detail' => 'test']);

        expect($exception->getMessage())->toBe('Method not found');
        expect($exception->errorCode())->toBe(-32601);
        expect($exception->errorData())->toBe(['detail' => 'test']);
    });

    it('detects URL elicitation required error', function () {
        $exception = McpProtocolException::fromError(-32042, 'URL elicitation required');

        expect($exception->isUrlElicitationRequired())->toBeTrue();
    });

    it('non-elicitation errors return false', function () {
        $exception = McpProtocolException::fromError(-32601, 'Method not found');

        expect($exception->isUrlElicitationRequired())->toBeFalse();
    });
});

describe('JsonRpcMessage edge cases', function () {
    it('handles notification with empty params', function () {
        $msg = JsonRpcMessage::notification('notifications/initialized', []);

        expect($msg->isNotification())->toBeTrue();
        expect($msg->params)->toBeNull();
    });

    it('handles request with empty params', function () {
        $msg = JsonRpcMessage::request('ping', []);

        expect($msg->isRequest())->toBeTrue();
        expect($msg->params)->toBeNull();
    });

    it('error code returns null for non-errors', function () {
        $msg = JsonRpcMessage::result(1, 'ok');

        expect($msg->errorCode())->toBeNull();
        expect($msg->errorMessage())->toBeNull();
    });
});
