# AGENTS.md — product & UI conventions

Conventions for building in dply-edge. For the **codebase map** (module tiers,
where code goes, commands, test suites) see **`CLAUDE.md`**. This file is the
*how it should look and behave* half.

> **Rewritten 2026-09-04 for the Edge-only shape.** The prior version was 82KB
> of accumulated preferences covering the VM/BYO, Cloud, Serverless and
> WordPress product lines removed on 2026-08-22 / 2026-08-25, plus the `/live`
> production mirror, `/infrastructure` hub, `/projects`, `/marketplace`,
> `/roadmap`, `/docs`, `/launches` and Fleet — none of which have routes any
> more. Conventions scoped to those surfaces were cut, not archived. Every
> factual claim below was re-checked against the code during the rewrite;
> several inherited ones turned out to be wrong and are corrected here.

---

## Learned User Preferences

### Chrome — the one layout rule

Every authed product surface, settings page, org page and public marketing
inner page shares one **merged chrome**:

- **one outer `dply-card … p-0`** — not a detached floating hero above a stack
  of cards
- **sand identity header** (`bg-brand-sand/20`, icon + title + short description)
- nested sections as **hairline strips** (`border-b border-brand-ink/10`), not
  stacked nested cards with large `space-y-6` gaps
- **flush** sub-tabs when present
- **Overview-matching skeletons**, not bare spinners
- prefer **compact combined** header/stat strips over stacked duplicate cards

Reach for the shells rather than re-deriving this:

- **`x-profile-shell`** (props title/description/icon; slots
  actions/stats/tabs/footer) — profile and settings pages (hub, security, API
  keys, SSH keys, source control, CLI, notifications), the Edge index,
  status-pages, and create/form pages.
- **`x-organization-shell`** — org workspace pages (overview, settings,
  members, teams, activity, billing, credentials, secrets).

When moving a list page into a shell: keep **one primary Add CTA** (the empty
state owns it when the list is empty; `<x-slot:actions>` only when items
exist — never header + section + empty-state triples), drop inner identity
strips that repeat the page title, and skip busy stats cockpits on empty pages.
Never put developer seed/artisan instructions in a product empty state.

An **empty Edge index** should be a polished onboarding splash — hero + primary
Deploy/Create CTA + capability tiles — inside the same shell chrome, not a lone
icon and a button. A **populated dashboard** stays dead simple: app cards plus
basic stats, Create in the header — not a busy cockpit. The dashboard itself
has **no breadcrumb**.

### Type, tokens, spacing

- Tailwind **v4, CSS-first config** (`@import "tailwindcss"`, `@theme`) for new
  and updated styling.
- **12px floor** for readable UI type. Avoid `text-[11px]` and similar sub-12
  arbitrary sizes for body or labels. `text-xxs` (8px) is a **named micro
  scale for true micro badges only**. Prefer tokens over one-off `text-[Npx]`.
- **`x-outline-link`** for repeated outline-style links and secondary nav
  anchors, instead of duplicating long Tailwind class strings. Its
  **`size="xxs"`** (`h-6 gap-1 rounded-md px-2 text-xs font-semibold`) is the
  **shell-header action size** used beside a page title (Members, Teams) —
  reach for the token rather than `size="sm"` plus
  a stack of `!important` overrides, which is how it was hand-rolled on three
  pages before the token existed. Note the token names the **control, not the
  type**: labels stay `text-xs`.
- When a component composes a shared base plus per-size classes, keep `gap` and
  `font-weight` **in the size arms only** — leaving them in both emits
  competing utilities and lets stylesheet order decide.
- Keep sibling controls (including Copy) a **consistent size** within a
  surface. Do not clip or truncate workspace action labels.

### Header, footer, theme

