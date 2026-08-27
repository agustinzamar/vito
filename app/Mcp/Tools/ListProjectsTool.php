<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class ListProjectsTool extends Tool
{
    use ResolvesAuthorizedProject;

    protected string $name = 'list_projects';

    protected string $title = 'List Projects';

    protected string $description =
        'List the Vito projects visible to the authenticated token, including your role in each project.';

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
                "This token does not have the 'read' ability required for list_projects."
            );
        }

        // Scope and Policy failures silently omit a project: nothing is
        // returned for an unauthorized resource.
        /** @var Collection<int, Project> $allProjects */
        $allProjects = $user->projects()->get();

        $projects = $allProjects
            ->filter(fn (Project $project): bool => $this->assertReadAccess($user, $token, $project))
            ->values();

        return Response::json([
            'projects' => $projects->map(fn (Project $project): array => self::mapProject($project, $user))->all(),
        ]);
    }
}
