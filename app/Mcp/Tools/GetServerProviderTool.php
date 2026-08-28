<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Mcp\Support\ServerProviderOutput;
use App\Models\PersonalAccessToken;
use App\Models\ServerProvider;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Native MCP capability: inspect a single server provider by id.
 *
 * Authorization mirrors the canonical GET /api/server-providers/{serverProvider}
 * endpoint and its ServerProviderPolicy: the caller must hold the `read` ability,
 * the provider must belong to the caller, and the caller's token must be allowed
 * to reach the provider's project (a global provider, project_id NULL, is
 * reachable from any in-scope token). Unknown ids and providers owned by other
 * users collapse to one stable `not_found` so existence never leaks; a provider
 * the caller owns but whose project the token cannot reach yields
 * `forbidden_scope`.
 */
class GetServerProviderTool extends Tool
{
    use ResolvesAuthorizedProject;

    protected string $name = 'get_server_provider';

    protected string $title = 'Get Server Provider';

    protected string $description =
        'Inspect a single Vito server provider by id. Requires the server_provider_id of an accessible provider.';

    /**
     * Advertise the required inputs so clients can call this tool from discovery
     * alone. Runtime checks in handle() remain authoritative.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'server_provider_id' => $schema->integer()
                ->description('The id of the server provider to inspect.')
                ->min(1)
                ->required(),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        if ($token === null || ! $token->can('read')) {
            return $this->mcpError(
                'forbidden_ability',
                "This token does not have the 'read' ability required for get_server_provider."
            );
        }

        $providerId = $request->get('server_provider_id');

        if (! is_numeric($providerId) || (int) $providerId < 1) {
            return $this->mcpError(
                'validation',
                'The server_provider_id parameter is required and must be a positive integer.'
            );
        }

        // Raw lookup by id: existence is not yet proven to the caller. A missing
        // id resolves to `not_found` below, so a probe cannot learn which ids
        // exist in the table.
        /** @var ServerProvider|null $provider */
        $provider = ServerProvider::query()->find((int) $providerId);

        if ($provider === null) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        // Ownership is checked before token scope so a foreign-owned provider can
        // never surface as `forbidden_scope` (which would confirm its existence).
        if ($provider->user_id !== $user->id) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        // Token project scope: a provider the caller owns but cannot reach through
        // the token's project scope is a scope denial, not a missing resource.
        if (! $user->tokenAllowsProject($provider->project_id)) {
            return $this->mcpError('forbidden_scope', 'You do not have access to the requested server provider.');
        }

        // ServerProviderPolicy::view remains the authoritative gate over ownership
        // and token scope; any future tightening there is honored automatically.
        if (! $user->can('view', $provider)) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        return Response::json([
            'server_provider' => ServerProviderOutput::toSafeArray($provider),
        ]);
    }
}
