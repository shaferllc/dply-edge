---
title: "Platform build status (2026-09-16)"
slug: edge-platform-status
category: "Internal"
order: 999
description: "What the feat/edge-platform branch adds, what has been verified, what hasn't, and the setup it needs before it can run for real."
---

# Platform build status

> **Blocking before any of this runs for real:** the spike. Docker is up; it
> needs a Cloudflare API token and any reachable Postgres:
>
>     cd spikes/cf-container
>     CLOUDFLARE_ACCOUNT_ID=7b04f7af0455bad4f97632f4f490f5de \
>     CLOUDFLARE_API_TOKEN=… DATABASE_URL=postgres://… ./run.sh
>
> If "containers in a dispatch namespace" or "TCP to Postgres" fails, the
> routing or database design changes (see below).

Branch `feat/edge-platform`. One feature per commit.

## What was built

| Area | What |
|---|---|
| Billing | Free / Pro $20 / Team $49 plans + usage; existing per-site subscriptions auto-move on sync |
| Containers | `container` runtime: PHP (Laravel, Symfony), Ruby (Rails, Sinatra), Node servers (Express, Nest, Fastify, Koa) on Cloudflare Containers |
| Container build | Repo Dockerfile or generated one; `wrangler deploy --dispatch-namespace` in `docker/edge-container-deployer` |
| Container ops | Container tab (size, min/max instances, sleep, jurisdiction, migrations, scheduler), rollback = rebuild commit, post-deploy health check, generated APP_KEY / SECRET_KEY_BASE |
| Queues | `dply/laravel` + `dply-rails` drivers; Worker consumes Cloudflare Queues and POSTs batches to `/_dply/queue` |
| Scheduler | Cron Triggers → `/_dply/schedule` → artisan command / rake task; with queue workers, `schedule:work` in `worker-0` instead (the app can sleep) |
| Queue workers | Always-on `queue:work` instances (`EdgeQueueWorkers`): Redis (dply Valkey) or database queue, autoscaling every 10 s on backlog and oldest-job wait, worker groups per queue set, pause/resume, failed jobs (list/retry/delete), worker logs, status, test job, alerts (`edge.workers.failed_jobs`, `edge.workers.crashing`) |
| dply databases | Postgres / MySQL / MongoDB pods on the `db` pool behind an active/standby gateway; statistics, backups, point-in-time restore |
| Placement | Apps with dply data run in `DPLY_EDGE_DATA_REGION` (ENAM) by default; every deploy records where it landed and its database round trip, and re-places a far instance |
| Databases | Projects → Databases: D1 create / query / attach / delete |
| Queues UI | Projects → Queues: create, backlog, send test, attach, delete |
| Metering | Per-second container compute (containersUsageAdaptiveGroups), D1 + Queues usage; billed on Pro/Team |
| Surfaces | Pricing page + estimator, templates (Laravel, Rails, Express), API + CLI (`dply db`, `dply queues`) |

## Verified

- Unit and feature tests for every commit (Pest, vitest, Laravel/Rails driver smoke tests).
- Locally with Docker 29 (OrbStack): `docker build` from inside the Node deployer image via the host socket; `wrangler dev` accepts the generated container Worker config, builds the image and starts.
- 2026-09-24, against the live dispatch namespace (`php artisan dply:edge:spike-worker-bindings {site}`, T-016). Upload-level only; no request was routed to the scripts.
  - A dispatch script can host a Durable Object: `migrations: {new_tag, new_sqlite_classes}` is accepted.
  - Another dispatch script can bind that class with `script_name` **plus `dispatch_namespace`**. Without `dispatch_namespace` Cloudflare answers "class in script … does not exist".
  - A `dply-entry.js` main module that does `export * from './worker.js'` and wraps the default export uploads fine.
  - Workflows do not work in Workers for Platforms. A `workflow` binding uploads, but `PUT /workflows/{name}` for a dispatch script returns 500 and the workflow stays not-found. Workflow stays container-only.

- 2026-09-25/26 on waypost (Laravel, Cloudflare Containers, dply Postgres + Valkey), 3,000 `inspire` jobs:

  | setup | drain |
  |---|---|
  | Dallas (WNAM), database queue, 1 process | ~2 jobs/s (~25 min) |
  | Placed in ENAM next to the data | ~6.8 jobs/s |
  | + 3 processes per worker | ~30 jobs/s (97 s) |
  | + dply Valkey queue, 6 processes | ~92 jobs/s (49 s) |
  | + 10 s autoscaling up to 5 workers | ~320 jobs/s peak (19 s) |

  Database round trip from inside the container: ~145 ms from Dallas, ~13 ms from Newark. Worker groups: 1,000 jobs on `high` drained in 15 s while the main group stayed idle. Scale up and down, pause/resume, failed jobs and worker logs all checked live.

## Not verified against a real Cloudflare account