- **One shared site header** across marketing and authed layouts; only the nav
  differs guest vs signed-in. Prefer
  [Blade UI Kit Blade Icons](https://blade-ui-kit.com/blade-icons) for header
  and primary nav icons, keep the logo prominent, and move overflow links into
  dropdowns rather than crowding the top bar.
- **Shared global footer** (`x-marketing-footer` or equivalent) on app,
  settings and public status layouts — matching marketing/guest, anchored on
  short pages.
- **Dark mode is first-class** across authed surfaces, not isolated pages. The
  theme resolves in `resources/views/partials/theme-head.blade.php` before
  first paint — there must be no light flash before the stored preference
  settles.

### Feedback and dialogs

- **Toasts** for routine success/error feedback. Avoid
  `session()->flash('success')` + Blade-only `$flash_*` patterns for this.
- Notification **badges must stay readable** in light and dark (enough contrast
  on the badge fill — not low-contrast ink on sand).
- **Copy-to-clipboard** shows an explicit *copied* confirmation (inline state
  or toast).
- Never `alert()` or a browser dialog — use **site-styled modals** for
  confirmations. A confirm modal that locks `body` scroll must clear
  `overflow-y-hidden` **on Alpine destroy**, not only on explicit Cancel.
- A confirm modal must live in the **Livewire view**. A layout `modals` slot is
  first-paint only and never opens.
- App **500** pages expose the exception in a **collapsed disclosure** an
  operator can open — not opaque-only.
- The floating **Deploys** drawer shows active and recent org deploys with
  branch and commit context; a fresh kickoff focuses the new run, not the
  previous finished one. **Clear finished** dismisses rows from the UI only —
  it never deletes deploy history from the DB. Opening the drawer stays lean
  (no multi-second N+1).

### Progressive disclosure

- Basics first: one primary workflow leads, advanced tools are secondary.
- **Gated / not-yet-shipped features stay visible** — show them in nav, menus
  and lists with an explicit **"Coming soon"** badge and a teaser preview,
  never silently hidden. Count and insight badges still gate on the *real* live
  flag, not the preview flag. Give coming-soon blocks comfortable spacing.
- Multi-step setup flows **number from 1**, and the journey stays one
  sequential list — do not insert a later step that resets the queue to 0.
- The **features** page should explain how capabilities connect, not list them
  in isolation. Surface subscription and plan limits in the product UI, aligned
  with the billing model below.

### Edge create flow

- **Dead simple by default.** Detect and deploy with as few operator-facing
  settings as possible; advanced knobs stay secondary. Maximise auto-detect
  over teaching copy — no duplicate how-it-works walls in both the form and
  the sidebar.
- Two-column layout (`max-w-7xl`): form in **`x-profile-shell`** plus a sticky
  live summary sidebar (`lg:sticky lg:top-24` +
  `lg:max-h-[calc(100vh-6rem)] lg:overflow-y-auto` — below the site header,
  scrolls with the page then pins, internal scroll when tall).
- Summary cards tied to form fields use **`wire:model.live`** so name and
  header previews update per keystroke (plain `wire:model` only syncs on blur).
- **Git repo pickers must not auto-select the first repo** when the list loads.
  Leave it unselected until the operator picks.
- **Detect branches and tags** from the repo; never assume `main`. Let the
  operator pick a ref (branch or tag) when the default is missing or wrong.
- Auto-detect build command and output dir on a complete repo paste
  (debounced), with **Detect runtime** for manual retry. Filter out
  framework/tooling monorepo roots that will not emit a site `dist/` — prefer
  real app packages and examples. Re-detect when `repo_root` changes.
- A short **what Edge accepts** hint is enough. **Omit empty status/stat bars**
  when detection has nothing to show.
- Prefer **illustrated tile pickers** with capability badges for high-impact
  choices, not plain dropdowns.
- On local / fake-Edge, offer **Load sample app** to prefill a known public
  template and run detection.
- **Empty Cloudflare credential state** = guidance plus an in-modal token add
  (the `AddProviderCredentialModal` pattern) — never an empty dropdown, never a
  forced trip to `/credentials`.
- **Edge customer copy hides Cloudflare internals** — the UI label is
  **Bindings**, not "Cloudflare bindings". Never surface provider jargon
  (Workers connections, binding types, dash URLs) in customer copy.
- Accept a **pasted repository URL** as well as the picker. Multi-step create
  uses an explicit **Next** per step.
- Compute **size tile pickers** must let the operator deselect back to the
  default/lite plan — selecting a larger size must not trap them. Show an
  **estimated price** on the selected size (customer-facing estimate, never
  platform margin).

### Edge workspace IA

- Customer copy says **project** / **app**, not "server" or "site". Breadcrumbs
  omit **"Edge"** (the whole product is Edge). Trail shape is **Dashboard /
  Projects / {app}**; crumbs that include the project name show the **project
  logo**. On a deployment detail page, the crumb points back to **Deploys** and
  the Deploys nav item stays highlighted.
- **Workspace section URLs drop the `edge-` prefix** — `/traffic`, `/logs`,
  `/cache`, `/environment`, `/members`, `/waiting-room`, `/rate-limits`, and
  peers — not `/edge-traffic` and the like. Keep redirects from legacy
  `/edge-*` paths.
- There is **no org-level Compute hub**. Databases and Queues belong **per app**,
  not as org-wide Projects leaves.
- **Cache** is a first-class workspace section (CDN/cache options, tags, purge,
  and stored-copy listing when the edge worker actually writes cache). Settings
  that only take effect after a worker redeploy must say so — do not imply the
  list will fill from Save alone.
- **Overview** = status + URL + actions + shortcuts into dedicated leaves. Not
  a dump of delivery/domains/bindings/traffic/billing, and **no overlapping
  copies** of Traffic, Deploys or other leaf content.
- **Build** splits build/repo config from preview protection, monorepo root and
  deploy hooks — not one overloaded page.
- **Networking** = domains / DNS / routing / edge-routing only. Managed add-ons
  (Bot protection, Rate limits, Forms, Jobs, Waiting room, Snippets, Cache
  tags, Alerts) belong under Access/Site-style groups, not Networking.
- Every section page carries short **what it does / how to use** guidance as a
  **collapsible** "how it works" block. Long examples and `dply.yaml` samples go
  **under Advanced or in modals** so the page stays slim — not bare forms, not
  walls of text.
- **Gate Worker-only nav** (Jobs, Crons, and similar) until the site has
  SSR/worker capability. Do not show those leaves on pure static sites.
- **Alerts** = notification **channel** routing (event subscriptions per
  channel plus thresholds), not thresholds-only.
- **Bot protection** lets operators **generate Turnstile keys in-context** on
  that section — same spirit as in-modal credential add.
- **Hide git-dependent** tabs and sections until a repo is linked — empty
  state, not dead tabs.
- The **shared collapsible sand CLI footer** (`bg-brand-sand/25`) goes on every
  CLI-help surface, never a one-off command block.
- **Coming-soon teasers stay standalone** — do not nest them inside the outer
  card.

### Livewire

- Prefer **Form objects** for forms with roughly 4+ fields over many separate
  `wire:model` props.
- **`stream()`** only during Livewire requests (`Livewire::isLivewireRequest()`),
  never full-page navs — otherwise full-document HTML corrupts the stream.
- For pages tracking unsaved changes, avoid Livewire actions for
  **non-persisting** UI (a preview toggle, say) — that refreshes the snapshot
  and clears dirty state. Use client-side dispatch, and prefer the floating
  **unsaved-changes save bar** (`InteractsWithUnsavedChangesBar`) for
  multi-section settings forms over scattered per-card Save buttons.
- **`#[Computed]`** accessors used as `$this->property` need a
  **`@property-read`** on the component or trait for static analysis and IDEs.
- **Large multi-tab workspaces:** lazy-render the active panel with
  `@if ($tab === '…')`, not `:hidden=` CSS-only. Split blades under
  `partials/{area}/`, move preamble into a `*ViewData` helper merged in
  `render()`, and gate heavy `render()` work by active tab.
- **Edge workspace** = the `Sites/EdgeSettings` shell lazy-mounting per-section
  child components under **`app/Livewire/Sites/Edge/Workspace/*`**, with traits
  in **`app/Livewire/Concerns/Edge/*`**. Mount Form objects (e.g.
  `EdgeBuildSettingsForm`) **only on the active section**; use `wire:init` for
  heavy overview cards (traffic, billing).
- **Do not hydrate the same inventory in both `mount()` and `render()`** —
  `render()` already hydrates per active tab on every request including the
  initial load, so duplicating it in `mount()` doubles every query. Keep only
  session-based hydration (banner dismissal and the like) in `mount()`.
- **Request-memo hot lookups** reused across a single render (billing scans,
  `AssignableNotificationChannels`) so Debugbar does not show the same query
  dozens of times.
- View-data helpers must be **merged in `render()`** so shared blade vars
  (breadcrumbs) reach the view.
- **Lazy-load placeholders are part of the view — change them together.** A
  `#[Lazy]` tab's hand-written skeleton duplicates the real view's title, note,
  sub-tab labels and row shapes, and nothing keeps them in sync. Whenever you
  edit a view's header copy, tab strip or section visibility, update its
  placeholder in the same change, mirroring the same conditionals. A skeleton
  that predicts a shape the real render will not produce is worse than none:
  the page paints one layout then visibly rearranges, which reads as loading
  twice.

### JavaScript

- Avoid shipping huge unsplit bundles — code-split, or serve heavy vendor libs
  from a CDN. Laravel Debug Bar for local profiling.
- **Page-scoped Vite entries** for heavy JS (CodeMirror, passkeys): dedicated
  `*-lazy.js` inputs with `@vite([...])` only on the blades that need them —
  **not** a dynamic `import()` from `app.js`, which puts a global prefetch on
  every page.

---

## Learned Workspace Facts

### Enablement layers — what to flip, and where

Product rollout flags are **retired**. `config/features.php` is an **empty
map** so `FeatureServiceProvider` registers nothing. Edge, status pages,
billing, signups, delivery, deploy contract, and shadow replay are **always
on**. Do not reintroduce Pennant gates for product surfaces. Persisted rows
in `features` / `feature_platform_overrides` from the old catalog are inert
until a flag is registered again.

Match remaining questions to the layer that still exists:

| Question | Layer | Flip via |
|---|---|---|
| Org can add another Edge app on plan? | **Subscription** | `Organization::canCreateOnSurface(QuotaSurface)`, `SubscriptionPlanResolver` |
| Retry deploys / bill Edge usage / digest hours? | **Ops config** (`config/product/dply.php`, `edge.php`) | `DPLY_*` env — not product rollout |

### App shape

- One Laravel app at the repo root, **one PostgreSQL database**. Local dev, CI
  and PHPUnit all use `pgsql`; **SQLite is not used** for app testing.
- **Config layout:** Laravel defaults stay at `config/` root; dply product keys
  live under **`config/product/*`** (dply, edge, subscription, testing_domains,
  cli, …). **`ConfigDirectoryAliases`** maps them back to the old top-level
  keys, so `config('dply')` and friends keep working and callers do not change.
  Watch the nesting when quoting a key: the Edge usage block is reached as
  `dply.edge.*`, not `edge.*`.
- Boolean flags from `.env` in PHP config: avoid `(bool) env(...)` — any
  non-empty string including `"false"` is truthy. Use
  **`filter_var(env(...), FILTER_VALIDATE_BOOLEAN)`**.
- Identity stays in this app (Fortify + OAuth providers); there is no separate
  identity service. Multiple brands/domains use host-based routing here.
- Public marketing waitlist gate = `COMING_SOON` → `config('dply.coming_soon')`
  via `RedirectGuestsToComingSoon`; default off, IP allow-list through
  `coming_soon_allowed_ips`.
- **Platform admin** at `/admin` (gate `can:viewPlatformAdmin`,
  `App\Support\Admin\AdminFeatureFlags`): global and product-line flag pages,
  organizations index/show, audit log, operations, overview, and
  `/admin/connections` for Slack / Discord / Telegram **platform** app
  credentials (DB overlays `.env`, never writes it; secrets are write-never
  after save).
- The product list is **`/dashboard`** (route name `dashboard`, not
  `edge.index`). `/projects` 301s there. Never put the index at `/apps` or
  `/applications` — nginx `location ^~ /app` on the public vhost proxies those
  prefixes to Reverb/Pusher (`Not found.`), so Laravel never sees them. Legacy
  `/edge`, `/apps`, and `/applications` permanently redirect to `/projects`.
- **`Server` is a vestigial owner row** — see `CLAUDE.md`. In the UI it is a
  **project**; workspace URLs use `/projects/{server}/…` (`/servers/…`
  redirects there). Prefer project-scoped paths over exposing `/sites/…` in
  customer-facing crumbs and nav.

### Edge delivery

- **Cloudflare-only.** Either **managed `dply_edge`** (the platform's own
  Cloudflare, configured through `.env` + Artisan —
  `dply:edge:infra:bootstrap`, `edge:worker:deploy`, **not the app UI**) or
  **BYO `org_cloudflare`** (an org credential, picked at Edge create).
- **Delivery hostnames** are flat `{slug}.on-dply.live` on the Edge apex
  (`edge_apex` / `testing_domains.edge` → **`on-dply.live`**, via
  `config/product/testing_domains.php` / `EdgeTestingDomains`) — **not** nested
  `*.on-dply.dply.host/*`, since wildcard SSL on `*.dply.host` does not cover
  `*.*.dply.host`. Older `on-dply.site` / `dply.host` hostnames may still
  route; mint new ones on `on-dply.live`. Legacy hostnames migrate via
  `dply:edge:migrate-hostnames`.
- **Custom Hostnames (SSL for SaaS)** handle managed-delivery custom domains:
  `EdgeCloudflareClient` + `EdgeCustomDomainProvisioner`, polling pending TLS,
  with TLS badges and ownership TXT in the Domains UI. The toggle is
  **`edge.custom_hostnames.enabled`** (default true) — `edge.custom_hostnames`
  itself is the array, not the boolean. BYO `org_cloudflare` keeps
  customer-zone TLS and skips the SaaS path. Needs the zone entitlement plus an
  API token with **Custom Hostnames Edit**.
- `edge:worker:deploy` skips routes for zones not active on Cloudflare; the
  per-route zone comes from the route pattern. `dply:edge:doctor` flags nested
  on-dply routes, zone misalignment and missing usage-analytics credentials.
- **Eligibility** is decided by `EdgeEligibility` after detection, and it is
  **looser than the marketing copy**: alongside the framework presets, its
  `EXTRA_ALLOWED_FRAMEWORKS` explicitly admits **`node_generic` and a bare
  `node` runtime**, and tests assert both stay eligible. So a generic Node repo
  that produces build output is accepted — do not write UI copy promising that
  "any Node app" is rejected. What *is* rejected: PHP/Laravel/Rails/WordPress
  and other non-`node`/`static` runtimes (hard-blocked), and framework/tooling
  monorepo roots (`withastro/astro`, `vercel/next.js`) via
  `EdgeSitePackageHeuristics` with `not_a_site` — there the operator picks an
  app package. Unknown or empty detection stays eligible for a manual static
  site.
- **SSR** is either **hybrid origin-fetch** (Worker static + an external origin
  URL) or **Worker-native SSR** where the platform Cloudflare account supports
  it. `EdgeSsrAvailability::isAvailable()` does **not** validate the API token —
  it only checks that account ID, token and dispatch namespace name are
  non-empty, then leans on a cached failure from a later namespace API call. A
  bad-but-present token therefore reads as available until something actually
  tries the namespace.

### Edge deploy pipeline

- **`BuildEdgeSiteJob` → `PublishEdgeDeploymentJob`** — clone and build in a
  temp dir, then R2 or the fake backend.
- The Build Journey must stream **rich runner output**, not a sparse step list.
  **Render ANSI colors** (never leave raw `[33m` escapes) and keep the live log
  panel **mounted across stream updates**. On split web/worker hosts, mirror
  logs via `EdgeLiveBuildLog` (Redis/Cache), because the local `build.log`
  lives on the worker.
- Horizon on build workers runs as **`www-data`** (`edge.build.docker_user`).
  `EdgeBuildDockerBootstrap::isLocalDesktopEnvironment()` is **Darwin only** —
  Linux workers must install/start Docker (`dply:edge:ensure-build-docker`),
  not show the OrbStack/Desktop hint (that used to fire whenever
  `APP_ENV=local`). A Docker failure must **not** present as a successful
  build.
- **Post-create editable:** build command, output dir, SPA fallback,
  deploy-on-push. Repo, branch and delivery backend are read-only in v1.
- **Deploys** = `RollbackEdgeDeployment` / `PromoteEdgePreview`. Stable aliases
  live on `edge_deployments.aliases` with a deployment **Aliases** tab
  (`{slug}--{sha7|d-*}.{on-dply apex}`).
- **Preview protection** = `edge_site_access_rules` + `EdgeAccessGate`
  (off / password / dply-account), non-production hostnames only.
- **Monorepo** = `repo_root` on the site, a create-flow picker, and a GitHub
  webhook scoped to `repo_root/**`. Container detection and Dockerfile
  generation re-root to that directory; one site is still one app package (a
  Laravel API and a Next app stay two sites).
- **Container apps** (`runtime_mode = container`): PHP/Rails/Node on Cloudflare
  Containers via `Services/Containers/*`. Rollouts are **gradual**
  (zero-downtime); wrangler gets `max_instances + 1` spare while traffic
  `getRandom` stays at the operator setting — `max_instances: 1` alone cannot
  finish an overlapping deploy. Expose the full Cloudflare Containers
  **scaling and routing** surface the product supports (not a single hidden
  default). New deploys apply Container tab settings automatically (no special
  Save-and-redeploy for the next build). Block a new deploy while a rollout is
  still in progress. A **failed or incomplete** deploy must **not** present
  the app as live. Scale-to-zero / cold start must **not** surface as a raw
  visitor **500** — wake or retry cleanly; visitor errors use a **branded**
  page (not raw provider/nginx text), and when the app's debug mode is on,
  surface the app's own error output. An app-origin **HTTP 500** is the app's
  response, not a platform deploy/health failure. Platform **PHP base images**
  (`EdgePhpBaseImage`) are reused/updated so common extensions stay preinstalled
  and builds stay quiet (no duplicate "module already installed" noise).
- **Resources** (container connections / bindings) use a **create-or-attach
  builder** (Laravel Cloud-style), not raw name/host/target fields. Prefer the
  full set of Cloudflare-native Resources the product supports; hide provider
  jargon in customer copy (**never name Upstash / Neon / PlanetScale** — say
  managed Redis / KV / queue / Postgres / MySQL). Choosing a resource type must
  open plan/size/settings before create — not a dead-end tile click. Platform-
  managed static assets **auto-provision** — no operator-facing binding values.
  **Attaching** a queue, Redis, KV, database, or similar must **auto-inject**
  connection env at deploy, surface those injected vars clearly, and put
  Laravel/Rails/PHP usage on an **Implementation** tab (not buried in the
  overview card). Auto-require framework helpers (`laravel-dply` / `dply-rails`)
  when a resource needs them. Resource cards show a **cost estimate**; omit
  cards for disabled capabilities (e.g. no Cache card when cache is off).
  **Managed Redis** and **managed KV** are Upstash-backed (TCP/HTTP Redis; KV
  via platform SDKs) — create/attach on Resources, **bill with markup**, and
  require a payment method when billed. **Managed HTTP queues** use QStash the
  same way. **Managed Postgres** is Neon-backed (ship first); **managed MySQL**
  is PlanetScale-backed and stays **Coming soon** until wired. Create UIs must
  explain plans/compute (not opaque expensive defaults). **Sleep** on a managed
  store **detaches injected env** so the app cannot read/write and is not
  charged — it does **not** tear down the store. Prefixed internal/testing keys
  use the site name plus a `dply` namespace. Durable Objects are **not** a Redis
  stand-in for app cache/session/queue. A **client certificate** is outbound
  identity on **Security**, not a Resource — per-app, with injected env keys
  prefixed (`dply.` / `_dply`) so they never clash with app vars; show usage
  examples (and a demo) in-product. Once the cert is provisioned, drop the
  Deploy CTA. Versions / app-router style controls are deploy mechanics, not
  Resources the operator configures. Queued resource/app deletes must show an
  in-progress / deleting state — not a silent “queued” toast that leaves the
  row looking live.
- **PHP + frontend assets:** when `package.json` has `scripts.build`, detection
  appends the frontend asset step (`FrontendAssetBuild`) beside Composer so the
  stored build command matches the image's Node assets stage (default
  `laravel/laravel` is Composer + Vite, often with no lockfile → `npm install`).
