<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class GetServerTool extends Tool
{
    use ResolvesAuthorizedProject;

    protected string $name = 'get_server';

    protected string $title = 'Get Server';

    protected string $description =
        'Inspect a single Vito server by id within a specific project. Requires the project_id and server_id of an accessible server.';

    /**
     * Advertise the required inputs so clients can call this tool from
     * discovery alone. Runtime checks in handle() remain authoritative.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()
                ->description('The id of the project the server belongs to.')
                ->min(1)
                ->required(),
            'server_id' => $schema->integer()
                ->description('The id of the server to inspect.')
                ->min(1)
                ->required(),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response
    {
        foreach (['project_id' => $request->get('project_id'), 'server_id' => $request->get('server_id')] as $param => $value) {
            if (! is_numeric($value) || (int) $value < 1) {
                return $this->mcpError(
                    'validation',
                    sprintf('The %s parameter is required and must be a positive integer.', $param)
                );
            }
        }

        /** @var User $user */
        $user = $request->user();

        /** @var PersonalAccessToken|null $token */
        $token = $user->currentAccessToken();

        if ($token === null || ! $token->can('read')) {
            return $this->mcpError(
                'forbidden_ability',
                "This token does not have the 'read' ability required for get_server."
            );
        }

        $projectId = (int) $request->get('project_id');

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

        // Relationship lookup: a foreign project/server id pair simply finds
        // nothing here, so cross-project probes collapse into `not_found`
        // with zero attribute leakage.
        $server = $project->servers()->find((int) $request->get('server_id'));

        if ($server === null) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        // Defense-in-depth re-check on the resolved model.
        if (! $user->can('view', $server)) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        return Response::json(['server' => self::mapServer($server)]);
    }
}
