<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListServersTool extends Tool
{
    use ResolvesAuthorizedProject;

    protected string $name = 'list_servers';

    protected string $title = 'List Servers';

    protected string $description =
        'List the servers belonging to a specific Vito project. Requires the project_id of an accessible project.';

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        $projectId = $request->get('project_id');

        if (! is_numeric($projectId) || (int) $projectId < 1) {
            return $this->mcpError(
                'validation',
                'The project_id parameter is required and must be a positive integer.'
            );
        }

        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        if ($token === null || ! $token->can('read')) {
            return $this->mcpError(
                'forbidden_ability',
                "This token does not have the 'read' ability required for list_servers."
            );
        }

        $project = $this->authorizedProject((int) $projectId);

        if ($project === null) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        if (! $token->hasProjectAccess($project)) {
            return $this->mcpError('forbidden_scope', 'You do not have access to the requested project.');
        }

        if (! $user->can('view', $project)) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        $servers = $project->servers()->get();

        return Response::json([
            'servers' => $servers->map(fn ($server): array => self::mapServer($server))->all(),
        ]);
    }
}
