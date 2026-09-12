# CLAUDE.md — codebase map & navigation

dply-edge is a single Laravel app (one PostgreSQL DB) that deploys **Edge
sites** — first-party Netlify-style static/SSG/SSR hosting on Cloudflare R2 +
Workers. It is the Edge-only cut of dply: the VM platform and every other
product line are gone (see the cut note below). This file is the **structural
map**: how the code is organized and where to find things. For product/UI
**conventions** (styling, Livewire patterns, feature-flag layers, billing
model, etc.) see **`AGENTS.md`**. For the *why* of the structure see
**`docs/adr/modular-monolith-structure.md`**.

## The shape: modular monolith

Code is organized into three tiers. The dividing line is **capability vs.
presentation**: domain engines live in modules, the workspace UI that drives
them is the shell, and the hub models everything shares are the kernel.

```
app/
├── Modules/<Domain>/     ← the engines (extracted capabilities)
├── Livewire/  Http/       ← the SHELL: workspace UI + controllers + routes
├── Models/                ← the KERNEL: shared hub models
├── Services/ Jobs/ Actions/ Support/ …  ← shared kernel + infra
```

- **Modules** (`app/Modules/*`, namespace `App\Modules\<Domain>`) — self-contained
  domain engines. Each owns its `Services/`, `Jobs/`, `Console/`, sometimes its
  own `Livewire/`+`Http/`, and is wired by a `<Domain>ServiceProvider` registered
  in `bootstrap/providers.php`.
- **Shell** — `app/Livewire/*` (the edge site **workspace** under
  `Livewire/Sites/Edge/*` plus auth/org/settings/admin pages) and
  `app/Http/Controllers/*`. The shell deliberately *stays* horizontal: workspace
  tabs, lifecycle UI, and routing orchestrate the module engines. Capabilities
  extract *out* of the shell; the shell does not move into modules.
- **Kernel** — `app/Models` hub models (`Site`, `Server`, `Organization`, `User`,
  `SiteBinding`) plus shared `Services/`, `Jobs/`, `Support/`, `Enums/`,
  the `app/Actions` framework (Attributes/Decorators/Concerns), and generic
  `app/Livewire/Concerns/*`. Everything may depend on these.

### The one enforced rule

**Modules must never depend on the presentation shell** (`app/Livewire/*`
concrete components, `app/Http/Controllers/*`). The arrow points UI → engine →
kernel, never the reverse. Enforced by `tests/Unit/ModuleBoundaryTest.php`:

```
php artisan test tests/Unit/ModuleBoundaryTest.php   # ~6s, runs in `composer test`
```

It parses every file under `app/Modules` with nikic/php-parser and fails on any
resolved reference into the shell. Generic `app/Livewire/Concerns/*`,
`app/Livewire/Forms/*` and the base `Controller` count as kernel, so modules may
use them. Imports referenced only from a `{@see}` docblock are ignored — no
runtime coupling (this matches what Deptrac counted).

Known-debt exemptions live in the test's `BASELINE` const; a *new* Module→shell
dependency fails the build. Pay one off and delete its line — a companion test
fails on stale entries so exemptions can't outlive the debt.

(Replaces Deptrac, removed 2026-08-15 — `deptrac.yaml` had been deleted in an
unrelated WIP commit three days earlier, so the boundary was silently unchecked.)

## Module map

| Module | What it owns |
|--------|--------------|
| **Edge** | The product. First-party Netlify-style static/SSG/SSR platform (Cloudflare R2/Workers): build + publish jobs, edge workspace UI, previews, custom domains, access rules, RUM/analytics roll-ups, and repo runtime detection (`Services/RuntimeDetection`, `Services/Manifest` — re-homed from the old Deploy module). |
| **Billing** | Revenue engine — subscriptions, Stripe sync, Edge metering + usage cost calculators. |
| **Notifications** | Notification channels + event dispatch. Also owns the **Laravel notification drivers** under `Channels/<Provider>/` (Intercom, PagerDuty, MicrosoftTeams) registered by `NotificationsServiceProvider`. |
| **Secrets** | Secret vault — residency, escrow, age encryption. |
| **SourceControl** | Git provider OAuth/integration (GitHub/GitLab/Bitbucket). |
| **Providers** | Cloud-provider API clients (Cloudflare, DigitalOcean, …) shared by every module that talks to a provider. |