- **Edge Routing** lives at `/edge-routing` (redirects, rewrites, headers);
  legacy `/routing` redirects there.
- Managed-delivery add-ons ship as workspace sections plus a worker host-map
  entry via `EdgeHostMapAddons` — **`dply_edge` only** (BYO shows a
  managed-only banner). That payload carries exactly **turnstile, rate_limit,
  forms, waiting_room, snippets, tags and jobs**. **Alerts is not in it** — it
  is a control-plane notification feature routed through the channel matrix,
  not something the Worker reads. **Waiting room** queues visitors on the
  *same* Edge URL (in-line "You're in line" page plus a session cookie), not a
  separate lobby domain.
- **Promotion is gated**, and the gates are live across the UI, the API and
  direct action entry points — do not add a fourth path that skips them:
  - **Shadow replay** samples production `edge_access_logs` GET/HEAD paths
    and replays them against the preview URL before promote or split.
  - **Preview review** — threaded `edge_preview_comments` plus
    `edge_preview_review_approvals`, PR links, and an optional promote gate
    (`DPLY_EDGE_PREVIEW_REVIEW_*`).
  - **Deploy contract** (always on) —
    `DeployContractEvaluator` runs the policy checks under
    `app/Services/DeployContract/Checks/*` (origin/edge health, env-keys
    subset, shadow-replay pass, review-ready), recorded in
    `deploy_contract_runs` and surfaced on the Edge **Previews** section as a
    Deploy contract card with **Run checks** and **Record waiver**. Promote to
    prod is blocked until it passes.
