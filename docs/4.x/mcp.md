# MCP (Model Context Protocol)

## Introduction

VitoDeploy ships with a first-party [MCP](https://modelcontextprotocol.io) server so AI agents and MCP-capable clients can work with your Vito instance over authenticated HTTP: list projects and servers, inspect a single server, and reboot a server after explicit confirmation.

The MCP integration reuses the same credentials, authorization rules (abilities, token project scope, and Policies), and domain actions as Vito's dashboard and REST API. No separate credential type is introduced.

## Endpoint

The server speaks the MCP Streamable HTTP transport on a single endpoint:

```
https://<vito-host>/api/mcp
```

Only `POST` is supported. There is no stdio transport in this release.

## Authentication

MCP requests authenticate with an existing Vito personal access token (the same keys used for the REST API). Generate one under **Settings → API Keys** (see [API Keys](settings/api-keys.md)) and send it as a bearer token:

```
Authorization: Bearer YOUR_API_KEY
```

Requests without a valid token are rejected at the HTTP layer before any tool runs.

### Least-privilege guidance

- Grant only the abilities a client needs: `read` for inventory/inspection clients, `write` only if the client should be able to reboot servers.
- Scope the key to specific projects (`project:{id}`) whenever possible; unscoped keys can see every project the user can access.
- Tool calls are additionally checked against Vito's project/server Policies — a token can never exceed its user's permissions.

## Client configuration

Example configuration for any MCP client that supports the Streamable HTTP transport:

```json
{
  "mcpServers": {
    "vito": {
      "transport": "http",
      "url": "https://<vito-host>/api/mcp",
      "headers": {
        "Authorization": "Bearer YOUR_API_KEY"
      }
    }
  }
}
```

After connecting, run tool discovery (`tools/list`) to confirm the four tools below are available to your token.

## Tools

| Tool | Abilities | Inputs | Description |
|------|-----------|--------|-------------|
| `list_projects` | `read` | none | Projects visible to the token, filtered by token project scope and Policies. |
| `list_servers` | `read` | `project_id` | Servers belonging to the requested project. |
| `get_server` | `read` | `project_id`, `server_id` | One safe server representation (status, IP, OS, …). |
| `reboot_server` | `write` | `project_id`, `server_id`, `confirm` | Reboots the server via the same action the dashboard uses. |

Results deliberately exclude credentials: provider data, public keys, SSH user lists, and token values never appear in tool output or error messages.

## Reboot confirmation

`reboot_server` requires explicit confirmation by passing `"confirm": true`. Without it the call returns a corrective error and nothing happens.

**Operational impact:** rebooting interrupts services on the server while it restarts, and the server reports a disconnected status until it comes back. Confirmation never bypasses authorization: a token without the `write` ability, or without access to the target project, is denied even when `confirm: true`.

## Reverse proxy notes

If Vito runs behind nginx (or similar), the `/api/mcp` route streams responses:

- The app already emits `X-Accel-Buffering: no`; do not force `proxy_buffering on` for this location.
- Forward the `Authorization` header to the upstream.
- Use long read timeouts (≥300s) with `proxy_set_header Connection "";` over HTTP/1.1 so long-lived MCP sessions are not cut off.

```nginx
location /api/mcp {
    proxy_pass http://vito-upstream;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Authorization $http_authorization;
    proxy_read_timeout 300s;
}
```

## Migration status

This first slice is intentionally partial:

- Exactly four tools (`list_projects`, `list_servers`, `get_server`, `reboot_server`). There are no legacy aliases and no generic dispatching tool.
- Streamable HTTP is the only transport; there is no stdio support.
- Broader parity with the REST API (sites, databases, workflows, destructive operations) and other providers such as Hostinger are non-goals for this release.
