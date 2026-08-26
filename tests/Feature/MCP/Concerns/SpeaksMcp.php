<?php

namespace Tests\Feature\MCP\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Speaks the MCP Streamable HTTP protocol against the /api/mcp endpoint,
 * absorbing session-header handling so individual tests stay readable.
 */
trait SpeaksMcp
{
    protected ?string $mcpSessionId = null;

    /**
     * @param  array<string, string>  $extraHeaders
     * @return array<string, string>
     */
    protected function mcpHeaders(?string $bearerToken = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Accept' => 'application/json, text/event-stream',
        ], $extraHeaders);

        if ($bearerToken !== null && $bearerToken !== '') {
            $headers['Authorization'] = 'Bearer '.$bearerToken;
        }

        if ($this->mcpSessionId !== null) {
            $headers['MCP-Session-Id'] = $this->mcpSessionId;
        }

        return $headers;
    }

    /**
     * Perform a JSON-RPC request against the MCP endpoint, capturing any
     * MCP-Session-Id issued by the server for follow-up requests.
     *
     * @param  array<string, mixed>  $params
     */
    public function mcpRequest(string $method, array $params = [], int|string $id = 1, ?string $bearerToken = null): TestResponse
    {
        $response = $this->postJson('/api/mcp', [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ], $this->mcpHeaders($bearerToken));

        if ($response->headers->has('MCP-Session-Id')) {
            $this->mcpSessionId = $response->headers->get('MCP-Session-Id');
        }

        return $response;
    }

    public function mcpInitialize(?string $bearerToken = null): TestResponse
    {
        return $this->mcpRequest('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => [],
            'clientInfo' => [
                'name' => 'vito-feature-test',
                'version' => '1.0.0',
            ],
        ], bearerToken: $bearerToken);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function mcpCallTool(string $tool, array $arguments = [], int $id = 2): TestResponse
    {
        return $this->mcpRequest('tools/call', [
            'name' => $tool,
            'arguments' => $arguments,
        ], id: $id);
    }

    public function mcpListTools(int $id = 3): TestResponse
    {
        return $this->mcpRequest('tools/list', [], id: $id);
    }
}
