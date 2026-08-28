<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Mcp\Support\SourceControlOutput;
use App\Models\PersonalAccessToken;
use App\Models\SourceControl;
use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * Native MCP capability: list the source controls reachable by the caller.
 *
 * No input parameters. The caller's reachable set is the existing
 * `SourceControl::getByTokenProjects($user)` seam: every source control owned
 * by the user, plus the user's global source controls (project_id IS NULL) and
 * any scoped to a project the caller's token may reach. Nothing is returned for
 * a source control the token cannot reach, so scope and ownership stay
 * authoritative. Credentials are never serialized: SourceControlOutput maps
 * only non-secret identity fields.
 */
class ListSourceControlsTool extends Tool
{
    use ResolvesAuthorizedProject;

    protected string $name = 'list_source_controls';

    protected string $title = 'List Source Controls';

    protected string $description =
        'List the source controls visible to the authenticated token, including global source controls and those scoped to a project the token can reach.';

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
                "This token does not have the 'read' ability required for list_source_controls."
            );
        }

        // The seam already enforces user ownership and token project scope; a
        // global source control (project_id NULL) is reachable from any in-scope token.
        $sourceControls = SourceControl::getByTokenProjects($user)->get();

        return Response::json([
            'source_controls' => $sourceControls
                ->map(fn (SourceControl $sourceControl): array => SourceControlOutput::toSafeArray($sourceControl))
                ->all(),
        ]);
    }
}
