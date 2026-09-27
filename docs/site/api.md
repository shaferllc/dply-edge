---
title: "HTTP API"
description: "Call dply from CI, scripts and integrations with organization-scoped API tokens."
---

The dply HTTP API lets you deploy, roll back, manage previews, domains, access rules and environment variables, tail logs, and read usage and billing from outside the dashboard. Use it from CI pipelines, deploy scripts or your own tooling. The [CLI](/docs/cli) and the [MCP server](/docs/mcp) use the same tokens and abilities.

Every endpoint is listed with parameters and example responses in the [API reference](/docs/api/reference).

## Base URL

All endpoints live under a versioned prefix:

```text
https://edge.dply.io/api/v1
```

## Authentication

Send an API token as a bearer token on every request, and ask for JSON:

```bash
curl https://edge.dply.io/api/v1/account \
  -H "Authorization: Bearer dply_your_token_here" \
  -H "Accept: application/json"
```

Tokens start with `dply_`. The API also accepts the token in an `X-API-Key` header, but `Authorization: Bearer` is the recommended form.

> [!IMPORTANT]
> Always send `Accept: application/json`. Without it, some errors (validation failures, authorization failures, rate limits) come back as HTML pages or redirects instead of JSON.

### How a token is scoped

Each token belongs to one user and one organization. A request succeeds only when all of these hold:

1. **The token is valid.** It exists and has not passed its expiry date.
2. **The caller's IP is allowed.** If the token has an IP allow-list, the request must come from an address on it.
3. **The user still belongs to the organization.** If the token's owner leaves or is removed from the organization, the token stops working immediately (`403`).
4. **The token has the ability.** Each endpoint requires one ability, such as `edge.deploy`.
5. **The user's role allows it on that app.** A token never outranks its owner. Read requests need permission to view the app; every other request needs permission to change it. See [Roles & permissions](/docs/roles-and-permissions).

If the owner's role in the organization is **Deployer**, the token is limited to the deployer abilities at request time, whatever abilities it was created with. The deployer abilities are `account.read`, `account.write`, `edge.read`, `edge.deploy`, `edge.env.read`, `sites.read` and `servers.read`.

## Create a token

> [!NOTE]
> Only organization owners and admins can create tokens on the **API keys** page. Members and deployers get a token by signing in with the [CLI](/docs/cli) (`dply login`), which caps its abilities to their role.

1. Open **Profile**, then **API keys**.
2. Choose **Add API token**.
3. Pick the organization the token belongs to. Only organizations where you are an owner or admin are listed.
4. Enter a **name** that says where the token is used, for example `github-actions-deploy`.
5. Optionally set an **expiry date**. It must be after today. Without one, the token never expires.
6. Optionally add an **IP allow-list**: one IP address or IPv4 CIDR range per line (or separated by commas). IPv6 addresses must be exact.
7. Select at least one permission, then create the token.

dply shows the token once, under **Copy this token now — you won't see it again**. Store it in your CI secret store or a password manager.

If the page shows **Pro plan required to create tokens**, the organization needs an active plan before you can add tokens. Existing tokens can still be revoked.

### Revoke a token

On **Profile → API keys**, choose **Revoke** next to the token. It stops working immediately. Owners and admins can also see and revoke every token in the organization from the organization's settings. See [Organizations](/docs/organizations).

Creating and revoking tokens is recorded in the [Activity log](/docs/activity-log).

## Abilities

Grant each token only what the integration needs.

