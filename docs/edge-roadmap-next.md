# dply Edge roadmap — next phases

Status: **History.** Waves A–E all complete ✅ (Wave E closed 2026-07-09). Kept as a record; remaining work is tracked in `.story/`, not here.

Continuation of [edge-roadmap.md](edge-roadmap.md). Phases 0–4 shipped the core deploy loop, custom domains, and hybrid SSR. The phases below take Edge from "works" to "feels finished" and competitive with Vercel / Netlify / Cloudflare Pages.

Ordering is roughly by dependency, not strict priority. The must-haves that closed the "feels finished" gap were P5, P6, P7 and P9.

> **Doc-drift warning.** This file has repeatedly lagged the code. Wave E was
> listed as "next" for six weeks after both its phases had shipped, and P9b/P9c
> shipped built on *existing* primitives rather than the new tables proposed
> below — so grepping for `edge_notification_rules` / `edge_audit_log` finds
> nothing and makes shipped work look missing. Check the code before believing a
> phase is unbuilt. An earlier "P14" was referenced here as "the single biggest
> acquisition lever" but was never defined anywhere in this document; the
> reference has been removed rather than left dangling.

## Wave A — closed ✅ (2026-05-25)

Scope: P5a + P5b, P6, P7a, P7b — API + in-repo config + deploy lifecycle UX ("feels finished").

