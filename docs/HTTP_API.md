# HTTP API

Use organization-scoped **API tokens** from **Profile → API keys** to call the dply HTTP API from CI/CD, scripts, or integrations. Tokens are sent as **Bearer** credentials. The Edge endpoints are also described as OpenAPI at [`public/openapi/edge.json`](../public/openapi/edge.json).

## Base URL

All versioned routes are under:

```text
{APP_URL}/api/v1
```

## Authentication

```http
Authorization: Bearer YOUR_TOKEN_HERE
```

Tokens belong to a **user** and **organization**. Each token lists **abilities**; the ability a route requires is mapped in `config/product/api_token_permissions.php` (`http_route_abilities`). The **deployer** organization role can only use abilities allowed by organization policy (typically read + deploy).

Creating new tokens may require a paid plan when your instance enables `DPLY_API_TOKENS_REQUIRE_PAID_PLAN`.

The CLI gets its token through the device flow instead: `POST /api/v1/auth/device/start`, the user approves in the browser, then `POST /api/v1/auth/device/poll`.

## Edge

`{site}` accepts the slug or the ULID. Edge routes share a per-token rate limit (`throttle:edge-api`, 600/min).

| Method | Path | Ability |
| --- | --- | --- |
| `GET` | `/edge/sites` | `edge.read` |
| `GET` | `/edge/sites/{site}` | `edge.read` |
| `GET` | `/edge/sites/{site}/deployments` | `edge.read` |
| `POST` | `/edge/sites/{site}/deployments` | `edge.deploy` |
| `GET` | `/edge/sites/{site}/deployments/{deployment}` | `edge.read` |
| `POST` | `/edge/sites/{site}/deployments/{deployment}/rollback` | `edge.deploy` |
| `GET` | `/edge/sites/{site}/previews` | `edge.read` |
| `POST` | `/edge/sites/{site}/previews` | `edge.deploy` |
| `DELETE` | `/edge/sites/{site}/previews/{preview}` | `edge.deploy` |
| `POST` | `/edge/sites/{site}/previews/{preview}/promote` | `edge.deploy` |
| `GET` | `/edge/sites/{site}/domains` | `edge.read` |
| `POST` | `/edge/sites/{site}/domains` | `edge.write` |
| `POST` | `/edge/sites/{site}/domains/{hostname}/verify` | `edge.write` |
| `DELETE` | `/edge/sites/{site}/domains/{hostname}` | `edge.write` |
| `GET` | `/edge/sites/{site}/aliases` | `edge.read` |
| `GET` | `/edge/sites/{site}/access` | `edge.read` |
| `PATCH` | `/edge/sites/{site}/access` | `edge.write` |
| `POST` | `/edge/sites/{site}/cache/purge` | `edge.write` |
| `GET` | `/edge/sites/{site}/usage` | `edge.read` |
| `GET` | `/edge/sites/{site}/logs` | `edge.read` |
| `GET` | `/edge/sites/{site}/env` | `edge.env.read` |
| `PUT` | `/edge/sites/{site}/env` | `edge.env.write` |
| `PATCH` | `/edge/sites/{site}/env/{key}` | `edge.env.write` |
| `DELETE` | `/edge/sites/{site}/env/{key}` | `edge.env.write` |
| `POST` | `/edge/lint` | `edge.read` |

Sites are created in the dashboard; there is no create endpoint.

## Account and billing

| Method | Path | Ability |
| --- | --- | --- |
| `GET` | `/account` | `account.read` |
| `GET` | `/account/organizations` | `account.read` |
| `GET` | `/account/sessions` | `account.read` |
| `DELETE` | `/account/sessions/{apiToken}` | `account.write` |
| `GET` | `/capabilities` | `account.read` |
| `GET` | `/billing` | `billing.read` |
| `GET` | `/billing/breakdown` | `billing.read` |
| `GET` | `/billing/invoices` | `billing.read` |

`GET /capabilities` reports what this instance offers (enabled surfaces and limits), so clients hardcode none of it.

## Notifications

| Method | Path | Ability |
| --- | --- | --- |
| `GET` | `/notifications/channels` | `notifications.read` |
| `GET` | `/notifications/events` | `notifications.read` |
| `POST` | `/notifications/channels/{channel}/test` | `notifications.write` |
| `GET` | `/sites/{site}/notifications` | `notifications.read` |
| `POST` | `/sites/{site}/notifications` | `notifications.write` |

`GET /notifications/channels` lists the channels the token's user may route to, each with `type` and a redacted `destination`. `GET /notifications/events` returns the event catalog grouped by category (`?subject=site` narrows it).

`GET /sites/{site}/notifications` returns `groups` — every event group that applies to the site, including `edge.*` — plus `channels`, each with the `events` currently routed to it. `POST` the same path with `{"channel": "…", "subscribe": [...], "unsubscribe": [...]}`: it adds and removes rather than replacing the channel's selection. An event outside the site's groups is a `422`; a channel the token cannot reach is a `404`.

`POST /notifications/channels/{channel}/test` returns `{"data": {"ok": bool, "message": "…"}}` — `200` when the provider accepted it, `422` when it did not.

## Related

- [CLI](ACCOUNT_CLI.md)
- [Organization roles & plan limits](ORG_ROLES_AND_LIMITS.md)
