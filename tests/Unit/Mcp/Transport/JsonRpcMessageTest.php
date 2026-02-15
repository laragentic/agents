<?php

declare(strict_types=1);

use Laragentic\Mcp\Transport\JsonRpcMessage;

beforeEach(function () {
    JsonRpcMessage::resetIdCounter();
});

describe('JsonRpcMessage', function () {
    describe('factory methods', function () {
        it('creates a request message', function () {
            $message = JsonRpcMessage::request('tools/list', ['cursor' => 'abc'], 42);

            expect($message->isRequest())->toBeTrue();
            expect($message->isNotification())->toBeFalse();
            expect($message->isResponse())->toBeFalse();
            expect($message->isError())->toBeFalse();
            expect($message->jsonrpc)->toBe('2.0');
            expect($message->id)->toBe(42);
            expect($message->method)->toBe('tools/list');
            expect($message->params)->toBe(['cursor' => 'abc']);
        });

        it('auto-increments request IDs', function () {
            $msg1 = JsonRpcMessage::request('ping');
            $msg2 = JsonRpcMessage::request('ping');

            expect($msg1->id)->toBe(1);
            expect($msg2->id)->toBe(2);
        });

        it('creates a notification message', function () {
            $message = JsonRpcMessage::notification('notifications/initialized');

            expect($message->isNotification())->toBeTrue();
            expect($message->isRequest())->toBeFalse();
            expect($message->id)->toBeNull();
            expect($message->method)->toBe('notifications/initialized');
        });

        it('creates a success response', function () {
            $message = JsonRpcMessage::result(1, ['tools' => []]);

            expect($message->isResponse())->toBeTrue();
            expect($message->isError())->toBeFalse();
            expect($message->id)->toBe(1);
            expect($message->result)->toBe(['tools' => []]);
        });

        it('creates an error response', function () {
            $message = JsonRpcMessage::error(1, -32601, 'Method not found', ['detail' => 'foo']);

            expect($message->isError())->toBeTrue();
            expect($message->isResponse())->toBeFalse();
            expect($message->errorCode())->toBe(-32601);
            expect($message->errorMessage())->toBe('Method not found');
            expect($message->error['data'])->toBe(['detail' => 'foo']);
        });
    });

    describe('serialization', function () {
        it('serializes a request to array', function () {
            $message = JsonRpcMessage::request('initialize', ['protocolVersion' => '2025-11-25'], 1);
            $array = $message->toArray();

            expect($array)->toBe([
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'initialize',
                'params' => ['protocolVersion' => '2025-11-25'],
            ]);
        });

        it('serializes a notification to array', function () {
            $message = JsonRpcMessage::notification('notifications/initialized');
            $array = $message->toArray();

            expect($array)->toBe([
                'jsonrpc' => '2.0',
                'method' => 'notifications/initialized',
            ]);
        });

        it('serializes to JSON', function () {
            $message = JsonRpcMessage::request('ping', [], 1);
            $json = $message->toJson();

            expect($json)->toBe('{"jsonrpc":"2.0","id":1,"method":"ping"}');
        });

        it('serializes a response with null result', function () {
            $message = JsonRpcMessage::result(1, null);
            $array = $message->toArray();

            expect($array['result'])->toBeInstanceOf(stdClass::class);
        });
    });

    describe('deserialization', function () {
        it('deserializes from array', function () {
            $message = JsonRpcMessage::fromArray([
                'jsonrpc' => '2.0',
                'id' => 1,
                'result' => ['tools' => []],
            ]);

            expect($message->isResponse())->toBeTrue();
            expect($message->result)->toBe(['tools' => []]);
        });

        it('deserializes from JSON', function () {
            $json = '{"jsonrpc":"2.0","id":1,"method":"ping"}';
            $message = JsonRpcMessage::fromJson($json);

            expect($message->isRequest())->toBeTrue();
            expect($message->method)->toBe('ping');
        });

        it('throws on invalid JSON-RPC version', function () {
            JsonRpcMessage::fromArray([
                'jsonrpc' => '1.0',
                'id' => 1,
            ]);
        })->throws(InvalidArgumentException::class);

        it('throws on invalid JSON', function () {
            JsonRpcMessage::fromJson('not json');
        })->throws(JsonException::class);
    });
});
