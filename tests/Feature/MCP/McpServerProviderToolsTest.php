<?php

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\MCP\Concerns\SpeaksMcp;

uses(RefreshDatabase::class, SpeaksMcp::class);

/**
 * Decode the first text content block of a tools/call result.
 *
 * @return array<string, mixed>
 */
function providerPayload(TestResponse $response): array
{
    return (array) json_decode((string) $response->json('result.content.0.text'), true);
}

test('list_server_providers returns the caller global and in-scope providers with safe fields only', function () {
    /** @var ServerProvider $global */
    $global = ServerProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => null,
    ]);
    /** @var ServerProvider $scoped */
    $scoped = ServerProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_server_providers');

    expect($response->json('result.isError'))->toBeFalse();

    $payload = providerPayload($response);

    $returnedIds = collect($payload['server_providers'])->pluck('id')->toArray();
    expect($returnedIds)->toEqualCanonicalizing([$global->id, $scoped->id]);

    foreach ($payload['server_providers'] as $provider) {
        expect(array_keys($provider))->toEqual([
            'id', 'project_id', 'global', 'name', 'provider', 'created_at', 'updated_at',
        ]);
    }

    $byId = collect($payload['server_providers'])->keyBy('id');
    expect($byId[$global->id]['global'])->toBeTrue()
        ->and($byId[$global->id]['project_id'])->toBeNull()
        ->and($byId[$scoped->id]['global'])->toBeFalse()
        ->and($byId[$scoped->id]['project_id'])->toBe($this->user->current_project_id);
});

test('list_server_providers omits providers owned by other users', function () {
    /** @var User $other */
    $other = User::factory()->create();
    $other->ensureHasDefaultProject();

    /** @var ServerProvider $othersProvider */
    $othersProvider = ServerProvider::factory()->create([
        'user_id' => $other->id,
        'project_id' => null,
    ]);

    /** @var ServerProvider $mine */
    $mine = ServerProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = providerPayload($this->mcpCallTool('list_server_providers'));

    $returnedIds = collect($payload['server_providers'])->pluck('id')->toArray();
    expect($returnedIds)->toEqual([$mine->id])
        ->and($returnedIds)->not->toContain($othersProvider->id);
});

test('list_server_providers omits providers outside the token project scope but keeps global ones', function () {
    /** @var Project $scopedProject */
    $scopedProject = Project::factory()->create();
    $scopedProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);

    /** @var Project $otherProject */
    $otherProject = Project::factory()->create();
    $otherProject->users()->create([
        'user_id' => $this->user->id,
        'role' => UserRole::ADMIN,
    ]);

    /** @var ServerProvider $inScope */
    $inScope = ServerProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $scopedProject->id,
    ]);
    /** @var ServerProvider $outOfScope */
    $outOfScope = ServerProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $otherProject->id,
    ]);
    /** @var ServerProvider $global */
    $global = ServerProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => null,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = providerPayload($this->mcpCallTool('list_server_providers'));

    $returnedIds = collect($payload['server_providers'])->pluck('id')->toArray();
    expect($returnedIds)->toEqualCanonicalizing([$inScope->id, $global->id])
        ->and($returnedIds)->not->toContain($outOfScope->id);
});

test('list_server_providers requires a read-capable token', function () {
    $plainToken = $this->user->createToken('mcp-write-only', ['write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = providerPayload($this->mcpCallTool('list_server_providers'));

    expect($payload['error']['code'])->toBe('forbidden_ability')
        ->and($payload['error']['message'])->toBeString();
});

test('list_server_providers never leaks credentials or secret fields', function () {
    /** @var ServerProvider $provider */
    $provider = ServerProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
        'credentials' => ['api_key' => 'PROVIDER-SECRET-XYZ', 'token' => 'hunter2'],
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_server_providers');

    expect($response->json('result.isError'))->toBeFalse();

    $rendered = (string) $response->json('result.content.0.text');
    $payload = json_decode($rendered, true);

    expect(array_keys($payload['server_providers'][0]))->toEqual([
        'id', 'project_id', 'global', 'name', 'provider', 'created_at', 'updated_at',
    ]);

    expect($rendered)->not->toContain('PROVIDER-SECRET-XYZ')
        ->not->toContain('hunter2')
        ->not->toContain('user_id')
        ->not->toContain('credentials')
        ->not->toContain('editable_data')
        ->not->toContain('provider_data')
        ->not->toContain('access_token');
});

test('tools/list exposes list_server_providers with no input schema', function () {
    $plainToken = $this->user->createToken('mcp-discovery', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $tools = collect($this->mcpListTools()->json('result.tools'));
    $names = $tools->pluck('name')->values()->toArray();

    expect($names)->toContain('list_server_providers');

    $tool = $tools->first(fn ($t) => $t['name'] === 'list_server_providers');
    expect($tool['inputSchema']['properties'])->toBe([])
        ->and($tool['inputSchema'])->not->toHaveKey('required');
});
