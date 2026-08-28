<?php

namespace App\Mcp\Support;

use App\Models\PersonalAccessToken;

/**
 * Resolves the effective project id driving an MCP tool call.
 *
 * This is the single seam where Vito's "default project" convenience lives for
 * native MCP. It is deliberately narrow: it only decides *which* project id
 * applies to a given call. Every downstream gate — token ability, token
 * project scope, Policy, and the project→server relationship lookup — stays in
 * the calling tool, so the convenience can never widen access on its own.
 *
 * Resolution order (highest precedence first):
 *
 *   1. An explicit, positive-integer project_id always wins (caller override).
 *   2. An omitted project_id on a token scoped to exactly one `project:{id}`
 *      is inferred to that id — the safe TS default-project convenience.
 *   3. An omitted project_id on an unscoped or multi-scoped token, or an
 *      explicit non-positive / non-numeric project_id, cannot be resolved:
 *      null is returned and the caller must surface a validation error asking
 *      for project_id.
 *
 * There is deliberately no environment default and no hidden "current project"
 * fallback. Inference relies only on the explicit request and the token's own
 * project scopes, which keeps multi-user HTTP behavior safe and predictable.
 */
class ProjectContext
{
    /**
     * Resolve the project id governing a tool call.
     *
     * @param  mixed  $explicitProjectId  Raw project_id from the request arguments.
     * @return ?int The resolved project id, or null when the caller must ask.
     */
    public function resolveProjectId(mixed $explicitProjectId, PersonalAccessToken $token): ?int
    {
        // Explicit positive integer always wins (precedence 1).
        if (is_numeric($explicitProjectId) && (int) $explicitProjectId >= 1) {
            return (int) $explicitProjectId;
        }

        // An explicit but invalid value cannot be salvaged (precedence 3).
        if ($explicitProjectId !== null) {
            return null;
        }

        // Omitted: infer only from a single project scope (precedence 2).
        $scoped = $token->getProjectIds();

        if (count($scoped) === 1) {
            return $scoped[0];
        }

        // Unscoped or multi-scoped: the caller must ask for project_id (3).
        return null;
    }
}
