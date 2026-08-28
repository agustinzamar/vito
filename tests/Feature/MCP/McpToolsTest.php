<?php

use App\Actions\Server\RebootServer;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Feature\MCP\Concerns\SpeaksMcp;

uses(RefreshDatabase::class, SpeaksMcp::class);

/**
 * Decode the first text content block of a tools/call result.
 *
 * @return array<string, mixed>
 */
function mcpToolPayload(TestResponse $response): array
{
    return (array) json_decode((string) $response->json('result.content.0.text'), true);
}

test('list_projects returns visible projects with credential-free fields only', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    /** @var Project $secondProject */
    $secondProject = Project::factory()->create();
    $secondProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);

    $response = $this->mcpCallTool('list_projects');

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    expect(count($payload['projects']))->toBe(2)
        ->and(collect($payload['projects'])->pluck('id')->toArray())
        ->toEqual([$this->user->current_project_id, $secondProject->id]);

    foreach ($payload['projects'] as $project) {
        expect(array_keys($project))->toEqual(['id', 'name', 'role', 'created_at', 'updated_at']);
    }
});

test('list_projects is restricted to a token project scope', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::USER,
    ]);
    $this->user->update(['current_project_id' => $scopedProject->id]);

    /** @var Project $otherProject */
    $otherProject = Project::factory()->create();
    $otherProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::USER,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_projects');

    $payload = mcpToolPayload($response);

    expect(count($payload['projects']))->toBe(1)
        ->and($payload['projects'][0]['id'])->toBe($scopedProject->id);
});

test('list_projects requires a read-capable token', function () {
    $plainToken = $this->user->createToken('mcp-no-read', ['write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_projects');

    $payload = mcpToolPayload($response);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($payload['error']['code'])->toBe('forbidden_ability')
        ->and($payload['error']['message'])->toBeString();
});

test('list_servers without project_id returns a stable validation error and performs no query', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_servers');

    $payload = mcpToolPayload($response);

    expect($response->json('result.isError'))->toBeTrue()
        ->and($payload['error']['code'])->toBe('validation')
        ->and($payload['error']['message'])->toContain('project_id');
});

test('list_servers with a non-integer project_id returns the same validation error', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('list_servers', ['project_id' => 'abc']));

    expect($payload['error']['code'])->toBe('validation');
});

test('list_servers returns only the requested project servers with safe fields', function () {
    Server::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    /** @var Project $otherProject */
    $otherProject = Project::factory()->create();
    $otherProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);
    Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $otherProject->id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_servers', ['project_id' => $this->user->current_project_id]);

    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    // The base TestCase already seeds one server in the current project,
    // so 2 created + 1 seeded = 3; the other project's server must never appear.
    expect(collect($payload['servers'])->pluck('project_id')->unique()->values()->toArray())
        ->toEqual([$this->user->current_project_id])
        ->and(count($payload['servers']))->toBe(3);

    foreach ($payload['servers'] as $server) {
        expect(array_keys($server))->toEqual([
            'id', 'project_id', 'name', 'ip', 'local_ip', 'port', 'os', 'status',
            'auto_update', 'auto_update_schedule', 'last_update_check', 'created_at', 'updated_at',
        ])
            ->and(json_encode($server))->not->toContain('super-secret')
            ->not->toContain('AAAA')
            ->not->toContain('provider_data');
    }
});

test('list_servers denies a token scoped to another project with forbidden_scope', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::USER,
    ]);

    /** @var Project $targetProject */
    $targetProject = Project::factory()->create();
    $targetProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('list_servers', ['project_id' => $targetProject->id]));

    expect(mcpToolPayload($this->mcpCallTool('list_servers', ['project_id' => $targetProject->id]))['error']['code'])
        ->toBe('forbidden_scope')
        ->and(json_encode($payload))->not->toContain((string) $targetProject->name);
});

test('list_servers returns not_found for a missing project', function () {
    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('list_servers', ['project_id' => 999999]));

    expect($payload['error']['code'])->toBe('not_found')
        ->and($payload['error']['message'])->toBe('The requested resource was not found.');
});