- **Cancel** = `EdgeSiteCanceller` / `TeardownEdgeSiteJob`.
- **Local Edge dev** needs `DPLY_FAKE_EDGE=true` plus a running `queue:work`;
  preview hostnames via Valet + dnsmasq. See `docs/edge-local-development.md`.

### Edge observability

- **Traffic & analytics** = CDN requests and bandwidth via `EdgeUsageCollector`
  over `Site::edgeUsageHostnames()` with a per-host `analytics_zone`, through
  the Cloudflare GraphQL API. **Build & deploy logs** are CI/build output only,
  not visitor HTTP.
- Prefer **Cloudflare APIs** (GraphQL analytics, Workers/Containers observability)
  for traffic and runtime logs — **not** tunnel-posted live-request ingest via
  `DPLY_EDGE_LOG_INGEST_*`. Do not depend on a public tunnel URL for access-log
  rows to appear.
- Worker **Analytics Engine** (`DPLY_EDGE_CF_ANALYTICS_DATASET`), optional
  **Logpush** (`dply:edge:ensure-logpush`), AE SQL rollup
  (`dply:edge:rollup-analytics-engine`), R2 in usage snapshots, Core Web Vitals
  RUM, and `dply:edge:prune-analytics` for retention remain where they still
  apply.
- Stats count **Worker-routed Edge hostnames only**, never the Laravel app URL.
- **Core Web Vitals need browser RUM beacons** to `/hooks/edge/{site}/vitals`.
  CDN "live requests" alone leave the vitals panel empty.

