<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\MCP\Concerns\SpeaksMcp;

uses(RefreshDatabase::class, SpeaksMcp::class);

/**
 * Decode the first text content block of a tools/call result.
 *
 * @return array<string, mixed>
 */
function mcpHealthPayload(TestResponse $response): array
{
    return (array) json_decode((string) $response->json('result.content.0.text'), true);
}

test('health_check returns success and the app version for a read-only token', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('health_check');

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpHealthPayload($response);

    expect($payload)->toBe([
        'success' => true,
        'version' => config('app.version'),
    ]);
});

test('health_check returns success and the app version for a write-only token', function () {
    $plainToken = $this->user->createToken('mcp-write', ['write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('health_check');

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpHealthPayload($response);

    expect($payload['success'])->toBeTrue()
        ->and($payload['version'])->toBe(config('app.version'));
});

test('health_check returns exactly success and version and nothing else', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpHealthPayload($this->mcpCallTool('health_check'));

    expect(array_keys($payload))->toBe(['success', 'version'])
        ->and($payload['success'])->toBeTrue()
        ->and($payload['version'])->toBe(config('app.version'));
});

test('health_check is project-free and ignores any supplied arguments', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    // Extra arguments must be ignored: the probe performs no project or server
    // lookups and is not ability-gated beyond the transport.
    $response = $this->mcpCallTool('health_check', ['project_id' => 1, 'server_id' => 2, 'confirm' => true]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpHealthPayload($response);

    expect($payload)->toBe([
        'success' => true,
        'version' => config('app.version'),
    ]);
});

test('tools/list exposes health_check as a project-free probe with no input schema', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $tools = collect($this->mcpListTools()->json('result.tools'))->keyBy('name');

    expect($tools)->toHaveKey('health_check')
        ->and($tools['health_check']['inputSchema']['properties'] ?? [])->toBe([]);
});
