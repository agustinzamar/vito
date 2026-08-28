<?php

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\SourceControl;
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
function sourceControlPayload(TestResponse $response): array
{
    return (array) json_decode((string) $response->json('result.content.0.text'), true);
}

test('list_source_controls returns the caller global and in-scope source controls with safe fields only', function () {
    /** @var SourceControl $global */
    $global = SourceControl::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => null,
    ]);
    /** @var SourceControl $scoped */
    $scoped = SourceControl::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_source_controls');

    expect($response->json('result.isError'))->toBeFalse();

    $payload = sourceControlPayload($response);

    $returnedIds = collect($payload['source_controls'])->pluck('id')->toArray();
    expect($returnedIds)->toEqualCanonicalizing([$global->id, $scoped->id]);

    foreach ($payload['source_controls'] as $sourceControl) {
        expect(array_keys($sourceControl))->toEqual([
            'id', 'project_id', 'global', 'name', 'provider', 'external_identifier', 'created_at', 'updated_at',
        ]);
    }

    $byId = collect($payload['source_controls'])->keyBy('id');
    expect($byId[$global->id]['global'])->toBeTrue()
        ->and($byId[$global->id]['project_id'])->toBeNull()
        ->and($byId[$scoped->id]['global'])->toBeFalse()
        ->and($byId[$scoped->id]['project_id'])->toBe($this->user->current_project_id);
});

test('list_source_controls omits source controls owned by other users', function () {
    /** @var User $other */
    $other = User::factory()->create();
    $other->ensureHasDefaultProject();

    /** @var SourceControl $others */
    $others = SourceControl::factory()->create([
        'user_id' => $other->id,
        'project_id' => null,
    ]);

    /** @var SourceControl $mine */
    $mine = SourceControl::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = sourceControlPayload($this->mcpCallTool('list_source_controls'));

    $returnedIds = collect($payload['source_controls'])->pluck('id')->toArray();
    expect($returnedIds)->toEqual([$mine->id])
        ->and($returnedIds)->not->toContain($others->id);
});

test('list_source_controls omits source controls outside the token project scope but keeps global ones', function () {
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

    /** @var SourceControl $inScope */
    $inScope = SourceControl::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $scopedProject->id,
    ]);
    /** @var SourceControl $outOfScope */
    $outOfScope = SourceControl::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $otherProject->id,
    ]);
    /** @var SourceControl $global */
    $global = SourceControl::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => null,
    ]);

    $plainToken = $this->user->createToken('mcp-scoped', ['read', 'project:'.$scopedProject->id])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = sourceControlPayload($this->mcpCallTool('list_source_controls'));

    $returnedIds = collect($payload['source_controls'])->pluck('id')->toArray();
    expect($returnedIds)->toEqualCanonicalizing([$inScope->id, $global->id])
        ->and($returnedIds)->not->toContain($outOfScope->id);
});

test('list_source_controls requires a read-capable token', function () {
    $plainToken = $this->user->createToken('mcp-write-only', ['write'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = sourceControlPayload($this->mcpCallTool('list_source_controls'));

    expect($payload['error']['code'])->toBe('forbidden_ability')
        ->and($payload['error']['message'])->toBeString();
});

test('list_source_controls surfaces ssh_port only for providers that support it', function () {
    /** @var SourceControl $gitlab */
    $gitlab = SourceControl::factory()->gitlab()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
        'provider_data' => ['ssh_port' => 2222],
    ]);
    /** @var SourceControl $github */
    $github = SourceControl::factory()->github()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = sourceControlPayload($this->mcpCallTool('list_source_controls'));
    $byId = collect($payload['source_controls'])->keyBy('id');

    // ssh_port is safe to expose: it is the configured port number, not a
    // credential, and is only present for providers that support it.
    expect($byId[$gitlab->id])->toHaveKey('ssh_port')
        ->and($byId[$gitlab->id]['ssh_port'])->toBe(2222);

    expect($byId[$github->id])->not->toHaveKey('ssh_port');
});

test('list_source_controls exposes external_identifier for github-app connections', function () {
    /** @var SourceControl $app */
    $app = SourceControl::factory()->githubApp()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $payload = sourceControlPayload($this->mcpCallTool('list_source_controls'));

    $control = collect($payload['source_controls'])->firstWhere('id', $app->id);

    expect($control)->not->toBeNull()
        ->and($control['external_identifier'])->toBe($app->external_identifier)
        ->and($control)->toHaveKey('external_identifier')
        ->and($control)->not->toHaveKey('ssh_port')
        ->and($control)->not->toHaveKey('github_app');
});

test('list_source_controls never leaks credentials or secret fields', function () {
    /** @var SourceControl $control */
    $control = SourceControl::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
        'access_token' => 'SC-ACCESS-TOKEN-XYZ',
        'provider_data' => ['token' => 'hunter2', 'secret' => 'PROVIDER-SECRET'],
    ]);

    $plainToken = $this->user->createToken('mcp-read', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $response = $this->mcpCallTool('list_source_controls');

    expect($response->json('result.isError'))->toBeFalse();

    $rendered = (string) $response->json('result.content.0.text');
    $payload = json_decode($rendered, true);

    expect(array_keys($payload['source_controls'][0]))->toEqual([
        'id', 'project_id', 'global', 'name', 'provider', 'external_identifier', 'created_at', 'updated_at',
    ]);

    expect($rendered)->not->toContain('SC-ACCESS-TOKEN-XYZ')
        ->not->toContain('hunter2')
        ->not->toContain('PROVIDER-SECRET')
        ->not->toContain('user_id')
        ->not->toContain('access_token')
        ->not->toContain('provider_data')
        ->not->toContain('editable_data')
        ->not->toContain('github_app')
        ->not->toContain('password');
});

test('tools/list exposes list_source_controls with no input schema', function () {
    $plainToken = $this->user->createToken('mcp-discovery', ['read'])->plainTextToken;
    $this->mcpInitialize($plainToken);

    $tools = collect($this->mcpListTools()->json('result.tools'));
    $names = $tools->pluck('name')->values()->toArray();

    expect($names)->toContain('list_source_controls');

    $tool = $tools->first(fn ($t) => $t['name'] === 'list_source_controls');
    expect($tool['inputSchema']['properties'])->toBe([])
        ->and($tool['inputSchema'])->not->toHaveKey('required');
});
