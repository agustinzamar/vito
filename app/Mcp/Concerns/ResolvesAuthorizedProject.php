<?php

namespace App\Mcp\Concerns;

use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Laravel\Mcp\Response;

/**
 * Shared authorization and safe-output helpers for Vito MCP tools.
 *
 * All access helpers return booleans or nullable models only — they never
 * throw with contextual data, so error text cannot leak resource attributes.
 *
 * The server mapper deliberately does NOT reuse ServerResource: it exposes
 * provider_data, public_key and ssh_users, which must never reach MCP output.
 */
trait ResolvesAuthorizedProject
{
    /**
     * Resolve a project by id; null when it does not exist. The caller maps
     * null (and any downstream authorization failure) to the stable
     * `not_found` error shape.
     */
    public function authorizedProject(int $projectId): ?Project
    {
        /** @var Project|null */
        return Project::query()->find($projectId);
    }

    /**
     * Read capability = token ability + token project scope + project Policy.
     */
    public function assertReadAccess(User $user, PersonalAccessToken $token, Project $project): bool
    {
        return $token->can('read')
            && $token->hasProjectAccess($project)
            && $user->can('view', $project);
    }

    /**
     * Write capability = write ability + token project scope + write Policy.
     */
    public function assertWriteAccess(User $user, PersonalAccessToken $token, Project $project): bool
    {
        return $token->can('write')
            && $token->hasProjectAccess($project)
            && $user->can('update', $project);
    }

    /**
     * Safe project representation (subset of ProjectResource; excludes the
     * users collection that would leak other members' identity data).
     *
     * @return array<string, mixed>
     */
    public static function mapProject(Project $project, User $user): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'role' => $project->role($user)->value,
            'created_at' => $project->created_at->toISOString(),
            'updated_at' => $project->updated_at->toISOString(),
        ];
    }

    /**
     * Safe server representation (explicit subset of ServerResource).
     *
     * Excluded by construction: provider_data (provider credentials),
     * public_key (SSH key material), ssh_user/ssh_users (internal account
     * enumeration), services/features/warnings/updates/kernel_updates
     * (verbose internals), progress/progress_step/status_color/user_id/
     * provider_id (UI/internal noise).
     *
     * @return array<string, mixed>
     */
    public static function mapServer(Server $server): array
    {
        return [
            'id' => $server->id,
            'project_id' => $server->project_id,
            'name' => $server->name,
            'ip' => $server->ip,
            'local_ip' => $server->local_ip,
            'port' => $server->port,
            'os' => $server->os->getText(),
            'status' => $server->status->getText(),
            'auto_update' => $server->auto_update,
            'auto_update_schedule' => $server->auto_update_schedule,
            'last_update_check' => $server->last_update_check?->toISOString(),
            'created_at' => $server->created_at->toISOString(),
            'updated_at' => $server->updated_at->toISOString(),
        ];
    }

    /**
     * Stable failure envelope: {error:{code,message}} with fixed message
     * text only — never interpolated exception bodies, model attributes,
     * or token material.
     */
    protected function mcpError(string $code, string $message): Response
    {
        return Response::error((string) json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ]));
    }
}