| Phase | What landed |
| --- | --- |
| P5a Public API | `/api/v1/edge/*` — sites, deployments (incl. rollback), previews (incl. promote + adhoc create + teardown), domains, aliases, cache purge, usage, logs, config lint. OpenAPI at `public/openapi/edge.json`. `dashboard_url` on site resources. |
| P5b CLI | `packages/dply-cli/` — `dply login`, `link`, `sites`, `edge deploy --prod`, `lint`, `open`, deployments/rollback/promote/previews/domains/aliases/purge/usage/logs`. Token + base URL in `~/.dply/config.json`, per-repo `.dply/site.json`. |
| P6 Repo config | `dply.yaml` / `dply.json` loaded after checkout — build overrides, redirects, rewrites, headers. `EdgeRepoConfigLinter` runs on every deploy (fatal parse errors fail the build) + `POST /api/v1/edge/lint` + `dply edge lint`. Snapshotted on `edge_deployments.repo_config`, applied by the Worker. |
| P7a Rollback + promote UI | Rollback/promote through shared confirm modal; `PromoteEdgePreview` copies preview artifacts before flipping host map. |
| P7b Per-deploy aliases | `edge_deployments.aliases` + `EdgeDeploymentAliasGenerator`; commit + deploy-id aliases in host map; deployment detail Aliases tab. |

**Wave A polish (2026-05-25):** config lint API + CLI, `deploy --prod` prints production URL, `edge open`, OpenAPI spec, Edge API feature tests.

**Carried forward (not Wave A blockers):** OAuth device-flow login (CLI uses `--token` paste), Eloquent API Resources refactor, `edge env` API/CLI, `[build.env_files]` merge, 600/min token rate limit, dedicated Redirects settings tab badge.

## Wave B — complete ✅ (2026-05-25)

Scope: P7c, P8a, P8b, P9a — day-2 table stakes (preview gates, monorepo, build cache, live logs).

| Phase | What landed |
| --- | --- |
| P7c Password-protected previews | `EdgeSiteAccessRule` + `EdgeAccessGate` + `EdgeAccessApiController` (GET/PATCH `/api/v1/edge/sites/{id}/access`). Worker `auth.ts` enforces password / dply-account gating on non-production hostnames; access rule travels in the KV routing payload. Settings UI on the Build Settings tab. |
| P8a Monorepo + repo_root | `EdgeRepoRoot` + `EdgeMonorepoDetector`; stored at `sites.meta.edge.source.repo_root`. Build runner applies root before install/build; GitHub webhook skips redeploy unless push touches `repo_root/**` (or repo config at root). Create + build-settings UI. |
| P8b R2-backed build cache | `EdgeBuildCache` — cache key from lockfile + node version, `.tar.gz` on R2 at `cache/{site_id}/{cache_key}.tar.gz`. Restores before Docker build, snapshots after success, 500 MB per-site LRU cap. Best-effort; failures log to build log only. |
| P9a Live log tail | `EdgeAccessLogReceived` broadcasts on `site.{id}`; `live-request-tail.blade.php` on Logs tab (Echo). `GET /api/v1/edge/sites/{id}/logs?since=…` + `dply edge logs --tail` (HTTP poll, `--interval`, `--window`, `--once`). |

**Wave B follow-ups (nice-to-have, not blockers):** dedicated `EdgeLogs` filter bar + CSV export; CLI WebSocket tail (poll ships today); `EdgeBuildCache` feature tests.

**Wave E (P9b deploy notifications, P9c audit log) — complete ✅ (see below).**

## Wave C — complete ✅ (2026-05-25)

Scope: P11b, P11a, P11c — acquisition: framework presets, import from incumbents, template gallery.

| Phase | What landed |
| --- | --- |
| P11b Framework preset registry | `EdgeFrameworkPreset` DTO + `EdgeFrameworkPresetRegistry` — 12 frameworks (Next, Astro, SvelteKit, Remix, Nuxt, Hono, Vite, Eleventy, Hugo, Jekyll, Gatsby, plain static) with build command, output dir, runtime mode, cache paths, marquer files. Drives Create-flow prefills (replaces the ad-hoc `match`) and is the contract import + template flows lean on. |
| P11a Import wizard | `EdgeImporter` interface + `ImportedEdgeProject` DTO + `NetlifyImporter` / `VercelImporter` / `CloudflarePagesImporter` (all three real implementations, all PAT-based). New `/edge/import` Livewire wizard walks provider → credential → project list → preview → hand-off to `/edge/create` with the build/repo/framework/env-var-count prefilled. Tokens stay in-memory; nothing persists until the user confirms in Create. |
| P11c Template gallery | `EdgeTemplateRegistry` (8 curated starters), `/edge/templates` Livewire gallery with tag filter. "Deploy" button pre-fills `/edge/create` via query params. New Templates + Import buttons on the Edge index header. |

**Wave C remaining (deferred):** real template screenshots (SVG heroes ship today; photo screenshots optional), automatic `dply.yaml` PR-open against the source repo on import.

**Wave C landed (doc catch-up):** DNS swap runbook in `/edge/import` preview + migration docs; env var transfer on import → create (`persistImportedEnvVars`).

## Wave D — complete ✅ (2026-05-25)

Scope: P10a, P10d, P10b, P10c — edge compute moat: middleware, A/B split, deploy hooks, bindings.

| Phase | What landed |
| --- | --- |
| P10a Edge middleware | `EdgeMiddlewareBundler` detects `src/middleware.{ts,js}` (or root), runs `npx esbuild` inside the build image to produce an ESM bundle. `EdgeMiddlewareBundleUploader` ships it to the dispatch namespace as `dply-mw-{site}-{deploy}` with HOST_MAP/ASSETS/identity bindings. Worker `runMiddleware()` dispatches before redirects/R2; 204 + `X-Dply-Middleware: continue` = pass-through, any other response short-circuits. SSR sites skip (OpenNext owns middleware). |
| P10d Split traffic / A-B | `UpdateEdgeSplitTraffic` action stores `meta.edge.split` on the production parent; host map publish includes preview_storage_prefix + percentage + sticky cookie. Worker `applySplitTrafficVariant` hashes IP+UA into a 0–99 bucket on first request, reads sticky cookie on subsequent ones, swaps `storage_prefix` for variant B. Inline UI on each live preview row. |
| P10b Deploy hooks | `edge_deploy_hooks` table + `EdgeDeployHook` model (sha256 token storage, plaintext shown once). Public `POST|GET /hooks/edge/deploy/{token}` (rate-limited, no auth) triggers `RedeployEdgeSite`. UI on Build Settings tab to mint + revoke; last-fired timestamps audited. |
| P10c Bindings declarations | `dply.yaml` now parses a `bindings:` block (`kv` / `r2` / `d1` / `queues`). `EdgeRepoBindingTranslator` converts the snapshot into Cloudflare binding descriptors; both middleware + SSR uploaders inject them into the per-deployment Worker (reserved-name list prevents overwriting HOST_MAP/ASSETS/etc). Read-only panel on Build Settings shows what's declared. |

**Wave D — complete ✅ (2026-07-09).**

- Cron triggers on per-site Workers: **shipped** (this was listed as "needs CF cron API wiring" long after it landed). `dply.yaml` parses `crons:`, `EdgeEffectiveCrons` merges repo + dashboard rows, and both bundle uploaders push them via `EdgeCloudflareClient::setDispatchScriptSchedules()`. UI on the Crons tab.
- Interactive create/attach/detach UI for bindings: **shipped**. `edge-bindings` tab, backed by `EdgeEffectiveBindings` + `EdgeDashboardBindingProvisioner`. wrangler.toml stays authoritative; dashboard rows are additive and lose name collisions.

**Still deferred:** proper service-bindings for middleware → SSR (today they coexist; dispatch overhead is fine for v1). **Phase 3b Custom Hostnames (SSL for SaaS)** shipped on managed `dply_edge` — see `EdgeCustomDomainProvisioner` + `config/edge.php` `custom_hostnames`.

> Bindings and crons only reach a **per-deployment Worker**, which exists for SSR
> sites and for static/hybrid sites shipping a `middleware.ts`. A purely static
> site serves assets straight from R2 with no Worker, so neither applies there —
> the Bindings tab warns about this explicitly.

## Phase 5 — Programmatic surface (CLI + Public API) ✅ (2026-05-24)

**Goal:** every UI action is scriptable. Power users, CI pipelines, and external integrations can drive Edge without the dashboard.

### 5a — Public REST API

- New `routes/api.php` group under `/api/v1/edge/*`, token-scoped (Sanctum personal access tokens with `edge:read` / `edge:write` abilities)
- Resources: `sites`, `deployments`, `domains`, `env`, `aliases`, `bindings`, `logs`, `usage`
- All endpoints return JSON resources; use Eloquent API Resources (`app/Http/Resources/Edge/*`)
- Rate limit per token (default 600/min)
- OpenAPI spec generated to `public/openapi/edge.json`

**Touchpoints:** `app/Http/Controllers/Api/Edge/*`, `app/Http/Resources/Edge/*`, `app/Policies/EdgeSitePolicy.php`, `routes/api.php`

**Acceptance:** `curl -H "Authorization: Bearer …" .../api/v1/edge/sites` lists user's edge sites; `POST /deployments` triggers a deploy; round-trips match the dashboard.

### 5b — `dply` CLI (Node, distributed via npm)

New repo path: `packages/dply-cli/`. Single binary, ships as `npm i -g dply`.

Commands (Edge subset):

| Command | Purpose |
| --- | --- |
| `dply login` | OAuth device-flow to get an API token |
| `dply link` | Link cwd to an Edge site (writes `.dply/site.json`) |
| `dply edge deploy [--prod]` | Trigger a deploy (preview by default) |
| `dply edge logs --tail [--deploy <id>]` | Stream live request + build logs |
| `dply edge env [pull \| push \| set KEY=val \| rm KEY] [--env prod\|preview]` | Manage env vars and secrets |
| `dply edge rollback <deploy-id>` | Re-point production at an existing deploy |
| `dply edge promote <deploy-id>` | Promote a preview to production |
| `dply edge aliases [list \| add \| rm]` | Manage per-deploy aliases |
| `dply edge domains [list \| add \| rm \| verify]` | Custom domains |
| `dply edge purge [--all \| --path /foo]` | Purge cache |
| `dply edge open` | Open the site / dashboard in browser |
| `dply edge whoami` | Show active token + org |

**Touchpoints:** new `packages/dply-cli/`, `app/Http/Controllers/Api/Auth/DeviceFlowController.php`

**Acceptance:** `dply link && dply edge deploy --prod` from a fresh checkout deploys and prints the prod URL.

---

## Phase 6 — `dply.yaml` in-repo config ✅ (2026-05-24)

**Goal:** declarative build + routing config committed to the repo. No more "remember to set this in the dashboard."

> **Format pivot:** v1 ships **YAML** (and JSON fallback) instead of TOML — `symfony/yaml` is already a dep, no composer change. Schema is the same shape; TOML loader can be added later behind a small parser swap.

### Schema (v1)

```yaml
build:
  command: npm run build
  output: dist
  root: apps/web      # monorepo support
  node: "20"

redirects:
  - from: /old/*
    to: /new/:splat
    status: 301

rewrites:
  - from: /api/*
    to: https://api.example.com/:splat

headers:
  - for: /static/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"
```

Functions / bindings (middleware, KV, R2, D1, Queues) are deferred to Phase 10 — they ride on top of this schema once edge compute lands.

### Deliverables

- `app/Services/Edge/Config/EdgeRepoConfigLoader.php` — parses `dply.toml` at build time, validates, returns DTOs
- New models: `EdgeRedirect`, `EdgeRewrite`, `EdgeHeaderRule` (mirrored to DB so Worker has fast lookup; toml is source of truth)
- Worker (`packages/edge-worker`) reads rules from KV and applies before R2 lookup
- Build runner: dashboard env merges with `[build.env_files]`; dashboard wins on conflict
- Lint command: `dply edge lint` (also runs server-side on every deploy, surfaces in build log)

**Touchpoints:** `app/Services/Edge/Config/`, `app/Services/Edge/EdgeBuildRunner.php`, `packages/edge-worker/src/router.ts`

**Acceptance:** committing a `dply.toml` with redirects → next deploy honors them; UI shows "managed by dply.toml" badge on the redirects tab.

---

## Phase 7 — Deploy lifecycle UX ✅ (2026-05-24)

Backend rollback exists; surface it. Plus the missing primitives users expect.

### 7a — Rollback + promote in UI

- New buttons on `app/Livewire/Sites/EdgeSettings.php` and the deployment row partial:
  - **Promote to production** (preview deploys only)
  - **Rollback** (production history list, "make this current")
- Confirmation modal with diff: which commit, which env vars, which deploy ID
- Action: `app/Actions/Edge/RollbackEdgeDeployment.php` (wraps existing `EdgeBackend::repoint()`)

### 7b — Per-deploy stable aliases

Every successful deploy gets a permanent alias: `{commit-sha-short}.{site}.dply.host` and `{deploy-id}.{site}.dply.host`. Outlives the PR.

- Add `aliases jsonb` column to `edge_deployments`
- `EdgeHostMapPublisher` writes alias → deployment mapping into KV
- Worker resolves alias hostnames same as PR previews
- UI: "Aliases" tab on deployment detail with copy buttons

**Touchpoints:** new migration, `app/Services/Edge/EdgeHostMapPublisher.php`, `packages/edge-worker/src/router.ts`

### 7c — Password-protected previews ✅ (2026-05-25, Wave B)

Per-site toggle: gate all non-production hostnames behind a password.

- Settings UI: `EdgeSettings` → "Preview protection" section (off / password / dply-account)
- New table `edge_site_access_rules` (mode, hashed password, allowed_emails)
- Worker reads rule from KV; serves a lightweight HTML auth page; sets signed cookie on success (HS256, 24h)
- Account-mode validates dply session JWT (shared secret with main app)

**Touchpoints:** new migration + model `EdgeSiteAccessRule`, `app/Services/Edge/EdgeAccessGate.php`, `packages/edge-worker/src/auth.ts`

**Acceptance:** flip toggle → preview URL prompts for password; production URL unaffected.

---

## Phase 8 — Monorepo & build cache ✅ (Wave B, 2026-05-25)

### 8a — Root directory + multi-site-per-repo ✅

- `edge_sites.repo_root` column (already partly there? verify)
- Create flow: detect monorepo (presence of `pnpm-workspace.yaml`, `turbo.json`, `nx.json`, `lerna.json`) and offer a subdirectory picker
- Build runner `cd`s into root before running build command
- GitHub webhook: only redeploy if changed files touch `repo_root/**` or `dply.toml`

### 8b — Build cache ✅

- Per-site cache stored in R2 at `{prefix}/cache/{cache-key}.tar.zst`
- Cache key: hash of `package-lock.json`/`pnpm-lock.yaml`/`yarn.lock` + node version + `repo_root`
- Cached paths (framework-aware presets): `node_modules`, `.next/cache`, `.nuxt`, `.astro`, `.svelte-kit/output`, `.cache`, `dist/.cache`
- Restore before build, snapshot after build (background, doesn't block deploy completion)
- Cap: 500 MB per site (config); LRU eviction

**Touchpoints:** `app/Services/Edge/EdgeBuildRunner.php`, new `app/Services/Edge/EdgeBuildCache.php`

**Acceptance:** second build of a Next.js app is ≥3× faster than first.

---

## Phase 9 — Live observability

### 9a — Real-time log tail (UI + CLI) ✅ (2026-05-25, Wave B)

Shipped: Echo live tail on Logs tab, log ingest broadcast, HTTP polling API + CLI tail. Not yet: CSV export, dedicated filter bar component, CLI WebSocket subscription.

- Laravel Reverb / Pusher channel: `edge.site.{id}.logs`
- `EdgeLogIngestController` broadcasts each line as it arrives
- New Livewire component `app/Livewire/Sites/EdgeLogs.php` with a filter bar (level, status, path, deploy)
- CLI `dply edge logs --tail` subscribes via WebSocket
- 7-day retention in `edge_access_logs`; "download last hour" CSV export

### 9b — Deploy notifications (Wave E) ✅ shipped

Shipped **without** the proposed `edge_notification_rules` table — it would have
duplicated the platform's existing notification stack. Instead the Edge events are
registered as ordinary notification event keys, so subscriptions, channels
(email / Slack / Discord / webhook), routing and the site Settings UI all work for
Edge sites with no Edge-specific machinery.

- Event keys in `config/notification_events.php` under the `edge` category:
  `edge.deploy.succeeded`, `edge.deploy.failed`, `edge.domain.verified`,
  `edge.domain.failing`, `edge.usage.over_budget`, `edge.rum.breach`
- Published via `NotificationPublisher` (subject = the `Site`)
- `edge.deploy.succeeded` + publish-phase `edge.deploy.failed` fire from
  `PublishEdgeDeploymentJob`; build-phase `edge.deploy.failed` fires from
  `BuildEdgeSiteJob::markFailed()` with `metadata.phase = build`
- Every publish is best-effort — a dead channel must never fail a good deploy,
  nor mask the build error on the failure path

`edge.deploy.duration_regressed` fires from `EdgeDeployDurationRegression` —
median (not mean) of the last N successful deploys, `min_samples` before it can
alert, all knobs under `config('edge.duration_regression')`.

The proposed per-rule "Test" button needs no work: it presumed the
`edge_notification_rules` table that was never built, and the existing
per-channel test (`ManagesNotificationChannels::sendTest`) already covers
Slack / Discord / email.

### 9c — Audit log (Wave E) ✅ shipped

Shipped on the shared `AuditLog` model (kernel) rather than a new `edge_audit_log`
table — Edge rows are scoped by `subject_type = Site::class`.

- CSV export at `sites.edge.audit.export` (`EdgeAuditLogExportController`)

---

## Phase 10 — Edge compute (the Cloudflare moat)

### 10a — Middleware / functions

User-written code on the request path. Single file per site to start: `src/middleware.ts`.

- Build runner detects middleware entry, bundles with esbuild, uploads as a per-deployment Worker module
- Bindings injected from `dply.toml` `[bindings.*]`
- Routing: middleware runs before R2 lookup; can short-circuit (auth), rewrite (`Request` → `Request`), or pass through
- Local dev: `dply edge dev` (Node-based runner using Miniflare)

**Touchpoints:** `app/Services/Edge/EdgeBuildRunner.php` (middleware bundling), `app/Console/Commands/EdgeWorkerDeployCommand.php` (already exists), `packages/edge-worker/src/middleware-host.ts` (new)

### 10b — Cron triggers + deploy hooks

- `[crons]` section in `dply.toml`: schedule + handler path
- Cloudflare cron triggers via API on the per-site Worker
- Deploy hooks: per-site URL `https://hooks.dply.host/edge/{token}` → triggers redeploy (Sanity, Contentful, Strapi integrations)

### 10c — Bindings UI

Per-site Cloudflare resource management.

- New tab on Edge workspace: "Bindings"
- KV namespaces, R2 buckets, D1 databases, Queues
- Create / attach existing / detach; auto-injects into `dply.toml` on save (writes a PR if repo connected, or notes "add to dply.toml manually")
- Per-binding usage metrics from Cloudflare GraphQL Analytics

**Touchpoints:** `app/Livewire/Sites/EdgeBindings.php`, `app/Services/Edge/EdgeBindingsManager.php`, extend `EdgeCloudflareClient`

### 10d — Split traffic / A-B at edge

- Production "split" config: route N% to a specific preview deployment
- Sticky-by-cookie option (so a user sees consistent variant)
- UI: slider on deployment detail "Send X% of prod traffic here"
- Worker reads split config from KV before R2 lookup; logs variant served per request

**Acceptance:** set 10% split → ~10% of prod traffic hits the preview, sticky per visitor.

---

## Phase 11 — Acquisition: import + templates

### 11a — Import from Vercel / Netlify / Pages

Single biggest churn lever vs incumbents. Three importers, one wizard.

- New flow at `/edge/import/{provider}`
- Vercel: OAuth → list projects → pull `vercel.json`, env vars, build command, framework, domains
- Netlify: PAT input → list sites → pull `netlify.toml`, `_redirects`, `_headers`, env vars, build, domains
- Pages: CF API token (often already linked) → list projects → pull build config, env, custom domains
- Translates source config → `dply.toml`, opens a PR against the user's repo with the file
- Imports env vars directly (secrets stay encrypted)
- Optional: sets up DNS swap instructions per provider

**Touchpoints:** new `app/Services/Edge/Importers/{Vercel,Netlify,Pages}Importer.php`, `app/Livewire/Edge/Import.php`

### 11b — Framework presets + auto-detect

Make the create flow zero-config for the common cases.

- `app/Services/Edge/Frameworks/FrameworkDetector.php` — sniffs `package.json` + lockfile + framework files
- Presets: Next.js, Astro, SvelteKit, Remix, Nuxt, Hono, Vite, Eleventy, Hugo, Jekyll, plain static
- Each preset declares: build command, output dir, node version, runtime mode (static/hybrid/ssr), cache paths
- Surfaced in create flow as "Detected: Astro → Build settings preconfigured"

### 11c — Template gallery

- New page `/edge/templates`: curated list of starter repos with "Deploy with dply" button
- Each template = a metadata file in `database/seeders/EdgeTemplateSeeder.php` (slug, repo URL, framework, description, screenshot)
- Click → if logged out, sign-up wall → fork user's GitHub → create Edge site → first deploy auto-triggers
- Launch with 10 templates (Next blog, Astro docs, SvelteKit SaaS starter, Hono API, Remix shop, plain static portfolio, etc.)
- Public "Deploy" badge generator: README markdown snippet pointing at `/edge/templates/deploy?repo=…`

---

## Phase 12 — Team collaboration on Edge sites

Org membership already exists ([ORG_ROLES_AND_LIMITS.md](ORG_ROLES_AND_LIMITS.md)); add per-site granularity.

- New table `edge_site_members` (site_id, user_id, role)
- Roles: `viewer` (read-only + logs), `deployer` (deploy + env), `admin` (everything incl. members + billing)
- Policy: `EdgeSitePolicy@deploy`, `@manageEnv`, `@manageDomains`, `@manageMembers`
- UI: "Members" tab on Edge workspace
- Invite by email; pending invites table

**Touchpoints:** new migration + model, `app/Policies/EdgeSitePolicy.php`, `app/Livewire/Sites/EdgeMembers.php`

---

## Sequencing recommendation

| Wave | Status | Phases | Why together |
| --- | --- | --- | --- |
| Wave A (closes "feels finished") | **Closed ✅** | 5a + 5b, 6, 7a, 7b | API + config + lifecycle are mutually reinforcing; ship together |
| Wave B (table-stakes polish) | **Complete ✅** | 7c, 8a, 8b, 9a | What evaluators hit on day 2 |
| Wave C (acquisition push) | **Next** | 11a, 11b, 11c | After waves A+B, the product is worth migrating to |
| Wave D (differentiation) | Planning | 10a, 10b, 10c, 10d | Cloudflare moat — needs A+B foundation |
| Wave E (org maturity) | Planning | 9b, 9c, 12 | Required to land paying teams |

## Open questions

- CLI distribution: npm-only, or also Homebrew / curl-installer? (Vercel ships all three.)
- `dply.toml` vs `dply.json` vs `dply.config.ts`? TOML chosen here for human-friendliness; revisit if TS-config users push back.
- Should split-traffic (10d) work for *any* preview, or require an explicit "release candidate" tag? Safety vs flexibility.
- Audit log retention: 90 days default — does compliance/enterprise tier need configurable longer?
- Import-from-Pages (11a) — Pages and dply both sit on Cloudflare. Co-existing or replacing? Affects DNS swap UX.
