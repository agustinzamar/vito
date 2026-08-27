<?php

namespace App\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;

class VitoServer extends Server
{
    protected string $name = 'vito';

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        Manage Vito projects and servers: list projects, list servers within a
        project, inspect a single server, and reboot a server after explicit
        confirmation.
    MARKDOWN;

    /**
     * Tools are added in a later slice; this skeleton establishes the
     * authenticated transport and registration seam only.
     *
     * @var array<int, Tool|class-string<Tool>>
     */
    protected array $tools = [];
}