### Billing

- **Plan tiers + usage** (ruling r-zdescb7y05vp1bxx, 2026-09-16): Free $0,
  Pro $20, Team $49 — monthly only, all allowances in
  `subscription.standard.tiers`. `Organization::billingTier()` reads the tier
  price off the subscription. Sites past the tier's count bill at `edge_cents`;
  seats hard-cap on Pro and bill `extra_seat_cents` on Team; build minutes
  are unlimited on Free (they draw the $5 credit) and bill overage on Pro/Team.
  No trial. Previews consume a usage credit, not a site slot.
- **Free is the starter plan** (parity target: Laravel Cloud starter): unlimited
  apps, seats, and builds (`plans.free.max_edge_apps` and `tiers.free.sites` /
  `seats` / `build_minutes` are null), containers on, scale-to-zero compute, 10
  custom domains, 1 managed queue, short log retention, spending limits/alerts,
  and a **$5 usage credit** (`spending_limit_cents`). `StarterUsageBudget`
  pauses new builds when that credit is used, and `StarterTrafficGate` stops
  managed container traffic, because a free org has no card.
  **Any paid subscription bills overage** (`quotaLimit()` returns null) —
  extra sites bill, so a cap on payers is no revenue lever.
- **Extra sites** (managed `dply_edge` only): static, hybrid, and container
  sites **past** the plan's included count bill at `edge_cents` ($2). Every
  Worker-native SSR site bills at `edge_ssr_cents` ($7) and does not use an
  included slot. Included sites are $0. Container apps also meter **compute**
  (tier compute credit, then overage). BYO `org_cloudflare` pays Cloudflare
  directly: no site fee and no usage meter today.