| Ability | Label in the dashboard | Allows |
|---|---|---|
| `edge.read` | Edge: Read | List and read apps, deployments, previews, domains, aliases, access rules, usage and logs. Lint config files. List databases and queues. |
| `edge.deploy` | Edge: Deploy / rollback / promote | Trigger deploys, roll back, create, delete and promote previews. |
| `edge.write` | Edge: Manage domains, access and cache | Add, verify and remove domains, change access protection, purge the cache, run SQL on databases, send queue messages. |
| `edge.env.read` | Edge env vars: Read (keys only) | List environment variable keys. Values are never returned. |
| `edge.env.write` | Edge env vars: Write | Set, replace and delete environment variables. |
| `notifications.read` | Notifications: Read channels and event subscriptions | List channels, the event catalog, and an app's event routing. |
| `notifications.write` | Notifications: Route events to channels, send tests | Change an app's event routing and send test messages. |
| `billing.read` | Billing: View plan, estimates, and invoices | Read the plan, cost breakdown and invoices. The owner must also be an organization admin. |
| `account.read` | Account & CLI: Read profile, orgs, and CLI sessions | Read the token's user and organization, list organizations and CLI sessions, read instance capabilities. |
| `account.write` | Account & CLI: Revoke CLI sessions | Revoke CLI sessions. |
| `sites.read` | AI assistants (MCP): List and read sites | Used by the [MCP server](/docs/mcp). |
| `servers.read` | AI assistants (MCP): List site hosts | Used by the [MCP server](/docs/mcp). |

## Rate limits

Requests are limited per token.

| Scope | Limit |
|---|---|
| All authenticated `/api/v1` endpoints, including `/api/v1/edge/*` | 60 requests per minute per token |
| `/api/v1/edge/*` (additional limit) | 600 requests per minute per token |
| `POST /api/v1/auth/device/start` | 30 requests per minute per IP address |
| `POST /api/v1/auth/device/poll` | 60 requests per minute per IP address |

Both limits apply to Edge endpoints, so in practice a single token can make 60 requests per minute. Responses carry `X-RateLimit-Limit` and `X-RateLimit-Remaining` headers. Over the limit, the API returns `429 Too Many Requests` with a `Retry-After` header giving the seconds to wait.

> [!TIP]
> If a CI job polls deployment status, poll every few seconds rather than in a tight loop, or use separate tokens for separate pipelines.

## Errors

Errors are JSON objects with a `message`. Validation errors add an `errors` object keyed by field.

```json
{
  "message": "The hostname field is required.",
  "errors": {
    "hostname": ["The hostname field is required."]
  }
}
```

| Status | Meaning |
|---|---|
| `200` | Success. |
| `202` | Accepted. The work (a deploy, preview, domain attach, teardown or queue message) was queued. |
| `401` | No token, an unknown token (`Unauthorized`), or an expired token (`Token expired or invalid`). |
| `403` | The token lacks the ability, the caller's IP is not on the allow-list, the token's user left the organization (`Forbidden`), or the user's role does not allow the action on this app (`Your role does not allow this on this site.`). |
| `404` | The app, deployment, preview, database, queue or channel does not exist in the token's organization, or the app is not an Edge app (`Edge site not found.`). |
| `422` | Validation failed, or dply could not complete the action. The `message` explains why, for example a rollback target whose files were pruned. |
| `429` | Rate limit exceeded. |

> [!NOTE]
> The environment variable and app notification endpoints look an app up by slug or ID. If neither matches, they currently answer with a redirect to the dashboard instead of a `404`. All other app endpoints take the app ID only and return `404`.

## Lists and pagination

List endpoints return a `data` array and are not paginated. Endpoints that can grow return the most recent items, with a query parameter to widen the window:

| Endpoint | Window |
|---|---|
| Deployments | Newest 20, up to 100 with `?limit=` |
| Logs | Requests since `?since=` (default the last 60 seconds), up to 500 with `?limit=`. The response includes a `tail_cursor` to pass as the next `since`. |
| Aliases | Aliases of the newest 50 live or superseded deployments |
| Invoices | The last 24 invoices |

## IDs and timestamps

IDs are ULID strings, such as `01j9z3k6x8m2q4r5t7v9w0y1a2`. Find an app's ID with `GET /api/v1/edge/sites` or in the `id` field of any app response. Timestamps are ISO 8601 with a UTC offset.

## Versioning

The version is part of the path (`/api/v1`). Fields may be added to v1 responses; build clients that ignore fields they do not recognize. There is no version header.

## What the API does not cover

Create and delete apps, databases and queues in the dashboard. The API manages apps that already exist.

## Next steps

- [API reference](/docs/api/reference)
- [CLI](/docs/cli)
- [MCP server](/docs/mcp)
- [Roles & permissions](/docs/roles-and-permissions)
