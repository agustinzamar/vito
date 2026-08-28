<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Mcp\Support\ProjectContext;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
     * Advertise the required input so clients can call this tool from
     * discovery alone. Runtime checks in handle() remain authoritative.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The id of the project whose servers should be listed. Optional: when omitted it is inferred from a single project:{id} token scope.')
                ->min(1),
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
                "This token does not have the 'read' ability required for list_servers."
            );
        }

        $projectId = (new ProjectContext)->resolveProjectId($request->get('project_id'), $token);

        if ($projectId === null) {
            return $this->mcpError(
                'validation',
                'The project_id parameter is required and must be a positive integer.'
            );
        }

        $project = $this->authorizedProject($projectId);

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
