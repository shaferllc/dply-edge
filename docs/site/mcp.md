---
title: "MCP server"
description: "Connect Claude, Cursor, and other MCP clients to dply so an AI assistant can list and read the apps in your organization."
---

dply runs a [Model Context Protocol](https://modelcontextprotocol.io) (MCP) server. MCP lets an AI assistant such as Claude Code, Claude Desktop, or Cursor call tools on your behalf. With the dply server connected, the assistant can look up the apps in your organization and their status without you copying details from the dashboard.

The server is read-only. It can't deploy, change settings, or read environment variable values. To act on an app, use the [CLI](/docs/cli) or the [HTTP API](/docs/api).

## Endpoint and authentication

| | |
| --- | --- |
| URL | `https://edge.dply.io/mcp` |
| Transport | Streamable HTTP |
| Authentication | `Authorization: Bearer dply_…` |
| Rate limit | 60 requests per minute per token, shared with the HTTP API |

The server authenticates with a regular dply API token, the same kind the HTTP API uses. The token belongs to one organization, so the assistant sees only that organization's apps. The server also checks your membership on every request: if you leave the organization, the token stops working.

## Create a token

Organization owners and admins create API tokens in the dashboard:

1. Open **Profile → API keys**.
2. Enter a name, for example `Claude`.
3. Under **AI assistants (MCP)**, select **List and read sites** (`sites.read`). That's the only ability the MCP server uses.
4. Create the token and copy it. dply shows the token only once.

Members and deployers can't open **API keys**. Sign in with the [CLI](/docs/cli) instead (`dply login` grants `sites.read` to every role) and use the token it saves in `~/.dply/config.json`, or ask an admin to create one for you.

A dedicated token with only `sites.read` limits the damage if it leaks. The CLI token carries more abilities than MCP needs.

> [!WARNING]
> Treat the token like a password. MCP clients store it in plain-text config files. Don't commit those files. Revoke the token under **Profile → API keys** if it's exposed.

## Connect a client

In each example, replace `dply_YOUR_TOKEN` with your token.

### Claude Code

```bash
claude mcp add --transport http dply https://edge.dply.io/mcp \
  --header "Authorization: Bearer dply_YOUR_TOKEN"
```

Run `claude mcp list` to confirm that the server connects. Add `--scope user` to make it available in every project.

### Cursor

Add the server to `~/.cursor/mcp.json`, or to `.cursor/mcp.json` in a project:

```json
{
  "mcpServers": {
    "dply": {
      "url": "https://edge.dply.io/mcp",
      "headers": {
        "Authorization": "Bearer dply_YOUR_TOKEN"
      }
    }
  }
}
```

### Claude Desktop

Claude Desktop's config file doesn't set request headers on remote servers, so use the `mcp-remote` bridge. It requires Node.js. Edit `claude_desktop_config.json` (**Settings → Developer → Edit Config**):

```json
{
  "mcpServers": {
    "dply": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://edge.dply.io/mcp",
        "--header",
        "Authorization:${DPLY_AUTH}"
      ],
      "env": {
        "DPLY_AUTH": "Bearer dply_YOUR_TOKEN"
      }
    }
  }
}
```

Restart Claude Desktop after you save the file.

### Other clients

Any client that supports streamable HTTP and custom headers can connect. Point it at `https://edge.dply.io/mcp` and send the `Authorization` header.

## Tools

There are two tools. Both need `sites.read`; without it a tool returns `This API token lacks the required "sites.read" ability.` Tools return JSON with a top-level `data` key.

### `list_sites`

Lists the apps in the organization. Preview deployments aren't included. It takes no input.

```json
{
  "data": [
    {
      "id": "01j9z4k7m2q8r5t6v3w0x1y2z3",
      "slug": "marketing",
      "name": "marketing",
      "runtime_mode": "static",
      "status": "edge_active",
      "live_url": "https://marketing-a1b2c3.on-dply.live",
      "last_deploy_at": "2026-09-24T09:15:02+00:00",
      "created_at": "2026-09-20T14:02:11+00:00"
    }
  ]
}
```

`runtime_mode` is `static`, `hybrid`, `ssr` or `container`. `live_url` is `null` until the first deploy goes live.

### `get_site`

Returns one app.

| Input | Type | Required | Description |
| --- | --- | --- | --- |
| `site_id` | string | Yes | The app's id or slug. |

It returns the `list_sites` fields plus:

| Field | Description |
| --- | --- |
| `repository` | The connected repository, for example `acme/marketing`. |
| `branch` | The production branch. |
| `custom_domains` | Each attached domain: `hostname`, `dns_status` (`pending`, `ready`, `failed`) and `ssl_status`. |

If the app doesn't exist, belongs to another organization, or you can't view it, the tool returns `Site "<id>" was not found in this organization.`

There are no tools for deployments, logs, environment variables or domain changes. Use the [CLI](/docs/cli) or the [HTTP API](/docs/api/reference) for those.

## Resources

Resources let a client load context without calling a tool. They need `sites.read`, like the tools.

| URI | Description |
| --- | --- |
| `dply://sites` | The organization's apps, with the same fields as `list_sites`. |
| `dply://sites/{site_id}` | One app, with the same fields as `get_site`. `site_id` accepts an id or slug. |

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| `401 Unauthorized` or `Token expired or invalid` | The header is missing or malformed, or the token was revoked or has expired. |
| `403 Forbidden` | You're no longer a member of the token's organization, or the request came from an IP address outside the token's allow list. |
| `lacks the required "…" ability` | Create a token that includes `sites.read`. |
| `429 Too Many Requests` | The token went over 60 requests per minute, counting HTTP API calls. Wait a minute. |
| An app is missing | The token belongs to a different organization. Create a token in the organization that owns the app. |

## Related

- [HTTP API](/docs/api)
- [CLI](/docs/cli)
- [Roles & permissions](/docs/roles-and-permissions)
- [Organizations](/docs/organizations)