> **The Edge-only cut (2026-08-25).** The whole VM/server platform and every
> non-Edge product line were removed: TaskRunner (SSH), Deploy, Serverless,
> Database, Cache, Queue, Realtime, Logs, Backups, Certificates, RemoteCli and
> Insights, along with their models, jobs, config, shell UI, routes, views and
> tests. `app/` went 2207 → ~1150 files, models 190 → ~70, routes ~600 → ~196.
> Earlier (2026-08-22) the Cloud PaaS, Imports, Snapshots, Marketplace/Scripts,
> Roadmap, Docs, Blog, Feedback, Referrals, Projects, Scaffold, OpsCopilot,
> Remediations, ConfigRevisions, Ai and Launch modules went the same way.
>
> Things worth knowing about the shape that is left:
>
> - **`Server` is a vestigial owner row.** `CreateEdgeSite` mints one per edge
>   site (`meta.host_kind = dply_edge_delivery`) so the workspace URLs keep the
>   `/servers/{server}/sites/{site}/…` shape they were built on. The model is a
>   thin record — every SSH/provisioning method left with the VM platform.
> - **`/dashboard` is a 302 to `/projects`** (route `edge.index`). One product surface, so the
>   projects list *is* the dashboard. The route name survives so `route('dashboard')`
>   call sites still resolve. `/apps` and `/applications` are reserved for Reverb/Pusher
>   on the vhost (`location ^~ /app`).
> - **Insights was deleted, not retargeted.** Every runner in it SSH'd into a
>   box; none applied to an edge site. Health that survives is StatusPages +
>   `SiteUptimeMonitor` URL checks.
> - **The MCP surface kept only `ListSites` / `GetSite` / `ListServers`** — the
>   env-push, deploy, database and log-shipping tools were all VM-shaped.
> - **Migrations are squashed (2026-09-11).** `database/schema/pgsql-schema.sql`
>   is the baseline; the old 197 migration files were pruned. Two migrations
>   follow the dump: `2026_09_11_000000_drop_removed_product_tables` (61 tables
>   no live code referenced, `DROP … CASCADE`) and
>   `2026_09_11_000001_drop_organization_bundle_entitlements_table`. Existing
>   installs run both on their next `migrate` — **irreversible; back up
>   production first.** Rebuild the dump with `schema:dump` against
>   `dply_edge_testing` using a pg_dump matching the server (16).
> - **`app/Actions` holds five plain classes and nothing else** —
>   `Auth/EnsureLocalDevAdminUser`, `Auth/UnlinkSocialAccount`,
>   `Organizations/EnsureUserHasWorkspaceOrganization`,
>   `Organizations/DeleteOrganizationAction`, `DeployContract/WaiveDeployContractRun`.
>   The generic Actions framework (~375 files) was deleted 2026-09-11; Login,
>   Register, Security, SourceControl and org settings use the five survivors.
> - **Billing is per live site plus metered usage.** No plan tiers: an org with
>   no subscription gets three Edge sites without a card, and any paid
>   subscription lifts the cap (`ManagesOrganizationQuotas::quotaLimit`). The
>   14-day trial and the bundled products (Tracely/Lookout) were removed.
> - **The CLI (`packages/dply-cli`) and API-token catalog are Edge-only.** Token
>   abilities live in `config/product/api_token_permissions.php`; the deployer
>   allowlist must cover `cli.device_flow_role_caps.deployer` (a test guards it).
> - **Owner decisions are recorded as storybloq rulings** (`.story/`). Check
>   them before reopening a settled question.

## Where do I put / find X?

- **An edge site workspace tab or page** → shell (`app/Livewire/Sites/Edge/…`),
  rendered by `SiteWorkspaceController` via `EdgeSettings`. Even if it drives a
  module, the *UI* stays in the shell.
- **Domain business logic, an engine, a queued worker for a capability** → that
  capability's module (`app/Modules/<Domain>/Services|Jobs`).
- **A CLI command for a capability** → the module's `Console/`, registered in its
  ServiceProvider (`$this->commands([...])` guarded by `runningInConsole()`).
- **A hub model** (Site/Server/Organization/User/SiteBinding) → stays in
  `app/Models` (kernel). A leaf model used ~only by one module *may* move into it
  under the ADR rule (≥90% of its references inside that module) — none
  qualified in the 2026-09-11 inventory, because the shell UI references them.
- **A Livewire alias** for a moved full-page/embedded component → register it in the
  module ServiceProvider's `boot()` (`Livewire::component('alias', Class::class)`).
  Guard tests in `tests/Feature/LivewireAliasGuardTest.php` enforce resolution.

## Common commands

```
composer dev            # serve + queue + logs + reverb (local)
composer test           # config:clear + artisan test (Pest/PHPUnit)
composer analyse        # phpstan
composer test           # includes the module-boundary check (tests/Unit/ModuleBoundaryTest.php)
```

### Test suites

`phpunit.xml` declares three suites; `defaultTestSuite` is `Unit,Feature`, so a
bare `artisan test` runs exactly those two.

```
composer test:unit / test:feature      # one suite
composer test:arch                     # tests/Arch — Pest arch rules (~45s)
composer test:all                      # all three
composer test:parallel / test:coverage / test:profile
```