- Each live site includes **1M requests / 100 GB egress / 5 GB R2 storage**
  plus R2 op allowances (`dply.edge.usage_billing.included_requests_per_site`,
  reduced from an earlier 5M — the config comment explains why), then metered
  **overage** when usage billing is on (`DPLY_EDGE_USAGE_BILLING_ENABLED`,
  `edge_usage_snapshots`, `dply:edge:collect-usage`). Overage = billable units
  × cost-floor rates × **`dply.edge.usage_billing.markup_percent`** (25%, read
  by `EdgeUsageCostCalculator`) into the Stripe `edge_usage` price. **Previews
  stay free.**   Customer-facing compute pricing **never shows platform margin**;
  cost figures are **estimates**, and sleep/savings context belongs beside them
  where helpful. Larger compute tiers should carry a **lower** relative take so
  bigger apps stay competitive.   **Managed Redis, KV, QStash queues, and app databases (Postgres/MySQL)** are
  billed the same way — meter usage, apply markup, never show the platform take
  or the underlying vendor name to customers.
- **Lifecycle:** `StandardSubscriptionCreator` **will not create** a
  subscription for a zero-dollar bill — Stripe rejects $0 subs, so free-zone
  orgs need no card. Note the asymmetry: there is **no automatic cancellation**
  when an existing subscriber's desired state drops back to free, so an org can
  keep a subscription it no longer needs. `onStandardSubscription()` matches
  **any** standard price.
