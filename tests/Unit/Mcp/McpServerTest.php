<?php

declare(strict_types=1);

use Laragentic\Mcp\McpServer;

describe('McpServer', function () {
    it('creates stdio server config', function () {
        $server = McpServer::stdio(
            'filesystem',
            'npx',
            ['-y', '@modelcontextprotocol/server-filesystem', '/tmp'],
        );

        expect($server->name)->toBe('filesystem');
        expect($server->transport)->toBe('stdio');
        expect($server->command)->toBe('npx');
        expect($server->args)->toBe(['-y', '@modelcontextprotocol/server-filesystem', '/tmp']);
        expect($server->isStdio())->toBeTrue();
        expect($server->isHttp())->toBeFalse();
    });

    it('creates HTTP server config', function () {
        $server = McpServer::http(
            'database',
            'https://db-server.example.com/mcp',
            ['Authorization' => 'Bearer token123'],
        );

        expect($server->name)->toBe('database');
        expect($server->transport)->toBe('http');
        expect($server->url)->toBe('https://db-server.example.com/mcp');
        expect($server->headers)->toBe(['Authorization' => 'Bearer token123']);
        expect($server->isHttp())->toBeTrue();
        expect($server->isStdio())->toBeFalse();
    });

    it('creates stdio server with env and cwd', function () {
        $server = McpServer::stdio(
            'test',
            '/usr/local/bin/mcp-server',
            [],
            ['API_KEY' => 'secret'],
            '/home/user',
        );

        expect($server->env)->toBe(['API_KEY' => 'secret']);
        expect($server->cwd)->toBe('/home/user');
    });

    it('supports trusted flag', function () {
        $server = McpServer::stdio('test', 'cmd', trusted: true);

        expect($server->trusted)->toBeTrue();
    });

    it('defaults to untrusted', function () {
        $server = McpServer::stdio('test', 'cmd');

        expect($server->trusted)->toBeFalse();
    });
});