test('list_servers requires a read-capable token', function () {
    $plainToken = $this->user->createToken('mcp-write-only', ['write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('list_servers', ['project_id' => $this->user->current_project_id]));

    expect($payload['error']['code'])->toBe('forbidden_ability');
});

test('get_server returns a single credential-free representation', function () {
    /** @var Server $server */
    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('get_server', [
        'project_id' => $this->user->current_project_id,
        'server_id' => $server->id,
    ]);

    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    expect($payload['server']['id'])->toBe($server->id)
        ->and(array_keys($payload['server']))->toEqual([
            'id', 'project_id', 'name', 'ip', 'local_ip', 'port', 'os', 'status',
            'auto_update', 'auto_update_schedule', 'last_update_check', 'created_at', 'updated_at',
        ])
        ->and(json_encode($payload))->not->toContain('super-secret')
        ->not->toContain('AAAA')
        ->not->toContain('provider_data');
});

test('get_server for another project\'s server returns not_found without leaking attributes', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::USER,
    ]);
    $this->user->update(['current_project_id' => $scopedProject->id]);

    /** @var Server $foreignServer */
    $foreignServer = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    // Ask about the foreign server through an unrelated project id pair:
    // the relationship lookup must simply find nothing.
    $payload = mcpToolPayload($this->mcpCallTool('get_server', [
        'project_id' => 999999,
        'server_id' => $foreignServer->id,
    ]));

    expect($payload['error']['code'])->toBe('not_found')
        ->and($payload['error']['message'])->toBe('The requested resource was not found.')
        ->and(json_encode($payload))->not->toContain($foreignServer->name)
        ->not->toContain((string) $foreignServer->ip)
        ->not->toContain((string) $foreignServer->ssh_user);
});

test('get_server validates both ids and rejects out-of-scope or missing projects', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::USER,
    ]);
    $this->user->update(['current_project_id' => $scopedProject->id]);

    /** @var Project $targetProject */
    $targetProject = Project::factory()->create();
    $targetProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    // Missing server_id → validation naming the parameter.
    expect(mcpToolPayload($this->mcpCallTool('get_server', [
        'project_id' => $scopedProject->id,
    ]))['error']['code'])->toBe('validation')
    // Non-integer server_id → same validation shape.
        ->and(mcpToolPayload($this->mcpCallTool('get_server', [
            'project_id' => $scopedProject->id,
            'server_id' => 'abc',
        ]))['error']['code'])->toBe('validation')
    // Token scoped elsewhere → forbidden_scope, no project name leaked.
        ->and(mcpToolPayload($this->mcpCallTool('get_server', [
            'project_id' => $targetProject->id,
            'server_id' => 1,
        ]))['error']['code'])->toBe('forbidden_scope')
    // Missing project → not_found.
        ->and(mcpToolPayload($this->mcpCallTool('get_server', [
            'project_id' => 999999,
            'server_id' => 1,
        ]))['error']['code'])->toBe('not_found');
});

test('get_server requires a read-capable token', function () {
    $plainToken = $this->user->createToken('mcp-write-only', ['write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('get_server', [
        'project_id' => $this->user->current_project_id,
        'server_id' => 1,
    ]));

    expect($payload['error']['code'])->toBe('forbidden_ability');
});

test('reboot_server denies a write-incapable token even with confirm and dispatches nothing', function () {
    /** @var Server $server */
    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    /** @var MockInterface|RebootServer $spy */
    $spy = $this->spy(RebootServer::class);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('reboot_server', [
        'project_id' => $this->user->current_project_id,
        'server_id' => $server->id,
        'confirm' => true,
    ]));

    expect($payload['error']['code'])->toBe('forbidden_ability');

    $spy->shouldNotHaveReceived('reboot');
});