- The model lives in `config/product/subscription.php`, computed by
  `OrganizationBillingStateComputer` → `DesiredBillingState`. Creating past a
  plan cap is **hard-blocked** with a styled upgrade modal or toast, never a
  browser alert.
- Org billing is **one page** (`billing.show`). `/billing/analytics` and
  `/invoices` redirect there. Forecast and invoices live on that page — no
  separate analytics/invoices nav. Copy names the plan (Free/Pro/Team) and
  what it includes; the plan picker sits under the payment method. The **payment method** (add/manage card) is the primary CTA;
  forecast and invoices sit below.
- Billing numbers are customer-facing (`authorize('update', $organization)`),
  so write them from the **payer's** side. The MRR/ARR tiles and competitor
  cost comparison were removed — what remains is **Cost forecast**.

### Settings, credentials, secrets

- `/settings` is a **hub with a shared settings layout** (profile, two-factor,
  orgs, billing).
- **Source control** (`/profile/source-control`) is **Git providers only** —
  OAuth to GitHub, GitLab, Bitbucket. The OAuth redirect is
  `{APP_URL}/auth/{provider}/callback` (`config/services.php`) and the exact
  callback must be registered on the provider's OAuth app. **`DPLY_PUBLIC_APP_URL`
  (a tunnel) is for inbound webhooks only, never OAuth** — keep `APP_URL`
  aligned with the browser URL used to sign in.
- Org **Credentials** is DNS-only today: Cloudflare, Gandi, Namecheap, and
  Vercel DNS. Prefer the reusable **`AddProviderCredentialModal`** /
  `x-add-provider-credential-link` to connect a provider **in-context** rather
  than redirecting to settings.
- Org settings have **no SSH or database-credential email toggles** (VM
  leftovers). Do not add them back.
- **Deleting a shared org provider credential requires org admin access**
  (`ProviderCredentialPolicy::delete` / `hasAdminAccess`). Ordinary members may
  view and use it, but must not see Remove.
- Provider **auth failures** must be prominent and force reauth or a new token.
  Health-check stored tokens **continuously**, not only on save; surface
  *Can't connect* up front and block the action rather than failing mid-job.
- **API tokens** are org-scoped with granular abilities from
  `config/product/api_token_permissions.php`. `DPLY_API_TOKENS_REQUIRE_PAID_PLAN`
  optionally gates **creating** tokens on Pro; deployer members get a reduced
  ability set.
- **Programmatic access must mirror site membership, not just the org.** MCP
  site list/deploy/logs still enforce it through **`SiteApiAccess`** — org
  admins bypass, everyone else must match their UI workspace role. Known drift:
  the Edge REST base controller currently scopes by **organization only**, so
  `/api/v1/edge/*` is more permissive than the UI it mirrors. Do not widen it
  further, and prefer `SiteApiAccess` when touching that path.
- Control-plane **outbound GETs** (hybrid origin healthchecks and the like) go
  through **`PublicOutboundUrl`**, which blocks private/loopback/link-local/metadata
  targets and does not follow redirects onto internal ones.
- **Secret-reveal** surfaces require **update**, not view.
- Org **Secrets** is key custody plus external stores (Vault, AWS SM, Doppler)
  and site-linked write-never secrets an operator pastes (single key or a bulk
  `.env`; comments, headers and `${VAR}` are fine) that inject on the next
  deploy. **Container env stays on dply secrets** — do not make Cloudflare
  Secrets Store the primary store. On the Environment step, **Paste or link
  secrets** previews key names only, skips already-linked keys, and the
  **Linked secrets** list matches env-variable rows (mono key, masked value,
  Unlink), collapsible and grouped by note. Environment editors also accept
  pasted `.env` blocks and auto-render rows. Saving env changes should open the
  same **redeploy** confirmation modal Resources uses — not silent apply.
- **Residency** is the org **age** key for secrets moved out of `.env`:
  *dply-managed* stores both halves (`dply_identity` wrapped with `APP_KEY`;
  the UI never shows the private identity), *customer-held* means dply cannot
  decrypt. Operators can **Rotate key** (new customer-held, identity shown
  once) or **Revert to dply-managed**. Escrowed site secrets stay locked to the
  old recipient until re-moved; shared vault secrets use the platform key and
  are unaffected.
- **Profile, 2FA and OAuth-linked accounts** stay user-scoped. Every user has
  an org, auto-created as `"<name>'s Workspace"`; a first team auto-creates on
  org registration; **site creation requires an org**.

### Notifications

- Channels and event routing are configurable at **organization**, **user** and
  **team** level, with bulk assignment where the UI supports it.
