<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Mcp\Support\ServerProviderOutput;
use App\Models\PersonalAccessToken;
use App\Models\ServerProvider;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Native MCP capability: list the server providers reachable by the caller.
 *
 * No input parameters. The caller's reachable set is the existing
 * `ServerProvider::getByTokenProjects($user)` seam: every provider owned by the
 * user, plus the user's global providers (project_id IS NULL) and any providers
 * scoped to a project the caller's token may reach. Nothing is ever returned for
 * a provider the token cannot reach, so scope and ownership stay authoritative.
 */
class ListServerProvidersTool extends Tool
{
    use ResolvesAuthorizedProject;

    protected string $name = 'list_server_providers';

    protected string $title = 'List Server Providers';

    protected string $description =
        'List the server providers visible to the authenticated token, including global providers and those scoped to a project the token can reach.';

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
                "This token does not have the 'read' ability required for list_server_providers."
            );
        }

        // The seam already enforces user ownership and token project scope; a
        // global provider (project_id NULL) is reachable from any in-scope token.
        $providers = ServerProvider::getByTokenProjects($user)->get();

        return Response::json([
            'server_providers' => $providers
                ->map(fn (ServerProvider $provider): array => ServerProviderOutput::toSafeArray($provider))
                ->all(),
        ]);
    }
}
