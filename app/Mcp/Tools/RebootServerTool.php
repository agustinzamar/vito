<?php

namespace App\Mcp\Tools;

use App\Actions\Server\RebootServer;
use App\Mcp\Concerns\ResolvesAuthorizedProject;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Throwable;

class RebootServerTool extends Tool
{
    use ResolvesAuthorizedProject;

    protected string $name = 'reboot_server';

    protected string $title = 'Reboot Server';

    protected string $description =
        'Reboot a Vito server over SSH. Interrupts services on the server while it restarts. '
        .'Requires project_id, server_id, and explicit confirmation by passing confirm: true.';

    /**
     * Advertise the required inputs, including the explicit boolean
     * confirmation, so clients can call this tool from discovery alone.
     * Runtime checks in handle() remain authoritative.
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
                ->description('The id of the server to reboot.')
                ->min(1)
                ->required(),
            'confirm' => $schema->boolean()
                ->description('Must be true to confirm the reboot. Interrupts services on the server while it restarts.')
                ->required(),
        ];
    }

    /**
     * Handle the tool request.
     *
     * Authorization order is deliberate: ability → project existence → token
     * scope → Policy → server resolution. Confirmation is checked only after
     * every authorization step passed — confirming never substitutes for
     * authorization.
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

        if ($token === null || ! $token->can('write')) {
            return $this->mcpError(
                'forbidden_ability',
                "This token does not have the 'write' ability required for reboot_server."
            );
        }

        $project = $this->authorizedProject((int) $request->get('project_id'));

        if ($project === null) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        if (! $token->hasProjectAccess($project)) {
            return $this->mcpError('forbidden_scope', 'You do not have access to the requested project.');
        }

        if (! $user->can('update', $project)) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        // Relationship lookup collapses cross-project probes into not_found.
        $server = $project->servers()->find((int) $request->get('server_id'));

        if ($server === null) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        // Defense-in-depth re-check on the resolved model (ServerPolicy@update).
        if (! $user->can('update', $server)) {
            return $this->mcpError('not_found', 'The requested resource was not found.');
        }

        if ($request->get('confirm') !== true) {
            return $this->mcpError(
                'confirmation_required',
                'This operation requires explicit confirmation. Pass confirm: true to reboot the server.'
            );
        }

        try {
            $rebooted = app(RebootServer::class)->reboot($server);
        } catch (Throwable) {
            return $this->mcpError('action_failed', 'The reboot operation failed.');
        }

        return Response::json(['server' => self::mapServer($rebooted)]);
    }
}