**Worker-site resources (T-018, 2026-09-24).** Uploads were proven by the T-016 spike, and the generated `dply-entry.js` was run under Node with stubbed `fetch` and Durable Object namespaces. No request has yet been routed to a wrapped Worker site. Still to see on a real SSR deploy with a State and an Another app attached:
- a State read and write crossing into `dply-state-{site}`;
- a peer call, with `global_fetch_strictly_public` added (a same-zone fetch otherwise fails with error 1042);
- a Worker reaching dply Valkey through `REDIS_URL` with a socket-capable client (Upstash REST vars were removed with Upstash Redis on 2026-09-24).

**Global queue consumer (T-019, 2026-09-24).** The platform Worker (`packages/edge-worker`, `src/queue.ts`) now has a `queue()` handler, and it has to be redeployed before Worker-site queues run. Unit-tested on both ends (vitest `queue.test.ts`, and the wrapper's `/__dply/queue` path run under Node). Not yet seen on Cloudflare: a message going from a queue to the platform Worker to a Worker site's `queue()` export and being acked.

No platform API token was available, so none of this has touched Cloudflare:

1. **Containers inside a Workers for Platforms dispatch namespace.** Wrangler supports it; Cloudflare's docs don't mention it. The whole routing design depends on it. Run `spikes/cf-container/run.sh`.
2. **Raw TCP from a container to Postgres/MySQL** (port 5432/3306). Also in the spike.
3. **Queue consumers on dispatch-namespace scripts** (`queues.consumers` in the generated wrangler config).
4. **Container application naming** (`dply-ctr-<site>…`), which the usage collector matches against `GET /containers/applications`.
5. **GraphQL dataset/field names** for container, D1 and Queues usage, taken from Cloudflare's docs pages.
6. **`--secrets-file` + `--containers-rollout immediate`** on a real deploy.
7. **The generated FrankenPHP / Rails / Node images** building a real app end to end.
8. **Container logs**: that Workers Logs includes container stdout, and the telemetry query response shape (`events.events[].$metadata.message`) the Container tab parses.
9. **Autoscaling** (2026-09-24): the Worker fills `instance-0…N` in order via the `hasRoom()` RPC on each `App` DO and reads the SDK's internal `inflightRequests` (checked against @cloudflare/containers 0.3.7). Instances below the current minimum override `onActivityExpired` and never sleep. The minimum comes from `min_instances` or from a scaling window. Windows are recurring or one-date, set per time zone, and the Worker's `limits()` picks the one that applies. `dply:edge:warm-containers` (every 5 min) starts them, along with an always-on jobs instance. It has not run against real traffic yet, and the SSR image (`inertia:start-ssr`) has not been built end to end either.

If (1) fails: deploy container Workers as normal scripts with a route or Custom Domain per site, and proxy from the Edge Worker by hostname. Only `EdgeContainerDeployer` (the `--dispatch-namespace` flag) and the `container` branch in `handler.ts` change.

## Setup before it runs for real

**Cloudflare API token** (`DPLY_EDGE_CF_API_TOKEN`) needs, in addition to what Edge already uses:
- Account → Containers: Edit
- Account → Workers Scripts: Edit (+ Workers for Platforms)
- Account → D1: Edit
- Account → Queues: Edit
- Account → Account Analytics: Read (usage collectors)

**Stripe:** run `php artisan dply:billing:provision-stripe`, then set:
- `STRIPE_PRICE_TIER_PRO`, `STRIPE_PRICE_TIER_TEAM`, `STRIPE_PRICE_TEAM_SEAT`
- `STRIPE_PRICE_STANDARD_EDGE_LB_ENDPOINT`

Then run `dply:billing:sync-all --dry-run` before the first real sweep, because it moves existing subscriptions.

**Build workers:** Docker with access to `/var/run/docker.sock`. The deployer image is built on first use.

**Migrations:** `php artisan migrate`. It adds `build_started_at`/`build_seconds`, `edge_container_usage`, `edge_databases`, `edge_queues`, `edge_data_usage`.

**Workers Paid** on the Cloudflare account (required for Containers).

## Known gaps

- A queue can have only one consumer. Attaching it to two container projects fails the second deploy.
- Free plan: the 1M requests / 10 GB egress allowance is advertised but not enforced — usage past it is neither billed nor throttled.
- Previews are billed for everything they use (build minutes, traffic, container compute) but do not take a site slot; the pricing FAQ says so.
- Full suite on this branch: 1165 passed, 15 failed, 5 skipped. All 15 fail identically on `main` (verified by checking `main` out and rerunning them): AdminDashboardTest (6 feature-flag tests), BillingApiTest billing flag, ContainerProviderCredentialsTest (2), CredentialTest provider grouping, EdgeCreatePageTest / EdgeIndexTest / EdgeNavLinkTest "surface edge inactive", EdgeDeploymentDetailPageTest promote diff, EdgePreviewReviewHubTest approval.
