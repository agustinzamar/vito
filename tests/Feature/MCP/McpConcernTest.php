<?php

use App\Enums\ServerStatus;
use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Response;

uses(RefreshDatabase::class);

/**
 * Expose the concern's helpers for direct unit-style verification.
 */
function mcpConcernInstance(): object
{
    return new class
    {
        use ResolvesAuthorizedProject;

        public function exposedError(string $code, string $message): Response
        {
            return $this->mcpError($code, $message);
        }
    };
}

test('authorizedProject resolves an existing project and null for a missing one', function () {
    $helper = mcpConcernInstance();

    /** @var Project $project */
    $project = Project::factory()->create();

    expect($helper->authorizedProject($project->id))->toBeInstanceOf(Project::class)
        ->and($helper->authorizedProject($project->id)->id)->toBe($project->id)
        ->and($helper->authorizedProject(999999))->toBeNull();
});

test('assertReadAccess denies a token without the read ability', function () {
    $helper = mcpConcernInstance();

    /** @var User $user */
    $user = User::factory()->create();
    $user->ensureHasDefaultProject();

    /** @var PersonalAccessToken $token */
    $token = $user->createToken('write-only', ['write'])->accessToken;

    expect($helper->assertReadAccess($user, $token, $user->currentProject))->toBeFalse();
});

test('assertReadAccess denies a token scoped to another project', function () {
    $helper = mcpConcernInstance();

    /** @var User $user */
    $user = User::factory()->create();
    $user->ensureHasDefaultProject();

    /** @var Project $otherProject */
    $otherProject = Project::factory()->create();

    /** @var PersonalAccessToken $token */
    $token = $user->createToken('scoped', ['read', 'project:'.$user->current_project_id])->accessToken;

    expect($helper->assertReadAccess($user, $token, $otherProject))->toBeFalse()
        ->and($helper->assertReadAccess($user, $token, $user->currentProject))->toBeTrue();
});

test('assertReadAccess denies a user without the project view policy', function () {
    $helper = mcpConcernInstance();

    /** @var User $user */
    $user = User::factory()->create();

    // Project where this user holds no membership role at all.
    /** @var User $owner */
    $owner = User::factory()->create();
    $owner->ensureHasDefaultProject();

    /** @var PersonalAccessToken $token */
    $token = $user->createToken('no-role', ['read'])->accessToken;

    expect($helper->assertReadAccess($user, $token, $owner->currentProject))->toBeFalse();
});

test('assertWriteAccess requires the write ability on top of scope and policy', function () {
    $helper = mcpConcernInstance();

    /** @var User $member */
    $member = User::factory()->create();
    $member->ensureHasDefaultProject();

    /** @var PersonalAccessToken $readToken */
    $readToken = $member->createToken('read-only', ['read'])->accessToken;
    /** @var PersonalAccessToken $writeToken */
    $writeToken = $member->createToken('write-capable', ['read', 'write'])->accessToken;

    expect($helper->assertWriteAccess($member, $readToken, $member->currentProject))->toBeFalse()
        ->and($helper->assertWriteAccess($member, $writeToken, $member->currentProject))->toBeTrue();
});

test('server mapper returns exactly the safe field set for a fully populated server', function () {
    /** @var Server $server */
    $server = Server::factory()->create([
        'provider_data' => ['key' => 'secret-provider-credential'],
        'public_key' => 'ssh-rsa AAAA...leaked-key-material',
        'ssh_user' => 'vito',
        'authentication' => ['user' => 'vito', 'pass' => 'super-secret'],
        'ip' => '203.0.113.10',
        'status' => ServerStatus::READY,
    ]);

    $mapped = ResolvesAuthorizedProject::mapServer($server);

    expect(array_keys($mapped))->toEqual([
        'id', 'project_id', 'name', 'ip', 'local_ip', 'port', 'os', 'status',
        'auto_update', 'auto_update_schedule', 'last_update_check', 'created_at', 'updated_at',
    ])
        ->and(json_encode($mapped))->not->toContain('secret-provider-credential')
        ->and(json_encode($mapped))->not->toContain('AAAA')
        ->and(json_encode($mapped))->not->toContain('super-secret')
        ->and(json_encode($mapped))->not->toContain('vito');
});

test('project mapper returns exactly the safe field set including caller role', function () {
    /** @var User $user */
    $user = User::factory()->create();
    $user->ensureHasDefaultProject();

    $mapped = ResolvesAuthorizedProject::mapProject($user->currentProject, $user);

    expect(array_keys($mapped))->toEqual(['id', 'name', 'role', 'created_at', 'updated_at'])
        ->and($mapped['id'])->toBe($user->currentProject->id)
        ->and($mapped['role'])->toBeString();
});

test('mcpError builds the stable error envelope as an error response', function () {
    $response = mcpConcernInstance()->exposedError('not_found', 'The requested resource was not found.');

    $payload = json_decode((string) $response->content(), true);

    expect($response->isError())->toBeTrue()
        ->and($payload)->toEqual([
            'error' => [
                'code' => 'not_found',
                'message' => 'The requested resource was not found.',
            ],
        ]);
});
