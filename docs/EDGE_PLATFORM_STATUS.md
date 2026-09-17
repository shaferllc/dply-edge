---
title: "Platform build status (2026-09-16)"
slug: edge-platform-status
category: "Internal"
order: 999
description: "What the feat/edge-platform branch adds, what has been verified, what hasn't, and the setup it needs before it can run for real."
---

# Platform build status

Branch `feat/edge-platform`. One feature per commit.

## What was built

| Area | What |
|---|---|
| Billing | Free / Pro $20 / Team $49 plans + usage; existing per-site subscriptions auto-move on sync |
| Containers | `container` runtime: PHP (Laravel, Symfony), Ruby (Rails, Sinatra), Node servers (Express, Nest, Fastify, Koa) on Cloudflare Containers |
| Container build | Repo Dockerfile or generated one; `wrangler deploy --dispatch-namespace` in `docker/edge-container-deployer` |
| Container ops | Container tab (size, max instances, sleep, jurisdiction, migrations, scheduler), rollback = rebuild commit, post-deploy health check, generated APP_KEY / SECRET_KEY_BASE |
| Queues | `dply/laravel` + `dply-rails` drivers; Worker consumes Cloudflare Queues and POSTs batches to `/_dply/queue` |
| Scheduler | Cron Triggers → `/_dply/schedule` → artisan command / rake task |
| Databases | Projects → Databases: D1 create / query / attach / delete |
| Queues UI | Projects → Queues: create, backlog, send test, attach, delete |
| Metering | Per-second container compute (containersUsageAdaptiveGroups), D1 + Queues usage; billed on Pro/Team |
| Surfaces | Pricing page + estimator, templates (Laravel, Rails, Express), API + CLI (`dply db`, `dply queues`) |

## Verified

- Unit and feature tests for every commit (Pest, vitest, Laravel/Rails driver smoke tests).
- Locally with Docker 29 (OrbStack): `docker build` from inside the Node deployer image via the host socket; `wrangler dev` accepts the generated container Worker config, builds the image and starts.

## Not verified against a real Cloudflare account

No platform API token was available, so none of this has touched Cloudflare:

1. **Containers inside a Workers for Platforms dispatch namespace.** Wrangler supports it; Cloudflare's docs don't mention it. The whole routing design depends on it. Run `spikes/cf-container/run.sh`.
2. **Raw TCP from a container to Postgres/MySQL** (port 5432/3306). Also in the spike.
3. **Queue consumers on dispatch-namespace scripts** (`queues.consumers` in the generated wrangler config).
4. **Container application naming** (`dply-ctr-<site>…`), which the usage collector matches against `GET /containers/applications`.
5. **GraphQL dataset/field names** for container, D1 and Queues usage, taken from Cloudflare's docs pages.
6. **`--secrets-file` + `--containers-rollout immediate`** on a real deploy.
7. **The generated FrankenPHP / Rails / Node images** building a real app end to end.

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

- Deleting a container site removes its Worker script, but not Cloudflare's container application.
- A queue can have only one consumer. Attaching it to two container projects fails the second deploy.
- Free plan: requests/egress past the allowance aren't billed or throttled; an existing site can still be switched to SSR.
- Container logs aren't in the dashboard yet (Cloudflare's container logs only).
- Full suite on this branch: 1165 passed, 15 failed, 5 skipped. All 15 fail identically on `main` (verified by checking `main` out and rerunning them): AdminDashboardTest (6 feature-flag tests), BillingApiTest billing flag, ContainerProviderCredentialsTest (2), CredentialTest provider grouping, EdgeCreatePageTest / EdgeIndexTest / EdgeNavLinkTest "surface edge inactive", EdgeDeploymentDetailPageTest promote diff, EdgePreviewReviewHubTest approval.
