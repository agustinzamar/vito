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
                ->description('The id of the project the server belongs to. Optional: when omitted it is inferred from a single project:{id} token scope.')
                ->min(1),
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

        $serverId = $request->get('server_id');

        if (! is_numeric($serverId) || (int) $serverId < 1) {
            return $this->mcpError(
                'validation',
                'The server_id parameter is required and must be a positive integer.'
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

        // Finding the server via the project relationship (rather than a
        // global lookup) keeps a foreign project/server pair from leaking
        // whether the server exists: it simply collapses into not_found.
        $server = $project->servers()->find((int) $request->get('server_id'));

        if ($server === null) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        // Defense-in-depth: re-check the Policy on the resolved model in
        // case relationship scoping alone is ever weakened.
        if (! $user->can('view', $server)) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        return Response::json(['server' => self::mapServer($server)]);
    }
}
