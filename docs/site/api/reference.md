---
title: "API reference"
description: "Every dply HTTP API v1 endpoint, with the ability it needs, its parameters and an example response."
---

This page lists every endpoint in the dply HTTP API v1. For authentication, rate limits, errors and how tokens are scoped to your role, read [HTTP API](/docs/api) first.

All paths are relative to `https://dply.io/api/v1`. Every request needs `Authorization: Bearer dply_…` and `Accept: application/json`. Requests with a body send `Content-Type: application/json`.

In the examples, `$DPLY_TOKEN` holds your token and `$SITE` holds an app ID from [List apps](#list-apps).

> [!NOTE]
> The API calls apps "sites" in paths and field names (`/edge/sites`, `site_id`). They are the same apps you see in the dashboard.

## Endpoint summary

| Method | Path | Ability |
|---|---|---|
| `GET` | `/account` | `account.read` |
| `GET` | `/account/organizations` | `account.read` |
| `GET` | `/account/sessions` | `account.read` |
| `DELETE` | `/account/sessions/{token}` | `account.write` |
| `GET` | `/capabilities` | `account.read` |
| `GET` | `/billing` | `billing.read` |
| `GET` | `/billing/breakdown` | `billing.read` |
| `GET` | `/billing/invoices` | `billing.read` |
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
| `GET` | `/edge/databases` | `edge.read` |
| `POST` | `/edge/databases/{database}/query` | `edge.write` |
| `GET` | `/edge/queues` | `edge.read` |
| `POST` | `/edge/queues/{queue}/messages` | `edge.write` |
| `GET` | `/notifications/channels` | `notifications.read` |
| `GET` | `/notifications/events` | `notifications.read` |
| `POST` | `/notifications/channels/{channel}/test` | `notifications.write` |
| `GET` | `/sites/{site}/notifications` | `notifications.read` |
| `POST` | `/sites/{site}/notifications` | `notifications.write` |
| `POST` | `/auth/device/start` | None (CLI sign-in) |
| `POST` | `/auth/device/poll` | None (CLI sign-in) |

For endpoints under `/edge/sites/{site}` and `/sites/{site}`, your role must also allow the action on that app: `GET` needs view access, every other method needs change access. See [Roles & permissions](/docs/roles-and-permissions).

## Account

### Get the current token's account

`GET /account` · `account.read`

Returns the token's user, its organization (with your role there) and the token itself.

```bash
curl https://dply.io/api/v1/account \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json"
```

```json
{
  "data": {
    "user": { "id": "01j9…", "name": "Ada Lovelace", "email": "ada@example.com" },
    "organization": {
      "id": "01j8…",
      "name": "Acme",
      "role": "admin",
      "is_current": true,
      "projects_count": 0
    },
    "token": {
      "id": "01ja…",
      "name": "github-actions-deploy",
      "prefix": "dply_AbCdEfGhIjK",
      "masked": "dply_AbCdEfGhIjK…",
      "abilities": ["edge.read", "edge.deploy"],
      "user": { "id": "01j9…", "name": "Ada Lovelace", "email": "ada@example.com" },
      "last_used_at": "2026-09-26T14:02:11+00:00",
      "expires_at": null,
      "created_at": "2026-09-01T09:30:00+00:00",
      "is_cli": false,
      "is_current": true
    }
  }
}
```

`role` is `owner`, `admin`, `member` or `deployer`. `abilities` is empty for a token created without an ability list, which grants every ability.

### List your organizations

`GET /account/organizations` · `account.read`

Every organization the token's user belongs to. `is_current` marks the token's own organization. A token only acts on its own organization.

```json
{
  "data": [
    { "id": "01j8…", "name": "Acme", "role": "admin", "is_current": true },
    { "id": "01j7…", "name": "Side project", "role": "owner", "is_current": false }
  ]
}
```

### List CLI sessions

`GET /account/sessions` · `account.read`

Lists tokens created by `dply login` in this organization, newest use first. Owners and admins see every member's sessions; other roles see their own. Each item has the same shape as `token` in [Get the current token's account](#get-the-current-tokens-account). Tokens created on **Profile → API keys** are not included.

### Revoke a CLI session

`DELETE /account/sessions/{token}` · `account.write`

| Parameter | In | Description |
|---|---|---|
| `token` | path | The session's token ID. |

Owners and admins can revoke any member's session. Other roles can revoke only their own.

```bash
curl -X DELETE https://dply.io/api/v1/account/sessions/01ja… \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json"
```

```json
{ "message": "CLI session revoked.", "revoked_current": false }
```

If you revoke the token making the request, the message is `CLI session revoked. This token no longer works.` and `revoked_current` is `true`. An unknown ID returns `404` with `CLI session not found.`

### Get instance capabilities

`GET /capabilities` · `account.read`

Describes what this dply instance offers. The CLI reads it before `dply init`.

```json
{
  "data": {
    "instance": { "url": "https://dply.io", "name": "dply" },
    "kinds": {
      "edge": {
        "enabled": true,
        "cli_create_supported": false,
        "cli_create": false,
        "create_url": "https://dply.io/…",
        "requires_git": true
      }
    }
  }
}
```

## Billing

All billing endpoints need `billing.read`, and the token's user must be an organization owner or admin. Otherwise they return `403` with `Org admin access is required to view billing.` Amounts are in US cents.

### Get the billing summary

`GET /billing` · `billing.read`

```json
{
  "data": {
    "organization_id": "01j8…",
    "summary": {
      "monthly_total_cents": 2000,
      "daily_run_rate_cents": 67,
      "interval": "month",
      "subscribed": true,
      "stripe_status": "active",
      "next_invoice_at": "2026-10-01",
      "edge_count": 12
    },
    "plan": { "key": "pro", "label": "Pro", "price_cents": 2000 },
    "monthly_total_cents": 2000,
    "managed_subtotal_cents": 2000,
    "is_free": false,
    "counts": { "edge": 12 },
    "subscription": {
      "active": true,
      "status": "active",
      "on_grace_period": false,
      "ends_at": null,
      "interval": "month",
      "items": [{ "price_id": "price_…", "quantity": 1 }]
    }
  }
}
```

With no subscription, `subscription` is `{ "active": false, "items": [] }`.

### Get the cost breakdown

`GET /billing/breakdown` · `billing.read`

The current month's estimate by category and by line item. Categories with a zero amount are left out.

```json
{
  "data": {
    "monthly_total_cents": 2000,
    "categories": [
      { "key": "plan", "label": "Pro", "cents": 2000, "color": "bg-brand-forest/70" },
      { "key": "usage", "label": "Usage after credit", "cents": 1200, "color": "bg-brand-sage/50" }
    ],
    "line_items": [
      { "label": "Pro plan", "quantity": 1, "unit_cents": 2000, "line_cents": 2000, "detail": null },
      { "label": "Delivery (requests, bandwidth, site storage)", "quantity": 1, "unit_cents": 3200, "line_cents": 3200, "detail": null },
      { "label": "Included usage credit", "quantity": 1, "unit_cents": -2000, "line_cents": -2000, "detail": "Pro includes $20.00 of usage each period" }
    ]
  }
}
```

Category keys are `plan`, `seats` and `usage`. `color` is a dashboard style hint; ignore it in your own tools.

### List invoices

`GET /billing/invoices` · `billing.read`

The last 24 invoices, newest first.

```json
{
  "data": {
    "invoices": [
      { "id": "in_…", "number": "ACME-0007", "date": "2026-09-01", "total_cents": 2400, "status": "paid", "paid": true }
    ]
  }
}
```

## Apps

### List apps

`GET /edge/sites` · `edge.read`

Every Edge app in the token's organization that the token's user can view, sorted by name. Preview apps are included, marked with `is_preview`.

```bash
curl https://dply.io/api/v1/edge/sites \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "id": "01j9z3k6x8m2q4r5t7v9w0y1a2",
      "organization_id": "01j8…",
      "name": "marketing",
      "slug": "marketing",
      "status": "edge_active",
      "is_preview": false,
      "parent_site_id": null,
      "runtime_mode": "static",
      "hostname": "marketing.dply.app",
      "live_url": "https://marketing.dply.app",
      "dashboard_url": "https://dply.io/projects/01j9…/sites/01j9z3k6…",
      "repository": "acme/marketing",
      "branch": "main",
      "repo_root": null,
      "active_deployment_id": "01ja…",
      "preview_protection": { "mode": "off", "enabled": false },
      "created_at": "2026-08-01T10:00:00+00:00",
      "updated_at": "2026-09-26T13:40:00+00:00"
    }
  ]
}
```

| Field | Description |
|---|---|
| `status` | `edge_provisioning`, `edge_active`, `edge_failed` or `edge_deleting`. |
| `runtime_mode` | `static`, `hybrid`, `ssr` or `container`. |
| `hostname`, `live_url` | The app's dply hostname and URL. |
| `repo_root` | The app's directory inside a monorepo, or `null`. |
| `preview_protection` | The access rule in force. On a preview it is the parent's rule and adds `inherited_from_site_id`. |

### Get an app

`GET /edge/sites/{site}` · `edge.read`

| Parameter | In | Description |
|---|---|---|
| `site` | path | The app ID. |

Returns one app in the shape above, wrapped in `data`. Returns `404` with `Edge site not found.` if the ID is not an Edge app in the token's organization.

## Deployments

A deployment object:

```json
{
  "id": "01ja…",
  "site_id": "01j9…",
  "status": "live",
  "git_commit": "9f2c1ab7e4d0c3b2a1f0e9d8c7b6a5f4e3d2c1b0",
  "git_branch": "main",
  "storage_prefix": "sites/01j9…/01ja…",
  "pruned": false,
  "cf_kv_version": "…",
  "aliases": ["9f2c1ab-marketing.dply.app"],
  "repo_config": {
    "source_path": "dply.yaml",
    "build_overrides": {},
    "redirect_count": 3,
    "rewrite_count": 0,
    "header_rule_count": 1,
    "warning_count": 0
  },
  "failure_reason": null,
  "published_at": "2026-09-26T13:40:00+00:00",
  "failed_at": null,
  "created_at": "2026-09-26T13:38:12+00:00"
}
```

`status` is `building`, `publishing`, `live`, `failed` or `superseded`. `pruned` is `true` once the deployment's files have been removed, after which it cannot be rolled back to.

### List deployments

`GET /edge/sites/{site}/deployments` · `edge.read`

| Parameter | In | Description |
|---|---|---|
| `limit` | query | Number of deployments, newest first. Default `20`, between `1` and `100`. |

```bash
curl "https://dply.io/api/v1/edge/sites/$SITE/deployments?limit=5" \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json"
```

Returns `{ "data": [ …deployment objects… ] }`.

### Trigger a deployment

`POST /edge/sites/{site}/deployments` · `edge.deploy`

With an empty body, dply rebuilds from the tip of the app's branch. With `commit`, it deploys that commit; if the commit was already built, dply publishes the existing build.

| Parameter | In | Rules |
|---|---|---|
| `commit` | body | Optional. 7 to 40 hexadecimal characters. |
| `branch` | body | Optional, up to 200 characters. Used only with `commit`. |
| `branch_tip` | body | Optional boolean. Same as an empty body. |

```bash
curl -X POST https://dply.io/api/v1/edge/sites/$SITE/deployments \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"commit": "9f2c1ab"}'
```

Returns `202` with the new deployment in `data`. Poll [Get a deployment](#get-a-deployment) until `status` is `live` or `failed`. Returns `422` with a `message` if the deploy cannot start.

### Get a deployment

`GET /edge/sites/{site}/deployments/{deployment}` · `edge.read`

Returns one deployment in `data`, or `404` with `Deployment not found.`

### Roll back to a deployment

`POST /edge/sites/{site}/deployments/{deployment}/rollback` · `edge.deploy`

Points production at an earlier deployment. The deployment must not be pruned. Returns the deployment in `data`, or `422` with the reason.

```bash
curl -X POST https://dply.io/api/v1/edge/sites/$SITE/deployments/01ja…/rollback \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json"
```

## Previews

Previews are separate apps linked to a parent. Call these endpoints with the parent app's ID; a preview ID in `{site}` returns `404`.

### List previews

`GET /edge/sites/{site}/previews` · `edge.read`

Returns the parent's live previews, newest first, as app objects (see [List apps](#list-apps)).

### Create a preview

`POST /edge/sites/{site}/previews` · `edge.deploy`

| Parameter | In | Rules |
|---|---|---|
| `commit` | body | Required. 7 to 40 hexadecimal characters. |
| `branch` | body | Optional, up to 200 characters. Defaults to the parent's branch, or `main`. |
| `ref_kind` | body | Optional. `branch`, `tag` or `commit`. |

```bash
curl -X POST https://dply.io/api/v1/edge/sites/$SITE/previews \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"commit": "4be1d02", "branch": "feature/pricing"}'
```

Returns `202` with the preview app in `data`.

### Delete a preview

`DELETE /edge/sites/{site}/previews/{preview}` · `edge.deploy`

Queues the preview's teardown.

```json
{ "message": "Preview teardown queued." }
```

Status `202`. Returns `404` with `Preview not found.` if the preview does not belong to the parent.

### Promote a preview

`POST /edge/sites/{site}/previews/{preview}/promote` · `edge.deploy`

Copies the preview's build to production. The preview keeps running. Returns the new production deployment in `data`, or `422` with the reason.

## Domains

### List domains

`GET /edge/sites/{site}/domains` · `edge.read`

```json
{
  "data": [
    {
      "hostname": "www.example.com",
      "mode": "manual",
      "dns_status": "active",
      "ssl_status": "active",
      "cname_target": "marketing.dply.app",
      "cf_custom_hostname_id": "…",
      "ownership_verification": null,
      "dply_verification": null,
      "analytics_zone": null,
      "attached_at": "2026-09-02T08:00:00+00:00",
      "verified_at": "2026-09-02T08:05:00+00:00",
      "error": null,
      "ssl_error": null
    }
  ]
}
```

When a domain needs a DNS record to prove ownership, `ownership_verification` or `dply_verification` holds the record to create. See [Domain verification](/docs/domain-verification).

### Add a domain

`POST /edge/sites/{site}/domains` · `edge.write`

| Parameter | In | Rules |
|---|---|---|
| `hostname` | body | Required. A valid hostname with at least one dot, up to 253 characters. |

```bash
curl -X POST https://dply.io/api/v1/edge/sites/$SITE/domains \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"hostname": "www.example.com"}'
```

Returns `202` with the DNS record to create:

```json
{
  "data": [
    { "name": "www.example.com", "type": "CNAME", "value": "marketing.dply.app", "status": "pending" }
  ]
}
```

Plan limits on custom domains apply. Returns `422` with the reason when the domain cannot be added.

### Verify a domain

`POST /edge/sites/{site}/domains/{hostname}/verify` · `edge.write`

Re-checks DNS and certificate status for a domain already added to the app. Returns the domain's updated record in `data`, or `"data": null` if the hostname is not attached to this app.

### Remove a domain

`DELETE /edge/sites/{site}/domains/{hostname}` · `edge.write`

```json
{ "message": "Custom domain removed." }
```

## Aliases

### List deployment aliases

`GET /edge/sites/{site}/aliases` · `edge.read`

Per-deployment hostnames for the newest 50 live or superseded deployments.

```json
{
  "data": [
    {
      "hostname": "9f2c1ab-marketing.dply.app",
      "deployment_id": "01ja…",
      "git_commit": "9f2c1ab7e4…",
      "git_branch": "main",
      "published_at": "2026-09-26T13:40:00+00:00"
    }
  ]
}
```

## Access protection

These endpoints manage the app's password or dply-account protection. See [Access control](/docs/access-control). Calling them with a preview's ID returns `422` with `Configure preview protection on the parent Edge site.`

### Get access protection

`GET /edge/sites/{site}/access` · `edge.read`

```json
{
  "data": {
    "site_id": "01j9…",
    "mode": "dply_account",
    "enabled": true,
    "password_set": false,
    "allowed_emails": ["ada@example.com"],
    "account_login_url": "https://dply.io/projects/sites/01j9…/preview-access"
  }
}
```

`account_login_url` appears only when `mode` is `dply_account` and protection is on. The password itself is never returned.

### Update access protection

`PATCH /edge/sites/{site}/access` · `edge.write`

| Parameter | In | Rules |
|---|---|---|
| `mode` | body | Required. `off`, `password` or `dply_account`. |
| `password` | body | Optional, up to 200 characters. Used with `password` mode. |
| `allowed_emails` | body | Optional array of up to 100 email addresses. Used with `dply_account` mode. |

```bash
curl -X PATCH https://dply.io/api/v1/edge/sites/$SITE/access \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"mode": "password", "password": "correct horse battery staple"}'
```

Returns the updated rule in the shape above.

## Cache

### Purge the cache

`POST /edge/sites/{site}/cache/purge` · `edge.write`

Send either `tag` or `paths`. If both are sent, `tag` wins.

| Parameter | In | Rules |
|---|---|---|
| `tag` | body | Up to 128 characters: letters, digits, `.`, `_` and `-`. |
| `paths` | body | Array of up to 100 paths, each up to 2048 characters. |

```bash
curl -X POST https://dply.io/api/v1/edge/sites/$SITE/cache/purge \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"paths": ["/", "/pricing"]}'
```

```json
{ "data": { "ok": true, "purged_keys": 2, "message": "…" } }
```

Returns `200` when the purge succeeded and `422` (same body, `"ok": false`) when it did not. See [Caching](/docs/caching).

## Usage

### Get app usage

`GET /edge/sites/{site}/usage` · `edge.read`

| Parameter | In | Description |
|---|---|---|
| `days` | query | Days of daily history. Default `30`, between `1` and `90`. |

Returns this billing period's requests, egress, storage and cost estimate for the app, plus a `daily` series. Usage is collected once a day.

```json
{
  "data": {
    "site_id": "01j9…",
    "site_name": "marketing",
    "platform_cents": 0,
    "platform_kind": "included",
    "usage_cents": 0,
    "total_cents": 0,
    "requests": 184220,
    "bytes_egress": 5210093312,
    "daily": [{ "date": "2026-09-25", "label": "Sep 25", "requests": 6120, "bytes_egress": 173004800, "cost_cents": 0 }],
    "requests_7d": 41200,
    "bytes_egress_7d": 1180000000,
    "last_collected_date": "2026-09-25",
    "byo_cloudflare": false
  }
}
```

The response has more fields than shown (storage operations, `usage_detail`, `peak_day`, `tracked_hostnames`). Before the first daily collection, or for a preview, `data` is `{ "message": "Usage not yet available — wait for the first nightly rollup." }`. See [Usage & metering](/docs/usage).

## Logs

### Tail request logs

`GET /edge/sites/{site}/logs` · `edge.read`

Returns requests served by the app, oldest first. `dply edge logs --tail` polls this endpoint.

| Parameter | In | Description |
|---|---|---|
| `since` | query | ISO 8601 timestamp. Only requests after it are returned. Default: 60 seconds ago. |
| `limit` | query | Maximum rows. Default `100`, between `1` and `500`. |

```bash
curl "https://dply.io/api/v1/edge/sites/$SITE/logs?since=2026-09-26T14:00:00Z" \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "occurred_at": "2026-09-26T14:00:03+00:00",
      "deployment_id": "01ja…",
      "hostname": "www.example.com",
      "method": "GET",
      "path": "/pricing",
      "status": 200,
      "duration_ms": 14,
      "bytes_egress": 18342,
      "cache_status": "HIT",
      "country": "US"
    }
  ],
  "meta": {
    "since": "2026-09-26T14:00:00+00:00",
    "count": 1,
    "tail_cursor": "2026-09-26T14:00:03+00:00"
  }
}
```

To follow the log, pass `meta.tail_cursor` as `since` on the next request. See [Logs](/docs/logs).

## Environment variables

These endpoints manage the app's production environment variables. Values are encrypted and never returned. Changes apply on the next deploy. `{site}` accepts the app ID or slug.

Keys must be uppercase, start with a letter, and contain only `A`–`Z`, `0`–`9` and `_` (up to 128 characters). These names are reserved: `HOST_MAP`, `ASSETS`, `ARTIFACTS`, `DEPLOYMENT_ID`, `SITE_ID`, `STORAGE_PREFIX`, `EDGE_ANALYTICS`, `LOG_INGEST_BASE_URL`, `LOG_INGEST_KEY`, `ENVIRONMENT`.

### List variable keys

`GET /edge/sites/{site}/env` · `edge.env.read`

```json
{
  "data": [
    { "key": "API_BASE_URL", "updated_at": "2026-09-20T10:00:00+00:00", "created_at": "2026-08-01T10:00:00+00:00" }
  ]
}
```

### Replace all variables

`PUT /edge/sites/{site}/env` · `edge.env.write`

The body is a JSON object of key-value pairs. It replaces the whole set: keys you leave out are deleted.

```bash
curl -X PUT https://dply.io/api/v1/edge/sites/$SITE/env \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"API_BASE_URL": "https://api.example.com", "FEATURE_FLAGS": "beta"}'
```

Returns the resulting key list, as in [List variable keys](#list-variable-keys). If any key is invalid, nothing is saved and the response is `422`:

```json
{ "message": "Invalid env payload.", "errors": { "host_map": "Key must be ALL_CAPS, start with a letter, and contain only A–Z, 0–9, and underscores." } }
```

> [!WARNING]
> `PUT` deletes every production variable not in the body. To change one variable, use `PATCH`.

### Set one variable

`PATCH /edge/sites/{site}/env/{key}` · `edge.env.write`

| Parameter | In | Rules |
|---|---|---|
| `key` | path | A valid key (see above). |
| `value` | body | A string or other scalar. |

```bash
curl -X PATCH https://dply.io/api/v1/edge/sites/$SITE/env/API_BASE_URL \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"value": "https://api.example.com"}'
```

```json
{ "data": { "key": "API_BASE_URL", "updated_at": "2026-09-26T14:10:00+00:00", "created_at": "2026-08-01T10:00:00+00:00" } }
```

### Delete a variable

`DELETE /edge/sites/{site}/env/{key}` · `edge.env.write`

```json
{ "deleted": 1 }
```

`deleted` is `0` if the key did not exist.

## Config linting

### Lint a config file

`POST /edge/lint` · `edge.read`

Validates the contents of a `dply.yaml` or `dply.json` without deploying. See [Configuration files](/docs/configuration-files).

| Parameter | In | Rules |
|---|---|---|
| `path` | body | Required. The file name, up to 255 characters, for example `dply.yaml`. |
| `content` | body | Required. The file contents, up to 65,536 characters. |

```bash
curl -X POST https://dply.io/api/v1/edge/lint \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d "$(jq -n --rawfile c dply.yaml '{path: "dply.yaml", content: $c}')"
```

```json
{
  "data": {
    "ok": true,
    "source_path": "dply.yaml",
    "errors": [],
    "warnings": [],
    "summary": {
      "redirects": 3, "rewrites": 0, "headers": 1,
      "build_keys": ["command", "output"],
      "tags": 0, "snippets": 0, "forms": 1
    }
  }
}
```

Status is `200` when `ok` is `true` and `422` when the file has errors.

## Databases

Edge SQL databases belong to the organization. Create and delete them in the dashboard. See [Edge SQL (D1)](/docs/resources/sql).

### List databases

`GET /edge/databases` · `edge.read`

```json
{
  "data": [
    { "id": "01jb…", "name": "app-db", "cloudflare_id": "…", "created_at": "2026-09-10T09:00:00+00:00" }
  ]
}
```

### Run SQL

`POST /edge/databases/{database}/query` · `edge.write`

| Parameter | In | Rules |
|---|---|---|
| `database` | path | The database ID or name. |
| `sql` | body | Required, up to 100,000 characters. |
| `params` | body | Optional array of bound parameters for `?` placeholders. |

```bash
curl -X POST https://dply.io/api/v1/edge/databases/app-db/query \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"sql": "SELECT id, email FROM users WHERE id = ?", "params": [42]}'
```

Returns the database's query result in `data`. SQL errors return `422` with the database's message.

> [!WARNING]
> This runs any SQL, including writes and schema changes, against the live database.

## Queues

Queues belong to the organization. See [Queues](/docs/resources/queues).

### List queues

`GET /edge/queues` · `edge.read`

```json
{ "data": [{ "id": "01jc…", "name": "emails", "cloudflare_name": "…" }] }
```

### Send a message

`POST /edge/queues/{queue}/messages` · `edge.write`

| Parameter | In | Rules |
|---|---|---|
| `queue` | path | The queue ID or name. |
| `body` | body | Required. Any JSON value. |

```bash
curl -X POST https://dply.io/api/v1/edge/queues/emails/messages \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"body": {"type": "welcome", "user_id": 42}}'
```

```json
{ "data": { "queued": true } }
```

Status `202`.

## Notifications

Channels are where alerts go (Slack, email, webhooks and others). Routing decides which events reach which channel for an app. See [Notification channels](/docs/notifications).

### List channels

`GET /notifications/channels` · `notifications.read`

The channels the token's user can route events to: their personal channels, channels of teams whose channels they can manage, and, for organization owners and admins, the organization's channels.

```json
{
  "data": [
    { "id": "01jd…", "label": "#deploys", "type": "slack", "destination": "Acme workspace", "owner": "Organization" }
  ]
}
```

`destination` is a redacted description, never the full webhook URL or key. `owner` is `Organization`, `Team` or `User`.

### List the event catalog

`GET /notifications/events` · `notifications.read`

| Parameter | In | Description |
|---|---|---|
| `subject` | query | `site` for the groups that can apply to an app. Omit it for the full catalog. |

```json
{
  "data": [
    {
      "key": "edge",
      "label": "Edge",
      "events": [
        { "key": "edge.deploy.succeeded", "label": "Edge deploy succeeded" },
        { "key": "edge.deploy.failed", "label": "Edge deploy failed (action required)" }
      ]
    }
  ]
}
```

> [!NOTE]
> The full catalog also lists event groups that do not apply to Edge apps. Use `GET /sites/{site}/notifications` to see exactly which events an app can route.

### Send a test message

`POST /notifications/channels/{channel}/test` · `notifications.write`

Sends the channel's test message, the same as **Test** in the dashboard.

```json
{ "data": { "ok": true, "message": "…" } }
```

`200` when the provider accepted it, `422` when it did not, `404` when the channel is not available to the token.

### Get an app's event routing

`GET /sites/{site}/notifications` · `notifications.read`

`{site}` accepts the app ID or slug. Returns the event groups that apply to the app and, for each channel you can use, the events routed to it.

```json
{
  "data": {
    "groups": [
      { "key": "site_uptime", "label": "…", "events": [{ "key": "site.uptime.down", "label": "Down & recovered" }] },
      { "key": "edge", "label": "Edge", "events": [{ "key": "edge.deploy.failed", "label": "Edge deploy failed (action required)" }] }
    ],
    "channels": [
      { "id": "01jd…", "label": "#deploys", "type": "slack", "events": ["edge.deploy.failed"] }
    ]
  }
}
```

### Change an app's event routing

`POST /sites/{site}/notifications` · `notifications.write`

Adds and removes events for one channel. Events you do not mention are left as they are.

| Parameter | In | Rules |
|---|---|---|
| `channel` | body | Required. A channel ID from [List channels](#list-channels). |
| `subscribe` | body | Array of event keys to add. |
| `unsubscribe` | body | Array of event keys to remove. |

```bash
curl -X POST https://dply.io/api/v1/sites/$SITE/notifications \
  -H "Authorization: Bearer $DPLY_TOKEN" -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"channel": "01jd…", "subscribe": ["edge.deploy.failed", "site.uptime.down"]}'
```

```json
{
  "data": {
    "channel": "01jd…",
    "events": ["edge.deploy.failed", "site.uptime.down"],
    "added": 1,
    "removed": 0
  }
}
```

An event that does not apply to the app returns `422` with `Unknown event for this subject: …` and the list of valid keys in `events`. Sending neither `subscribe` nor `unsubscribe` returns `422`. A channel the token cannot use returns `404`.

## CLI sign-in

The CLI uses these two unauthenticated endpoints for `dply login`. You do not need them unless you are building your own client. See [CLI](/docs/cli).

### Start a sign-in

`POST /auth/device/start`

```json
{
  "device_code": "…",
  "user_code": "ABCD-EFGH",
  "verification_uri": "https://dply.io/auth/device",
  "verification_uri_complete": "https://dply.io/auth/device?user_code=ABCD-EFGH",
  "expires_in": 900,
  "interval": 2
}
```

Send the user to `verification_uri_complete` to approve. The code expires after 15 minutes.

### Poll for the token

`POST /auth/device/poll`

| Parameter | In | Rules |
|---|---|---|
| `device_code` | body | Required, 16 to 128 characters. |

Poll every `interval` seconds. The response is `{ "status": "pending" }`, `"denied"` or `"expired"` until the user approves, then once:

```json
{ "status": "authorized", "token": "dply_…" }
```

The token is returned only once. Later polls with the same code return `expired`.

## Related

- [HTTP API](/docs/api)
- [CLI](/docs/cli)
- [Roles & permissions](/docs/roles-and-permissions)
- [Deployments](/docs/deployments)