test('reboot_server without explicit confirm returns confirmation_required and dispatches nothing', function () {
    /** @var Server $server */
    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    /** @var MockInterface|RebootServer $spy */
    $spy = $this->spy(RebootServer::class);

    $plainToken = $this->user->createToken('mcp-rw', ['read', 'write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    // confirm omitted entirely.
    expect(mcpToolPayload($this->mcpCallTool('reboot_server', [
        'project_id' => $this->user->current_project_id,
        'server_id' => $server->id,
    ]))['error']['code'])->toBe('confirmation_required')
    // confirm explicitly false → same outcome.
        ->and(mcpToolPayload($this->mcpCallTool('reboot_server', [
            'project_id' => $this->user->current_project_id,
            'server_id' => $server->id,
            'confirm' => false,
        ]))['error']['code'])->toBe('confirmation_required');

    $spy->shouldNotHaveReceived('reboot');
});

test('reboot_server checks scope before confirmation for out-of-scope tokens', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::USER,
    ]);
    $this->user->update(['current_project_id' => $scopedProject->id]);

    /** @var Project $targetProject */
    $targetProject = Project::factory()->create();
    $targetProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);

    /** @var Server $foreignServer */
    $foreignServer = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $targetProject->id,
    ]);

    /** @var MockInterface|RebootServer $spy */
    $spy = $this->spy(RebootServer::class);

    $plainToken = $this->user->createToken('mcp-scoped', ['read', 'write', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('reboot_server', [
        'project_id' => $targetProject->id,
        'server_id' => $foreignServer->id,
        'confirm' => true,
    ]));

    expect($payload['error']['code'])->toBe('forbidden_scope')
        ->and(json_encode($payload))->not->toContain((string) $foreignServer->ip);

    $spy->shouldNotHaveReceived('reboot');
});

test('reboot_server with confirm dispatches RebootServer exactly once and returns a safe payload', function () {
    /** @var Server $server */
    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $action = Mockery::mock(RebootServer::class);
    $action->shouldReceive('reboot')->once()->andReturn($server);
    $this->app->instance(RebootServer::class, $action);

    $plainToken = $this->user->createToken('mcp-rw', ['read', 'write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('reboot_server', [
        'project_id' => $this->user->current_project_id,
        'server_id' => $server->id,
        'confirm' => true,
    ]);

    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    expect($payload['server']['id'])->toBe($server->id)
        ->and(array_keys($payload['server']))->toEqual([
            'id', 'project_id', 'name', 'ip', 'local_ip', 'port', 'os', 'status',
            'auto_update', 'auto_update_schedule', 'last_update_check', 'created_at', 'updated_at',
        ])
        ->and(json_encode($payload))->not->toContain('provider_data')
        ->not->toContain('AAAA');
});

test('list_servers infers project_id from a single token project scope', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);
    Server::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'project_id' => $scopedProject->id,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped-single', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_servers');

    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    expect(collect($payload['servers'])->pluck('project_id')->unique()->values()->toArray())
        ->toEqual([$scopedProject->id])
        ->and(count($payload['servers']))->toBe(2);
});

test('get_server infers project_id from a single token project scope', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);
    /** @var Server $server */
    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $scopedProject->id,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped-single', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('get_server', ['server_id' => $server->id]);

    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    expect($payload['server']['id'])->toBe($server->id)
        ->and($payload['server']['project_id'])->toBe($scopedProject->id);
});

test('reboot_server infers project_id from a single token project scope and dispatches once', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);
    /** @var Server $server */
    $server = Server::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $scopedProject->id,
    ]);

    $action = Mockery::mock(RebootServer::class);
    $action->shouldReceive('reboot')->once()->andReturn($server);
    $this->app->instance(RebootServer::class, $action);

    $plainToken = $this->user->createToken('mcp-scoped-single-rw', ['read', 'write', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('reboot_server', ['server_id' => $server->id, 'confirm' => true]);

    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    expect($payload['server']['id'])->toBe($server->id);
});

