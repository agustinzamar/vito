<?php

use App\Actions\Server\RebootServer;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\Feature\MCP\Concerns\SpeaksMcp;

uses(RefreshDatabase::class, SpeaksMcp::class);

/**
 * Consolidated secret-free failure audit (spec R-Secret-free).
 *
 * Every forced failure path must return payloads whose envelope text,
 * message content, and any log output exclude by construction:
 * provider credentials, public-key material, token plaintext, and
 * exception/stack markers.
 */

/**
 * Create a server poisoned with recognizable secret material that must
 * never appear in any MCP output or log line.
 */
function auditPoisonedServer(): Server
{
    return Server::factory()->create([
        'public_key' => 'ssh-ed25519 AAAA0POISONKEY audit@vito',
        'provider_data' => ['api_key' => 'PROVIDER-SECRET-XYZ', 'secret' => 'hunter2'],
    ]);
}

beforeEach(function () {
    $this->secrets = [
        'PROVIDER-SECRET-XYZ',
        'hunter2',
        'provider_data',
        'ssh-ed25519',
        'AAAA0POISONKEY',
        '#0 ',
        'stack trace',
        'Exception:',
    ];

    // Every bearer-token plaintext used in these tests is added to
    // $this->secrets so negative assertions cover credential leakage too.
    $this->registerAuditToken = function (string $plainToken): string {
        $this->secrets[] = $plainToken;

        return $plainToken;
    };

    // Sanctum's RequestGuard caches the resolved user for the whole test
    // process, so switching bearer identity mid-test requires forgetting
    // the cached guards before re-initializing with the new token.
    $this->actAsToken = function (string $plainToken): TestResponse {
        Auth::forgetGuards();

        return $this->mcpInitialize($plainToken);
    };

    $this->assertSecretFree = function (string $label, string $rendered) {
        foreach ($this->secrets as $marker) {
            expect($rendered)->not->toContain($marker, "{$label} leaked [{$marker}]");
        }
    };
});

test('authorization failures across all four tool families are secret free', function () {
    /** @var Server $server */
    $server = auditPoisonedServer();

    // Read-only token → forbidden_ability on write-gated reboot_server.
    $readOnly = ($this->registerAuditToken)($this->user->createToken('audit-ro', ['read'])->plainTextToken);
    $this->mcpInitialize($readOnly);

    $rebootPayload = (string) $this->mcpCallTool('reboot_server', [
        'project_id' => $this->user->current_project_id,
        'server_id' => $server->id,
        'confirm' => true,
    ])->json('result.content.0.text');

    ($this->assertSecretFree)('forbidden_ability/reboot_server', $rebootPayload);
    expect(json_decode($rebootPayload, true)['error']['code'])->toBe('forbidden_ability')
        ->and(json_decode($rebootPayload, true)['error']['message'])->not->toContain($server->name);

    // Token scoped elsewhere → forbidden_scope with zero resource leakage.
    $scopedToken = ($this->registerAuditToken)($this->user->createToken('audit-scoped', ['read', 'project:999999'])->plainTextToken);
    ($this->actAsToken)($scopedToken);

    $scopePayload = (string) $this->mcpCallTool('list_servers', ['project_id' => $this->user->current_project_id])
        ->json('result.content.0.text');

    ($this->assertSecretFree)('forbidden_scope/list_servers', $scopePayload);
    expect($scopePayload)->not->toContain((string) $server->ip)
        ->and($scopePayload)->not->toContain($server->name)
        ->and((string) json_decode($scopePayload, true)['error']['code'] ?? json_decode($scopePayload, true)['error']['code'])
        ->toBe('forbidden_scope');
});

test('validation failures name only the offending parameter and stay secret free', function () {
    auditPoisonedServer();

    $plainToken = ($this->registerAuditToken)($this->user->createToken('audit-validation', ['read'])->plainTextToken);
    ($this->actAsToken)($plainToken);

    $payload = (string) $this->mcpCallTool('get_server', ['project_id' => 1])->json('result.content.0.text');

    $decoded = json_decode($payload, true);

    ($this->assertSecretFree)('validation/get_server', $payload);
    expect($decoded['error']['code'])->toBe('validation')
        ->and($decoded['error']['message'])->toContain('server_id');
});

test('action exceptions map to action_failed without leaking exception details', function () {
    /** @var Server $server */
    $server = auditPoisonedServer();

    /** @var MockInterface|RebootServer $explosive */
    $explosive = Mockery::mock(RebootServer::class);
    $explosive->shouldReceive('reboot')->once()->andThrow(
        new RuntimeException('upstream blew up PROVIDER-SECRET-XYZ ssh-ed25519 AAAA0POISONKEY at StackTrace.php:42')
    );
    $this->app->instance(RebootServer::class, $explosive);

    $plainToken = ($this->registerAuditToken)($this->user->createToken('audit-action', ['read', 'write'])->plainTextToken);
    ($this->actAsToken)($plainToken);

    $payload = (string) $this->mcpCallTool('reboot_server', [
        'project_id' => $this->user->current_project_id,
        'server_id' => $server->id,
        'confirm' => true,
    ])->json('result.content.0.text');

    $decoded = json_decode($payload, true);

    ($this->assertSecretFree)('action_failed/reboot_server', $payload);
    expect($decoded['error']['code'])->toBe('action_failed')
        ->and($decoded['error']['message'])->toBe('The reboot operation failed.');
});

test('log output during failure paths is limited to code, tool, and user_id context', function () {
    /** @var Server $server */
    $server = auditPoisonedServer();

    $entries = [];
    Log::listen(function (MessageLogged $event) use (&$entries) {
        $entries[] = $event;
    });

    $plainToken = ($this->registerAuditToken)($this->user->createToken('audit-log', ['read'])->plainTextToken);
    ($this->actAsToken)($plainToken);

    // Exercise denial classes: not_found and validation.
    $this->mcpCallTool('get_server', ['project_id' => 999999, 'server_id' => 1]); // not_found
    $this->mcpCallTool('list_servers', ['project_id' => 'abc']); // validation

    $scopedToken = ($this->registerAuditToken)($this->user->createToken('audit-log-scoped', ['read', 'project:888888'])->plainTextToken);
    ($this->actAsToken)($scopedToken);
    $this->mcpCallTool('list_servers', ['project_id' => $this->user->current_project_id]); // forbidden_scope

    $writeOnly = ($this->registerAuditToken)($this->user->createToken('audit-log-wo', ['write'])->plainTextToken);
    ($this->actAsToken)($writeOnly);
    $this->mcpCallTool('list_projects'); // forbidden_ability

    foreach ($entries as $entry) {
        // Design §5 logging rule: at most {code, tool, user_id} — nothing else,
        // and never token material or provider credentials.
        expect(array_diff(array_keys((array) $entry->context), ['code', 'tool', 'user_id']))->toBeEmpty();

        $rendered = $entry->level.'|'.$entry->message.'|'.json_encode($entry->context);
        foreach ($this->secrets as $marker) {
            expect($rendered)->not->toContain($marker, 'log entry leaked ['.$marker.']');
        }
    }

    // Current implementation logs nothing during MCP handling — the strictest
    // compliant form of "at most {code, tool, user_id}".
    expect(count($entries))->toBe(0);
});
