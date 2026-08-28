<?php

namespace App\Mcp\Support;

use App\Models\SourceControl;
use App\SourceControlProviders\SourceControlProvider;

/**
 * Maps a SourceControl to the minimal, secret-free representation returned by
 * the native `list_source_controls` MCP tool.
 *
 * This is a single-purpose mapper, not a generic source-control framework: it
 * exposes only the fields an agent needs to identify a connection (provider,
 * profile, scope, identity) and stays deliberately blind to credentials. The
 * excluded-by-construction fields are user_id (ownership), access_token
 * (encrypted token), provider_data (encrypted secrets/metadata), editable_data
 * (mutable config), and any password/token material — none of which may reach
 * MCP output. external_identifier is a plain, non-secret handle and is always
 * surfaced; ssh_port is surfaced only when the provider handler advertises it
 * as an editable field, mirroring SourceControlResource.
 */
final class SourceControlOutput
{
    /**
     * @return array<string, mixed>
     */
    public static function toSafeArray(SourceControl $sourceControl): array
    {
        $handler = config('source-control.providers.'.$sourceControl->provider.'.handler');
        $supportsSshPort = is_string($handler)
            && is_a($handler, SourceControlProvider::class, true)
            && in_array('ssh_port', $handler::editableFields(), true);

        $data = [
            'id' => $sourceControl->id,
            'project_id' => $sourceControl->project_id,
            'global' => is_null($sourceControl->project_id),
            'name' => $sourceControl->profile,
            'provider' => $sourceControl->provider,
            'external_identifier' => $sourceControl->external_identifier,
            'created_at' => $sourceControl->created_at->toISOString(),
            'updated_at' => $sourceControl->updated_at->toISOString(),
        ];

        if ($supportsSshPort) {
            $data['ssh_port'] = $sourceControl->provider()->getSshPort();
        }

        return $data;
    }
}
