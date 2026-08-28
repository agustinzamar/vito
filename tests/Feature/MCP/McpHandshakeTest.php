<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MCP\Concerns\SpeaksMcp;

uses(RefreshDatabase::class, SpeaksMcp::class);

test('unauthenticated MCP request is rejected without exposing internals', function () {
    $response = $this->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => ['name' => 'vito-feature-test', 'version' => '1.0.0'],
        ],
    ], ['Accept' => 'application/json, text/event-stream']);

    $response->assertStatus(401);

    $body = $response->getContent();

    expect($body)
        ->not->toContain('list_projects')
        ->not->toContain('list_servers')
        ->not->toContain('get_server')
        ->not->toContain('reboot_server')
        ->not->toContain('Exception')
        ->not->toContain('vendor/')
        ->not->toContain('Stack trace');

    expect($response->headers->get('WWW-Authenticate'))->toContain('Bearer');
});

test('valid personal access token completes MCP initialize handshake', function () {
    $plainToken = $this->user->createToken('mcp-handshake', ['read'])->plainTextToken;

    $response = $this->mcpInitialize($plainToken);

    $response->assertOk();

    $result = $response->json('result');

    expect($result['serverInfo']['name'])->toBe('vito')
        ->and($result['protocolVersion'])->toBeIn(['2025-06-18', '2025-03-26', '2024-11-05'])
        ->and($this->mcpSessionId)->not->toBeNull();
});

test('tools/list works over the established authenticated session', function () {
    $plainToken = $this->user->createToken('mcp-session', ['read'])->plainTextToken;

    $this->mcpInitialize($plainToken);

    $response = $this->mcpListTools();

    $response->assertOk();
    expect($response->json('result.tools'))->toBeArray();
});

test('invalid bearer token is rejected like a missing one', function () {
    $response = $this->postJson('/api/mcp', [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [],
    ], $this->mcpHeaders('invalid-token-value'));

    $response->assertStatus(401);
});

test('read-only token can initialize and discover MCP tools', function () {
    $plainToken = $this->user->createToken('mcp-read-only', ['read'])->plainTextToken;

    $response = $this->mcpInitialize($plainToken);

    $response->assertOk();
    expect($this->mcpSessionId)->not->toBeNull();

    $tools = $this->mcpListTools();
    $tools->assertOk();
    expect($tools->json('result.tools'))->toBeArray();
});

test('write-only token can initialize and discover MCP tools', function () {
    $plainToken = $this->user->createToken('mcp-write-only', ['write'])->plainTextToken;

    $response = $this->mcpInitialize($plainToken);

    $response->assertOk();
    expect($this->mcpSessionId)->not->toBeNull();

    $tools = $this->mcpListTools();
    $tools->assertOk();
    expect($tools->json('result.tools'))->toBeArray();
});

test('MCP endpoint only accepts POST, keeping exactly one HTTP transport', function () {
    $plainToken = $this->user->createToken('mcp-transport', ['read'])->plainTextToken;

    $headers = ['Authorization' => 'Bearer '.$plainToken];

    $this->get('/api/mcp', $headers)->assertStatus(405);
    $this->delete('/api/mcp', [], $headers)->assertStatus(405);
});
