# dply on dply (self-hosting)

dply's control plane (`edge.dply.io`) runs on dply. It is a Laravel **container
app** on Cloudflare Containers, deployed like any customer's. Customer builds
need Docker, which a Cloudflare container cannot run. A separate **builder**
host drains the build queues. Everything that has to outlive one instance is
kept in Postgres, Valkey or R2.

The deploy commands (`dply:self:register`, `dply:self:deploy`) and the builder
host itself have their own docs. This page covers what the app needs from its
runtime to run as N stateless instances.

## Architecture

```
                 visitors / CLI / webhooks
                          │  https://edge.dply.io
                          ▼
            ┌──────────────────────────────┐
            │  Cloudflare Worker (the app's │  TLS, CF-Connecting-IP,
            │  own, from EdgeContainerDeployer)  X-Forwarded-Proto/Host
            └──────────────┬───────────────┘
                           │ proxies to a container instance
     ┌─────────────────────┼──────────────────────────────┐
     ▼                     ▼                              ▼
┌──────────┐        ┌──────────┐                ┌────────────────────┐
│ web #1…N │        │ web …    │                │ queue workers      │
│ DPLY_RUNTIME=     │          │                │ (DPLY_ROLE=worker) │
│ container│        │          │                │ worker-0 also runs │
│ frankenphp        │          │                │ schedule:work      │
└────┬─────┘        └────┬─────┘                └─────────┬──────────┘
     │ same image, same env, no shared disk               │ queue:work redis
     │                                                     │ --queue=<control queues>
     ├──────────────┬──────────────────┬───────────────────┤
     ▼              ▼                  ▼                   ▼
 dply Postgres   dply Valkey        Cloudflare R2        Cloudflare APIs
 {id}.db.dply.io {id}.cache.dply.io  (edge bucket:        (Workers, KV,
 :5432 TLS/SNI   :6380 TLS/SNI/AUTH   artifacts, build    Containers, D1…)
                 sessions, cache,     logs, _platform/)
                 locks, queues
                    ▲   │ dply-provision, dply-builder
                    │   ▼
            ┌───────────────────────────────┐
            │ builder pool (DOKS `builders`)│  DPLY_RUNTIME=builder
            │ 1–4 pods, one per node, KEDA  │  Docker (dind sidecar),
            │ on queues:dply-provision.     │  git, node, age, pg_dump.
            │ Horizon on the build queues + │  No HTTP, no scheduler.
            │ its own lane. Uploads to R2.  │
            └───────────────────────────────┘
```

The builders are `deploy/builders/` (image, manifests, `apply.sh`; a
standalone-droplet fallback in `bootstrap.sh`); operating them is
`docs/self-hosting-runbook.md` section 7.

## Which process runs where

| Process | Where | `DPLY_RUNTIME` | Queues | Notes |
|---|---|---|---|---|
| HTTP (FrankenPHP) | container web instances | `container` | none | Stateless. Any instance serves any request. |
| `queue:work` × N | container worker instances (`DPLY_ROLE=worker`) | `container` | `dply,default,dply-background,dply-control,dply-manage,probes:worker-1` | From `DplyRuntime::workerQueueList('container')`. |
| `schedule:work` | worker-0 (`DPLY_WORKER_SCHEDULER=1`) | `container` | — | Every event is `onOneServer()`, so a rollout overlap cannot double-run. |
| Horizon | builder | `builder` | `dply-provision` (builds, publishes), `dply-builder` (short build-host jobs) | `config/horizon.php` drops every supervisor whose queues this runtime does not drain. |
| `php artisan migrate --force` | one-shot, run by the self-deploy | `container` | — | Never on boot (below). |

What the builder queues carry:

- **`dply-provision`**: `BuildEdgeSiteJob`, `PublishEdgeDeploymentJob` and `SnapshotEdgeBuildCacheJob`. These run git clone, npm, `docker run`, buildx and wrangler (through the deployer container).
- **`dply-builder`**:
  - `DetectRepositoryRuntimeJob` (git clone).
  - The `docker kill` of a cancelled or superseded build, which `CancelStuckEdgeDeployment` queues when it runs somewhere without Docker. It has its own lane so it does not wait behind the builds it is stopping.
  - Scheduled tasks that need host binaries. In a container, `DplySchedule::onBuildHost()` queues these as `QueuedCommand`s:
    - `dply:edge:warm-build-images` (docker)
    - `dply:edge:check-realtime` (node)
    - `secrets:escrow --source=*` (age, pg_dump)

Fail-safes:

- **A container worker whose `--queue` list names a build queue refuses to work.** `AppServiceProvider::keepContainerWorkersOffBuildQueues` makes the `Looping` event return false, and the worker logs once why it is idle.
- **Host-only commands are skipped in a container with a log line.** This covers `dply:edge:ensure-build-docker` and the Docker probes in `dply:edge:doctor` and `dply:runtime:check` (`DplyRuntime::skipsHostWork()` / `runsBuilds()`).

## Env matrix

