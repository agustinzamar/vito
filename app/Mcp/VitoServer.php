<?php

namespace App\Mcp;

use App\Mcp\Tools\GetServerProviderTool;
use App\Mcp\Tools\GetServerTool;
use App\Mcp\Tools\HealthTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\ListServerProvidersTool;
use App\Mcp\Tools\ListServersTool;
use App\Mcp\Tools\RebootServerTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class VitoServer extends Server
{
    protected string $name = 'vito';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Inspect Vito health, projects, servers, and server providers. Reboot a
        server only after explicit confirmation.
    MARKDOWN;

    /** @var array<int, Tool|class-string<Tool>> */
    protected array $tools = [
        HealthTool::class,
        ListProjectsTool::class,
        ListServersTool::class,
        ListServerProvidersTool::class,
        GetServerProviderTool::class,
        GetServerTool::class,
        RebootServerTool::class,
    ];
}
