<?php

namespace App\Mcp\Support;

use App\Models\ServerProvider;

/**
 * Maps a ServerProvider to the minimal, secret-free representation returned by
 * the native `list_server_providers` MCP tool.
 *
 * This is intentionally a single-purpose mapper, not a generic provider
 * framework: it exposes only the fields an agent needs to identify a provider
 * and stays deliberately blind to credentials. The excluded-by-construction
 * fields are user_id (ownership), credentials (encrypted secrets), editable_data
 * (mutable config), access tokens, and the runtime provider_data/exception
 * surfaces — none of which may reach MCP output.
 */
final class ServerProviderOutput
{
    /**
     * @return array<string, mixed>
     */
    public static function toSafeArray(ServerProvider $provider): array
    {
        return [
            'id' => $provider->id,
            'project_id' => $provider->project_id,
            'global' => is_null($provider->project_id),
            'name' => $provider->profile,
            'provider' => $provider->provider,
            'created_at' => $provider->created_at->toISOString(),
            'updated_at' => $provider->updated_at->toISOString(),
        ];
    }
}