Shared by the container and the builder. The values must be **identical**:
`APP_KEY` (encrypted columns, signed URLs, queued closures), `APP_NAME`
(the Redis, cache and Horizon key prefixes derive from it), `REDIS_PREFIX`,
`CACHE_PREFIX`, `HORIZON_PREFIX`, `REDIS_CACHE_DB`, `DB_*`, `REDIS_*`,
`DPLY_EDGE_R2_*`, `DPLY_EDGE_CF_*` and every provider credential. If a prefix
differs, the builder watches different keys, and the detection results and
live build logs handed over through Valkey go missing without an error.

| Variable | container | builder | Why |
|---|---|---|---|
| `DPLY_RUNTIME` | `container` | `builder` | Role (`App\Support\DplyRuntime`). |
| `APP_URL` | `https://edge.dply.io` | `https://edge.dply.io` | Links in mail, webhooks and the CLI. |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | same | |
| `DB_CONNECTION` | `pgsql` | `pgsql` | |
| `DB_HOST` | `{id}.db.dply.io` | same | The gateway routes on SNI, so use the hostname and never an IP. |
| `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `5432` / `app` / `app` / … | same | Injected by the dply database (`EdgeAppDatabase`). `DB_URL=postgresql://app:…@{id}.db.dply.io:5432/app?sslmode=require` works as well. |
| `DB_SSLMODE` | `require` | `require` | The gateway refuses plaintext. |
| `PGGSSENCMODE` | `disable` | `disable` | libpq otherwise probes for Kerberos first, which can stall past the gateway's 15 s handshake deadline. |
| `REDIS_URL` | `rediss://default:<pw>@{id}.cache.dply.io:6380` | same | `rediss://` means TLS. `RedisConnectionTls` sets `scheme=tls` and sends the host as SNI with peer verification (Let's Encrypt). AUTH uses the URL's user:password. Injected by the Valkey resource. |
| `REDIS_CLIENT` | `phpredis` | `phpredis` | |
| `REDIS_PERSISTENT` | `true` | `false` | One TLS handshake per PHP worker, not one per request. |
| `CACHE_STORE` | `redis` | `redis` | Also the `onOneServer` / `withoutOverlapping` mutex. |
| `SESSION_DRIVER` | `redis` | — | Instances share no disk. |
| `QUEUE_CONNECTION` | `redis` | `redis` | Set it explicitly, because the app's own env wins over the `dply` push queue that the deployer would otherwise inject. |
| `LOG_CHANNEL` | `stderr` (the default in a container) | `stderr` or `daily` | A container's stderr is output as JSON lines. |
| `TRUSTED_PROXIES` | `*` (the default in a container) | — | The Worker is the only way in. The visitor IP is taken from `CF-Connecting-IP` (`UseCloudflareClientIp`). |
| `DPLY_EDGE_R2_BUCKET`, `_ACCESS_KEY`, `_SECRET`, `DPLY_EDGE_CF_ACCOUNT_ID` | set | set | Artifacts and build logs. Also the `platform` disk (below). |
| `PLATFORM_DISK_BUCKET` (+ `_KEY`, `_SECRET`, `_ENDPOINT`, `_ROOT`) | optional | optional | A dedicated bucket for the `platform` disk. When unset, a container uses the edge bucket under `_platform/`. |
| `DPLY_SELF_SITE_ID` | set | set | Marks the self site. See "Migrations". Without it, the repo `edge.self.repo` identifies the self site. |
| `DPLY_WORKER_TIMEOUT` | `900` or more | — | The container `queue:work` default is 60 s. Control-plane jobs on `dply` can run for minutes (database transfers, teardown). |
| `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE` | `cache` / `redis` | same | The file driver is per instance, so `artisan down` would take down one instance out of N. |
| `HORIZON_BUILD_MAX_PROCESSES` | — | ≤ vCPUs | Builds saturate cores. |

## Storage: what moved, what stays local

**On R2 (shared, durable):**

| What | Before | Now |
|---|---|---|
| Org icons and site/server logos (`site_assets` disk) | `shared/site-assets` on the VM | The `platform` bucket, prefix `site-assets/`. The same `/site-assets/{path}` URLs are streamed by the `site-assets.stream` route (the framework `serve` route is local-only). |
| Livewire upload staging (org icon upload) | `storage/app/private/livewire-tmp` | The `platform` disk, which becomes the default disk in a container. Livewire uploads to R2 directly with a presigned PUT, so **the bucket's CORS must allow `PUT` from `https://edge.dply.io`**. `Organizations\Settings` reads the upload with `->get()`, which works whichever disk staged it. |
| Build logs | Already on R2 | Unchanged. The builder uploads `build.log` to R2 (`BuildEdgeSiteJob::persistBuildLog`) and mirrors the live tail into Valkey (`EdgeLiveBuildLog`). The web reads R2 or Valkey and never the builder's disk. |
| Build artifacts, SSR/middleware bundles | Already on R2 / Workers for Platforms | Unchanged. |

**Deliberately local (temporary, one job or one request):**

- `storage/app/edge-builds`, `edge-git-cache` and `edge-pkg-store` on the builder: the checkout, git cache and package store. The builder's disk is a cache. A builder that loses it rebuilds from scratch.
- `sys_get_temp_dir()` scratch:
  - Repo detection clones (`RepositoryRuntimePreview`, `GitHubFastDetectFetcher`, `EdgeMonorepoDetector`).
  - The CLI tarball build (`CliPackageTarballBuilder`; the result is cached in Valkey).
  - The compliance export.
  - age identity temp files.
  - The mTLS leaf OpenSSL config.
- `storage/framework/views` and `bootstrap/cache` inside the image.
- `secret_vault.gitops.work_dir` and `secret_vault.reencrypt.checkpoint_disk=local`. These are used by builder-only or one-shot commands.

## Migrations

Migrations run in a single step. **They never run on container boot.** N
instances starting a new release would race each other, and every wake from
sleep would pay for a second framework boot. `EdgeContainerDeployer::secrets()`
always sends `DPLY_MIGRATE_ON_BOOT=0` for the self site
(`EdgeContainerDeployer::isSelfSite()`: `DPLY_SELF_SITE_ID`, else the repo),
whatever the site's migrate-on-boot setting says. That key is merged after the
app's env, so the env cannot override it either. The self-deploy runs
`php artisan migrate --force` once, before the new image takes traffic.

## Health

`/up` is the framework health route. It is not in the `web` group, so it
starts no session and runs no DB query or middleware beyond the global stack.
A test asserts zero queries. The Worker's readiness probe and the self-deploy
both use it (`edge.self.health_path`).

## Known limits

- **Horizon UI.** Horizon does not run in a container (workers use `queue:work`). `/horizon` still renders from Valkey, so it shows the builder's master and the build queues. The container workers are visible in the workspace's queue-worker panel and logs, not in Horizon.
- **The PHP server is detected, not pinned.** `EdgeContainerDockerfile::detectPhpServer()` picks **swoole Octane** for any app that requires `laravel/octane`, and dply-edge does, although nothing in the app uses Octane. Until that is resolved, the self image runs a request lifecycle this codebase was never tested under. **Resolve this before the first self-deploy.** Remove `laravel/octane`, which gives the fpm image. Or add an explicit server override to the generator and pin `frankenphp`. Under fpm, PHP's stderr goes to `/tmp/php-fpm.log`, not the container log (see the ENXIO note in the generator), so FrankenPHP is the better target for stderr logging.
- **Builder pool: jobs that read the build's disk are pinned to its host.** `PublishEdgeDeploymentJob` reads the artifact directory and SSR/middleware sidecars, and `SnapshotEdgeBuildCacheJob` the checkout, from the build host's disk; a cancel's `docker kill` must reach the host running the build. Each builder pod therefore also drains its own lane, `DPLY_EDGE_BUILD_HOST_QUEUE` (`dply-provision-<pod>`, `DplyRuntime::hostQueue()`), and those jobs (and the kill, via `meta.build_queue`) are dispatched there. Unset (one build host) nothing changes. Limits: the lane runs under `supervisor-fast` (900 s timeout), and a pod that dies strands its lane's jobs (the deployment is then reaped by `dply:edge:reap-stuck-builds`).
- **No `age` binaries in the image.** The organization Secrets page (`organizations/{org}/secrets`: generating or rotating the org residency key) and secret escalation/residency decryption call `age` / `age-keygen` inline (`OrgSecretKeyManager` → `AgeEncryptor`). They fail in the container until the image has `age`.
- **No git in the image.** Web-request paths that call git inline degrade: the branch list (`DefaultBranchResolver`, `git ls-remote`) and monorepo detection (`EdgeMonorepoDetector`). Both catch the failure. The branch field stays free text and GitHub detection uses the API fast path. Full detection runs on the builder. Adding `git` to the image restores both.
- **The Valkey tenant must be persistent (Pro).** The cache uses db 1 (`REDIS_CACHE_DB`). The gateway's sleep snapshot `SCAN`s only db 0, so a flex tenant that sleeps would lose the cache, and a sleeping queue store is never acceptable for the control plane.
- **Escrow runs on the builder.** `secrets:escrow --source=platform-env` escrows the builder's `.env`, not the container's secrets. `db-dump` needs `pg_dump` 16+ there.
- **The realtime monitor needs node on the builder** (`edge.realtime.monitor.node`).
- **Cancelling a build from the web waits for the `dply-builder` lane.** A builder that is down means the orphaned `docker run` keeps going until its own timeout.
- **dply/laravel is injected into the image** (the scheduler and queue workers are on). With `DPLY_QUEUE_TOKEN` set, it turns on `PGSQL_ATTR_DISABLE_PREPARES` for every pgsql connection (one round trip per query), and it adds the token-authenticated `/_dply/queue`, `/_dply/schedule` and `/_dply/command` routes to the control plane.
- **`app_ro`, the read-only database login, is not used by the control plane.** Nothing reads through a replica connection. Add a `read` block to `database.connections.pgsql` if that changes.
