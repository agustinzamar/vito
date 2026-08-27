<?php

namespace App\Providers;

use App\Mcp\VitoServer;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class McpServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Tools enforce operation-specific authorization.
        Mcp::web('api/mcp', VitoServer::class)
            ->middleware(['auth:sanctum', 'ability:read,write'])
            ->name('api.mcp');

        // Name and protect laravel/mcp's GET/DELETE routes.
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== 'api/mcp' || $route->getName() !== null) {
                continue;
            }

            $route->name('api.mcp.'.strtolower($route->methods()[0]))
                ->middleware(['auth:sanctum', 'ability:read,write']);
        }
    }
}
