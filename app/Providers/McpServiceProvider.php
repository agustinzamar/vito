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
        // laravel/mcp v0.8.x Mcp::web() takes no middleware argument; it returns
        // the POST route with package middleware applied, so auth:sanctum is chained.
        // One endpoint serves all four tools, so any MCP-capable token ability
        // passes the coarse route gate; per-tool checks stay inside the tools.
        Mcp::web('api/mcp', VitoServer::class)
            ->middleware(['auth:sanctum', 'ability:read,write'])
            ->name('api.mcp');

        // laravel/mcp also registers unnamed GET/DELETE 405 stubs next to the
        // POST route. The architecture suite requires every application route
        // to be named and authenticated, so normalize those here too.
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            if ($route->uri() !== 'api/mcp' || $route->getName() !== null) {
                continue;
            }

            $route->name('api.mcp.'.strtolower($route->methods()[0]))
                ->middleware(['auth:sanctum', 'ability:read,write']);
        }
    }
}
