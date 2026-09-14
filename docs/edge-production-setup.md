# dply Edge — production setup

Edge supports two delivery modes:

| Mode | Backend key | Who pays Cloudflare | Setup |
|------|-------------|---------------------|--------|
| **Dply Edge (managed)** — default | `dply_edge` | dply (pass-through + margin on usage) | Platform `DPLY_EDGE_*` env (below) |
| **Your Cloudflare account (BYO)** | `org_cloudflare` | Customer directly | Org Cloudflare credential + `dply:edge:bootstrap-org` |

Org Cloudflare credentials under **Settings → Server providers** can also power **DNS automation** on BYO VMs. Edge BYO reuses the same credential with additional Workers/KV/R2 scopes stored in `credentials.edge` metadata.

---

## Platform mode (managed — default)

This runbook wires the **platform-owned** Cloudflare stack Edge needs in production: R2 artifacts, Workers KV host map, and the delivery Worker.

Customer Cloudflare tokens connected for DNS-only scopes are **not** sufficient for Edge BYO — see [BYO mode](#byo-mode-customer-cloudflare-account) below.

## Prerequisites

- Cloudflare account with **Workers**, **R2**, and **Workers KV** enabled
- API token with at least:
  - Account → Workers Scripts → Edit
  - Account → Workers KV Storage → Edit
  - Account → Workers R2 Storage → Edit
- Wildcard DNS for Edge delivery domains (e.g. `*.on-dply.site`) routed to the Worker zone
- Queue workers with **Docker** available for `BuildEdgeSiteJob` (bootstrap with `dply:edge:ensure-build-docker` — see [§6](#6-build-workers-docker-on-the-control-plane))

## 1. Bootstrap R2 + KV (API)

Set account credentials, then create bucket + KV namespace:

```bash
export DPLY_EDGE_CF_ACCOUNT_ID=your_account_id
export DPLY_EDGE_CF_API_TOKEN=your_api_token

php artisan dply:edge:infra:bootstrap
```

Options:

```bash
php artisan dply:edge:infra:bootstrap \
  --bucket=dply-edge-artifacts \
  --kv-title=dply-edge-host-map \
  --dry-run
```

The command prints a `.env` block with values to copy into production secrets.

## 2. Create R2 S3 access keys (dashboard)

Laravel uploads build artifacts via the S3-compatible R2 API. Create keys in Cloudflare:

**R2 → Manage R2 API tokens → Create API token**

- Permission: **Object Read & Write** on your Edge bucket
- Copy **Access Key ID** and **Secret Access Key**

Add to production `.env`:

```dotenv
DPLY_FAKE_EDGE=false
FEATURE_SURFACE_EDGE=true

DPLY_EDGE_R2_BUCKET=dply-edge-artifacts
DPLY_EDGE_R2_REGION=auto
DPLY_EDGE_R2_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
DPLY_EDGE_R2_ACCESS_KEY=...
DPLY_EDGE_R2_SECRET=...
DPLY_EDGE_R2_KEY_PREFIX=edge/

DPLY_EDGE_CF_ACCOUNT_ID=...
DPLY_EDGE_CF_API_TOKEN=...
DPLY_EDGE_CF_KV_NAMESPACE_ID=...
DPLY_EDGE_CF_WORKER_SCRIPT=dply-edge
DPLY_EDGE_CF_ZONE_NAME=on-dply.site
DPLY_EDGE_CF_WORKER_ROUTES=*.on-dply.site/*
DPLY_EDGE_TESTING_DOMAINS=on-dply.site
```

`DPLY_EDGE_R2_ENDPOINT` is optional when `DPLY_EDGE_CF_ACCOUNT_ID` is set — Laravel derives `https://{account_id}.r2.cloudflarestorage.com`.

## 3. Validate credentials

```bash
php artisan dply:edge:doctor
php artisan dply:edge:doctor --probe   # live R2 + KV write test
php artisan dply:edge:doctor --json
```

`--probe` writes and deletes a temporary KV key and R2 object.

## 4. Deploy the Cloudflare Worker

From the app server or CI (Node 20+):

```bash
php artisan edge:worker:deploy
php artisan edge:worker:deploy --dry-run
```

This generates `packages/edge-worker/wrangler.generated.toml` from Laravel config and runs `wrangler deploy`.

Manual alternative:

```bash
cd packages/edge-worker
npm ci
# edit wrangler.toml bindings, then:
npm run deploy
```

## 5. DNS

Point Edge preview hostnames at Cloudflare:

| Record | Value |
|--------|--------|
| `*.on-dply.site` | Worker route (via `DPLY_EDGE_CF_WORKER_ROUTES`) or CNAME to Worker |

When `DPLY_EDGE_CF_ZONE_NAME` + `DPLY_EDGE_CF_WORKER_ROUTES` are set, `edge:worker:deploy` attaches routes automatically.

Custom domains attach per-site via the Edge dashboard; Laravel writes KV entries on publish.

## 6. Build workers (Docker on the control plane)

Edge builds (`BuildEdgeSiteJob`) run on dply's **control-plane worker** VMs
(`DPLY_RUNTIME=worker`, queue `dply-provision`) — not customer VMs. Docker
access must be granted to the user that **runs Horizon** — `dply` on
dply-provisioned boxes, `www-data` under `deploy/supervisor/dply-worker*.conf`.
Leave `DPLY_EDGE_BUILD_DOCKER_USER` unset and it is detected: a build job
self-heals as its own worker user (needs passwordless sudo), and
`sudo artisan` grants the invoking user (`SUDO_USER`). Set it only when those
differ from the Horizon user.

If deploys fail with *passwordless sudo* / *daemon unreachable*, bootstrap once
as root on each worker:

```bash
sudo php artisan dply:edge:ensure-build-docker          # grants the sudo-invoking user; --user=… to override
php artisan horizon:terminate
php artisan dply:edge:ensure-build-docker --check
php artisan dply:edge:warm-build-images                   # optional
```

New control-plane workers: set `DPLY_PROVISION_EDGE_BUILD_DOCKER=true` so
provision installs Docker and adds `www-data` (+ `dply`) to the docker group.

`dply:edge:doctor` and `dply:runtime:check` both probe the Docker daemon.

```dotenv
DPLY_EDGE_BUILD_IMAGE=node:20-bookworm
DPLY_EDGE_BUILD_TIMEOUT=900
DPLY_EDGE_ARTIFACT_MAX_BYTES=524288000
# Horizon user on workers (default www-data — see deploy/supervisor/)
# DPLY_EDGE_BUILD_DOCKER_USER=www-data
# DPLY_PROVISION_EDGE_BUILD_DOCKER=true   # on control-plane workers only
```

## 7. Smoke test

1. Enable `FEATURE_SURFACE_EDGE=true`
2. Create an Edge site from git (static/SSG repo) — choose **Dply Edge (managed)** on the create form
3. Confirm deployment reaches **live** in site workspace
4. Open `{slug}.on-dply.site` — Worker serves R2 artifacts
5. Redeploy → new immutable prefix; KV pointer updates

---

## BYO mode (customer Cloudflare account)

Operators connect their own Cloudflare account when creating an Edge site (**Your Cloudflare account**) or from **Settings → Server providers**.

### Required API token scopes (Account)

- Workers Scripts → Edit
- Workers KV Storage → Edit
- Workers R2 Storage → Edit

DNS Edit is optional but useful when the same credential also automates DNS.

### 1. Connect Cloudflare in dply

From **Edge → Create** (BYO mode) or **Settings → Server providers**, add a Cloudflare API token. Edge-capable tokens are validated for KV + R2 list access when connecting from the Edge create flow.

### 2. Bootstrap R2 + KV in the customer account

```bash
php artisan dply:edge:bootstrap-org <credential_ulid> \
  --account-id=<cloudflare_account_id> \
  --zone-name=example.com \
  --bucket=dply-edge-artifacts \
  --r2-access-key=<from R2 dashboard> \
  --r2-secret=<from R2 dashboard>
```

Create R2 S3 keys in the customer dashboard (**R2 → Manage R2 API tokens**) before passing `--r2-access-key` / `--r2-secret`.

### 3. Deploy the Worker to the customer account

```bash
php artisan edge:worker:deploy --credential=<credential_ulid>
```

Wrangler config is generated from the credential's `credentials.edge` metadata (bucket, KV namespace, routes on `--zone-name`).

### 4. Create Edge sites

On **Edge → Create**, choose **Your Cloudflare account** and pick the bootstrapped credential. Default hostnames use `{slug}.{zone-name}` when `--zone-name` was set during bootstrap.

### Billing

BYO Edge sites still incur the dply **platform fee** per live site where applicable, but **Cloudflare delivery usage is billed by Cloudflare directly** — not through dply's metered Edge usage line item (which applies to managed `dply_edge` sites only).

## Troubleshooting

| Symptom | Check |
|---------|--------|
| Build succeeds, publish fails | `dply:edge:doctor --probe` — R2 keys + bucket name |
| 404 on live URL | KV host map — `EdgeHostMapPublisher` writes on publish; verify namespace id |
| Worker 404 | `edge:worker:deploy` + route pattern matches hostname zone |
| Still using fake mode | `DPLY_FAKE_EDGE=false` in production `.env` |

## Related

- [Edge roadmap](edge-roadmap.md)
- [Edge product boundary ADR](adr/edge-product-boundary.md)
- Worker package: [packages/edge-worker/README.md](../packages/edge-worker/README.md)
- Laravel config: `config/edge.php`