- Types are `TYPE_*` consts on **`NotificationChannel`** with `match` arms in
  **both** `sendTest()` and `sendOperationalMessage()`. Adding a type means
  both arms — a test-only arm silently never delivers, which is what
  `mobile_app` still does — plus the three Livewire surfaces
  (`ManagesNotificationChannels`, `CreatesNotificationChannelInline`,
  `BulkNotificationAssignments`). Share per-type field logic through a
  `Builds<Provider>ChannelInput` concern rather than writing a fourth copy.
- **Intercom / PagerDuty / Microsoft Teams are reimplemented in-repo**
  (2026-08-14), not the `laravel-notification-channels/*` packages — Intercom
  caps at Laravel 9, PagerDuty at 12, and Teams' package targets the retired
  Office 365 connector. The public APIs mirror the packages so their docs still
  apply. They live under `app/Modules/Notifications/Channels/<Provider>/`.
- Credentials are **per-channel** in the encrypted `config` blob, never
  app-level, so each org reaches its own workspace. `services.*` keys exist only
  as a fallback for `$user->notify()` with no channel row.
- **PagerDuty pages humans.** `DeliversToPagerDuty` defaults to **silence**
  (`pagerDutySeverity()` returns null) and only incident-shaped notifications
  opt in — do not blanket-apply it the way the chat-shaped
  `DeliversToIntercom` / `DeliversToMicrosoftTeams` are applied. Alerts carry a
  **derived dedup key** (resource + event, never the event id) so a flapping
  resource updates one incident rather than opening many.
- **Teams = Power Automate Workflows + Adaptive Cards**, *not* the Incoming
  Webhook connector Microsoft retired in May 2026. `MicrosoftTeamsClient`
  refuses `*.webhook.office.com` URLs at type-time, save-time and send-time.
- **`UniversalEventNotification` deliberately has no provider leg** — the
  routing resolver already fanned the event out to subscribed channels, so a
  leg there double-delivers.
- Alerts wire `edge.*` events through the same site notification-channel and
  subscription matrix.

### CLI

- **`packages/dply-cli/`**, published as `@dply/cli`, hosted at
  `/cli/install.sh`, `/cli/dply-cli.tgz`, `/cli/version.json`
  (`DPLY_CLI_INSTALL_METHOD=tarball` until the npm package). Tarball built by
  `CliPackageTarballBuilder`. **`install.sh` must work locally** even when a
  prebuilt tarball 404s (build-on-demand or npm fallback) — never leave an
  operator blocked on a missing `dply-cli.tgz`.
- Default origin from `config/product/cli.php` `default_base_url`
  (`APP_URL` / `DPLY_CLI_DEFAULT_BASE_URL`). Keep the install host and the
  device-login API host aligned.
- **Device-flow `dply login`** opens a browser and drops into an interactive
  shell after auth. Bare `dply` is command mode with autocomplete, shortcuts
  and smart empty states; `menu` or Enter opens interactive browse (menus
  accept numbers or typed commands); pasting `dply …` inside the shell strips
  the prefix. `dply auth refresh` re-approves scopes. **Settings → CLI**
  (`/profile/cli`) manages CLI sessions.
- Link file is **`.dply/site.json`** for `dply link` / `dply deploy`.

### Testing

- Dply-owned testing and preview zones live in
  `config/product/testing_domains.php` (`TestingDomains`), **not** a `.env`
  list — adding a zone is a code change. Edge uses the **`on-dply.live`** apex;
  tests (`APP_ENV=testing`) use local `*.test` apexes so the suite never talks
  to a public zone. Edge delivery resolves through `EdgeTestingDomains`, which
  falls back to `TestingDomains::edge()`.
- Procedural Pest tests behind Pennant gates need **`usesFeatures()`** in
  `tests/Pest.php` — `WithFeatures` only works when the PHPUnit class sets
  `$features`.
- For routing sub-tabs, an HTTP GET with `?tab=…` does not activate the panel
  on a full-page Livewire component — use
  `Livewire::withQueryParams(['tab' => '…'])`. Lazy-tab tests may need the tab
  setter called before asserting panel content.
- On very large views, assign via `$component->instance()->property` instead of
  `->set()` when a full re-render is unnecessary — it avoids PHPUnit OOM.
- Pest helpers use `app()->bind()`, not `$this->app`. `TestCase::setUp()`
  resets `set_time_limit(0)`, `phpunit.xml` sets `memory_limit=1G`, and
  `TestCase::tearDown` calls `gc_collect_cycles()` — batch Pest runs OOM at
  512MB after many Livewire bootstraps.
- When a sync dispatch must actually run, opt out of the global `Queue::fake()`
  with `Queue::getFacadeRoot()->except([JobClass::class])`.

### Known leftovers from the cut

Not conventions — traps. These are places where the code still carries the old
shape, tracked as tickets rather than fixed here:

- `App\Enums\QuotaSurface` still has a `Site` case. Nothing but an orphaned
  non-Edge row can land on it.
- `docs/edge-roadmap.md` and `docs/edge-roadmap-next.md` are **completed
  history** through 2026-07-09, and they predate the cut. They carry their own
  doc-drift warning. Do not read them as a forward plan.
- `organizations.email_database_credentials_enabled` (and similar SSH-email
  leftovers) may still exist on the model; the org-settings toggles are gone.
