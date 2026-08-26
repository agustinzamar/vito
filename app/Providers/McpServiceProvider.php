<?php

namespace App\Providers;

use App\Mcp\VitoServer;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;

class McpServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // laravel/mcp v0.8.x Mcp::web() takes no middleware argument; it returns
        // the POST route with package middleware applied, so auth:sanctum is chained.
        Mcp::web('api/mcp', VitoServer::class)->middleware('auth:sanctum');
    }
}
