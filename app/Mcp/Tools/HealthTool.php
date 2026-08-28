<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class HealthTool extends Tool
{
    protected string $name = 'health_check';

    protected string $title = 'Health Check';

    protected string $description =
        'Lightweight, project-free liveness probe. Returns whether the MCP server is responding and the running Vito version. '
        .'Requires only a valid token (read or write) and no inputs; it performs no project, server, or ability lookups.';

    /**
     * Handle the tool request.
     *
     * This is an authenticated but project-free, ability-free probe. The MCP
     * transport already requires a valid personal access token with the read or
     * write ability, so no further authorization gate is applied here — any
     * valid token may confirm liveness and read the deployed version.
     */
    public function handle(Request $request): Response
    {
        return Response::json([
            'success' => true,
            'version' => config('app.version'),
        ]);
    }
}