test('list_servers requires project_id when the token is scoped to multiple projects', function () {
    /** @var Project $projectA */
    $projectA = Project::factory()->create();
    $projectA->users()->create(['user_id' => $this->user->id, 'role' => UserRole::USER]);
    /** @var Project $projectB */
    $projectB = Project::factory()->create();
    $projectB->users()->create(['user_id' => $this->user->id, 'role' => UserRole::USER]);

    $plainToken = $this->user->createToken('mcp-multi-scope', ['read', 'project:'.$projectA->id, 'project:'.$projectB->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('list_servers'));

    expect($payload['error']['code'])->toBe('validation')
        ->and($payload['error']['message'])->toContain('project_id');
});

test('list_servers explicit project_id is honored over multi-scope ambiguity', function () {
    /** @var Project $projectA */
    $projectA = Project::factory()->create();
    $projectA->users()->create(['user_id' => $this->user->id, 'role' => UserRole::ADMIN]);
    Server::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'project_id' => $projectA->id,
    ]);

    /** @var Project $projectB */
    $projectB = Project::factory()->create();
    $projectB->users()->create(['user_id' => $this->user->id, 'role' => UserRole::USER]);

    $plainToken = $this->user->createToken('mcp-multi-scope', ['read', 'project:'.$projectA->id, 'project:'.$projectB->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_servers', ['project_id' => $projectA->id]);

    expect($response->json('result.isError'))->toBeFalse();

    $payload = mcpToolPayload($response);

    expect(collect($payload['servers'])->pluck('project_id')->unique()->values()->toArray())
        ->toEqual([$projectA->id])
        ->and(count($payload['servers']))->toBe(2);
});

test('list_servers with a single-scope token but no read ability returns forbidden_ability, not inference', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create(['user_id' => $this->user->id, 'role' => UserRole::USER]);

    $plainToken = $this->user->createToken('mcp-write-single', ['write', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('list_servers'));

    expect($payload['error']['code'])->toBe('forbidden_ability');
});

test('reboot_server with a single-scope read-only token returns forbidden_ability, not inference', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create(['user_id' => $this->user->id, 'role' => UserRole::USER]);

    $plainToken = $this->user->createToken('mcp-read-single', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = mcpToolPayload($this->mcpCallTool('reboot_server', ['server_id' => 1, 'confirm' => true]));

    expect($payload['error']['code'])->toBe('forbidden_ability');
});

test('tools/list exposes the baseline native tools with schemas', function () {
    $plainToken = $this->user->createToken('mcp-discovery', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpListTools();

    $tools = collect($response->json('result.tools'));
    $names = $tools->pluck('name')->values()->toArray();

    expect($names)->toContain('list_projects', 'list_servers', 'get_server', 'reboot_server')
        ->and(collect($response->json('result.tools'))->first(fn ($tool) => $tool['name'] === 'list_projects')['inputSchema'])
        ->toHaveKey('properties');
});

test('no resources or prompts are registered on the MCP server', function () {
    $plainToken = $this->user->createToken('mcp-discovery', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $resources = $this->mcpRequest('resources/list', [], id: 7);
    $prompts = $this->mcpRequest('prompts/list', [], id: 8);

    expect($resources->json('result.resources'))->toEqual([])
        ->and($prompts->json('result.prompts'))->toEqual([]);
});

test('tools/list describes reboot_server operational impact and confirmation behavior', function () {
    $plainToken = $this->user->createToken('mcp-discovery', ['read', 'write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $reboot = collect($this->mcpListTools()->json('result.tools'))
        ->first(fn ($tool) => $tool['name'] === 'reboot_server');

    expect($reboot)->not->toBeNull()
        ->and($reboot['description'])
        ->toContain('Interrupts services on the server while it restarts.')
        ->toContain('confirm: true');
});

test('tools/list publishes a usable input schema for every parameterized tool', function () {
    $plainToken = $this->user->createToken('mcp-schema', ['read', 'write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $tools = collect($this->mcpListTools()->json('result.tools'))->keyBy('name');

    // The serializer omits `required` entirely when a tool has no required
    // properties, so read it defensively.
    $requiredOf = static fn (string $name): array => $tools[$name]['inputSchema']['required'] ?? [];

    expect($tools['list_projects']['inputSchema']['properties'])->toBe([])
        ->and($requiredOf('list_servers'))->toBe([])
        ->and($tools['list_servers']['inputSchema']['properties'])->toHaveKey('project_id')
        ->and($tools['list_servers']['inputSchema']['properties']['project_id']['type'])->toBe('integer')
        ->and($requiredOf('get_server'))->toBe(['server_id'])
        ->and($tools['get_server']['inputSchema']['properties'])->toHaveKey('project_id')
        ->and($tools['get_server']['inputSchema']['properties']['server_id']['type'])->toBe('integer')
        ->and($requiredOf('reboot_server'))->toBe(['server_id', 'confirm'])
        ->and($tools['reboot_server']['inputSchema']['properties'])->toHaveKey('project_id')
        ->and($tools['reboot_server']['inputSchema']['properties']['confirm']['type'])->toBe('boolean');
});