The `Modules` suite went with the Edge cut (every module-local test file was
TaskRunner's) and the `App` suite with the Actions framework.

**The suite is green as of 2026-09-11** (~1140 tests, 5–7 min). `composer test`
disables Composer's 300s process timeout for that reason — without it the run
is killed midway and looks like a failure.

**Tests use their own database, `dply_edge_testing`** (phpunit.xml, and the
`TestCase` guard refuses anything else). It used to share `dply_testing` with
the sibling `dply/` and `dply-serverless/` apps, so each project's
`RefreshDatabase` wiped the other's schema.

### Fast local runs (Pest TIA)

[Test Impact Analysis](https://pestphp.com/docs/tia) is configured in
`tests/Pest.php` (`pest()->tia()->locally()`) and records a dependency graph in
`~/.pest/tia/<key>` — `vendor/bin/pest --baseline` prints the path.

```
composer test:tia          # full suite, replayed  — ~30s
composer test:tia:fresh    # re-record the graph   — ~7.5min
```

Two constraints decide whether TIA engages, and both are easy to trip:

- **It only applies to whole runs.** Any `--group` / `--exclude-group` / path
  filter prints *"TIA does not apply to partial runs"* and runs normally. That
  is why `composer test` (which needs `--exclude-group=arch` for CI parity)
  does not benefit — use `test:tia` while iterating.
- **Every collected test must be a Pest test.** One PHPUnit class aborts the
  run with *"Tia mode requires Pest tests"*. `tests/` is 100% Pest as of
  2026-08-16; keep it that way or `test:tia` breaks for everyone.

`locally()` keeps TIA off in CI, per Pest's guidance — `.github/workflows/tests.yml`
must keep running the suite in full.

### Measuring coverage

Last measured **41.6%** of statements (18,417 / 44,232) on 2026-09-11, after the
Edge-only cleanup, with `composer test:coverage:clover`. The older 43.5% figure
predates the cut and is not comparable.

```
composer test:coverage:clover   # ~7min, writes coverage.xml (gitignored)
composer test:coverage          # sequential text table, much slower
```

**TIA and coverage do not combine** — measured on Pest 5.0.5, three ways:

| command | behaviour | time | number |
|---|---|---|---|
| `--tia --coverage-clover` | replays; clover sees only the re-run tests | 1min | **6.0%** ✗ |
| `--tia --coverage` | prints *"recording a coverage baseline"* and re-runs everything, **every time** — never replays | 6–7min | no table under `--parallel` |
| `--no-tia --coverage-clover` | plain full run | 6–7min | **43.2%** ✓ |

So there is no fast path to a coverage number, and **a replay-measured
percentage is not a property of the codebase** — it reports whatever TIA chose
to re-execute, which on a clean tree is essentially the currently-failing
tests. Fixing failures drives it toward 0%; it is not a metric to target.

Also: **`--coverage`'s text table does not render under `--parallel`** at all
(paratest merges worker coverage in the parent and the summary is dropped).
Use `--coverage-clover` when parallel, or drop `--parallel` — though a
sequential full run took 19min here and died on a `Premature end of PHP
process`, so clover is the practical option.

### Architecture tests

`tests/Arch/ArchTest.php` holds Pest arch rules — enums are enums, Concerns are
traits, Jobs implement `ShouldQueue`, Livewire classes extend `Component`,
controllers are suffixed, and no `dd`/`dump`/`shell_exec`/`eval` reaches app
code. Every `ignoring()` there is an inspected exception, commented in place.

They sit in their own suite (not `defaultTestSuite`) because the whole-app scan
costs ~45s and needs 2G — the file raises `memory_limit` itself, since PHPUnit's
`<ini>` setting overrides `php -d`. CI runs them as a separate step.

The **module boundary is not** an arch rule: `tests/Unit/ModuleBoundaryTest.php`
already owns it. Its `BASELINE` is empty since the Edge cut — both entries were
Feedback / Roadmap components, and those modules are gone.

### Test groups

Every test carries a layer group (`unit`, `feature`, `app`) plus domain groups
derived from its filename and directory — `sites`, `edge`, `billing`,
`console`, `livewire`, and ~25 more. The map at the bottom of `tests/Pest.php`
still lists tokens for removed product lines; they simply match nothing. Add a
token there rather than tagging files.

```
php artisan test --group=servers
php artisan test --group=sites --group=deploy        # union
php artisan test --group=billing --exclude-group=livewire
```

Domain groups register only when a `--group` / `--exclude-group` flag is
present — resolving them costs seconds per run, so unfiltered runs skip it.

## Critical do-nots (see memory / AGENTS.md for the rest)

- **Never** `migrate:fresh` / `migrate:reset` / `db:wipe` on any env (incl. testing)
  without explicit permission.
- **There is no SSH here any more.** `SshConnectionFactory`, `TaskRunner` and
  every remote-exec path left with the VM platform. Long work still belongs in
  a queued job and not the render/HTTP path (PHP 30s `max_execution_time`) —
  edge builds already work that way.
- **Livewire single root** — full-page views with multiple top-level roots throw
  "Snapshot missing"; wrap in `<div class="contents">`.
- The user **tests manually in the browser** — don't run the test suite unless asked.
