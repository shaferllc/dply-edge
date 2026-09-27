# Report: Runtime & compute

Pages: `static-and-hybrid`, `server-rendering`, `edge-middleware`, `containers`, `scaling-and-sleep`, `queue-workers`, `scheduled-tasks`, `data-regions`.

## 0. Security findings (read first)

1. **Customer SSR and middleware scripts are bound to platform-wide storage.** `EdgeSsrBundleUploader::bindingsFor()` (app/Modules/Edge/Services/EdgeSsrBundleUploader.php:257-293) and `EdgeMiddlewareBundleUploader::bindingsFor()` (EdgeMiddlewareBundleUploader.php:248-260) give customer-authored code:
   - `HOST_MAP`: the platform routing KV,
   - `ASSETS`: the whole shared R2 bucket (not scoped to the prefix),
   - `EDGE_CACHE`.

   Any SSR or middleware app can read, and probably write, every tenant's routing entries and build artifacts. That includes origin auth secrets and access-token secrets stored in host-map entries. This looks cross-tenant. The docs do not mention these bindings. **L** (scope with a proxy binding/service that enforces the site's prefix, or signed R2 reads; never hand the raw namespace to tenant code).
2. **Middleware fails open.**
   - A bundle failure (esbuild error, or a bundle over 2 MB) is logged and the deploy goes live without middleware (EdgeBuildRunner.php:~590, EdgeMiddlewareBundler.php:28/96).
   - A runtime throw passes the request through (packages/edge-worker/src/handler.ts:977).
   - A site that uses middleware as an auth gate silently loses it. The docs carry a WARNING. Suggested fix: fail the deploy when a detected middleware file doesn't bundle (**S**), and consider failing closed at runtime or offering it as an option (**M**).
3. **SSR 503 leaks internals to visitors.** `ssrUnavailable()` (handler.ts:~1109) appends the raw exception message ("Worker dispatch binding missing — platform Worker was deployed without DISPATCHER.") to a public response. Log the detail and show visitors a branded page instead. **S**.

## 1. Mismatches

- **Scheduler card copy is wrong.** queue-workers-card.blade.php:258 says "A Cron Trigger calls the app every minute, which keeps it from sleeping". The Worker actually wakes the app only when a task is due (`cronDue`, EdgeContainerDeployer.php:1436-1446). **S**
- **The Crons section is written for middleware only.** crons.blade.php:5-14 has "Handler field is reserved for later" and "needs middleware…". For container apps the handler *is* the artisan command or rake task (EdgeContainerDeployer::cronHandlers, packages/laravel-dply/src/ScheduleController.php:26). **S**: branch the copy on `runtime_mode`.
- **Schedules past five are dropped silently.** `array_slice($crons, 0, 5)` (EdgeContainerDeployer.php:1505) cuts extras with no warning, and `schedule:run` takes one of the five slots. The Crons UI does not validate the count. SSR and middleware uploads pass every schedule to Cloudflare, and a sixth fails with only a Log::warning. **S**: validate at most 5 in Crons.php and show the count.
- **SSR crons do nothing for framework builds.** The Crons copy says crons call `scheduled()` in "middleware (or SSR) Worker". OpenNext, Astro, SvelteKit and Remix output don't export `scheduled`, and the wrapper only forwards it when it exists (EdgeWorkerEntryWrapper.php:159). Documented as-is. **M**: generate a default `scheduled` in the wrapper, or hide Crons for SSR.
- **Stale internal doc: docs/EDGE_CONTAINERS.md.**
  - It says "sleep after 10 idle minutes". Code defaults to 5m (config edge.php:169).
  - It says "Migrations run on boot". The default is off (EdgeContainerSettings.php:100).
  - It says images use "FrankenPHP". The default PHP server is php-fpm behind nginx (on a FrankenPHP base image), and Octane gives Swoole or RoadRunner (EdgeContainerDockerfile::detectPhpServer).
  - It says "basic, up to 5 instances". The Flex plan is 1 instance.
  - The "128 KB payload" limit is not in code.
  - **S**: delete it or point it at /docs/containers.
- **Broken doc links from the workspace.** `docSlug` values `edge-containers` (container.blade.php:4), `edge-crons` (crons.blade.php:4) and `edge-jobs` (jobs.blade.php:4) aren't in nav.json. **S**: map them to `containers`, `scheduled-tasks` and `resources/queues`.
- **Container tab tips are stale.** "Queues: install dply/laravel or dply-rails and add a queue under Jobs" (container.blade.php:14). dply/laravel is auto-injected unless the app ships its own Dockerfile (EdgeContainerDeployer::needsLaravelPackage), and queues now come from **Add resource**. The Container tab's migrate/scheduler help also differs from the Overview sheet. **S**
- **Three naming schemes for the same sizes.**
  - The App sheet says Lite/Flex/Small/Medium/Large/XL (Resources.php:2990-2997).
  - The Container tab (container.blade.php:59) and the pricing page (pricing.blade.php:96-104) show raw keys: `basic`, `standard-1`…
  - The queue-workers cost line shows raw keys.
  - "Flex" also names Valkey sizes and is Laravel Cloud's class name.
  - The create-flow plans Flex/Small/Medium share names with sizes but also set instances, sleep and scheduler.
  - **S**: use the labels everywhere, with the key as secondary text.
- **Two places edit the same settings, and each can do things the other can't.** The Overview **App** sheet has size, instances, sleep, region, rollout, sticky and scheduler, and saves as you change it. The **Container** sidebar section duplicates most of that and is the only place for **Min instances**, **Scaling windows** and **Keep the jobs instance awake**, behind a **Save and redeploy** button. Customers won't find always-on from the App card. **M**: move min instances and windows into the sheet, or retire the Container form.
- **Paused copy.** The paused container response is plain text, "This app is paused. The workspace usage credit is used up." (handler.ts:685, EdgeContainerDeployer.php worker `fetch`). It isn't branded, which AGENTS.md requires. The cause is a trial spending limit or no plan, not "usage credit", and the text is shown to visitors, not the owner. **S**
- **Hybrid 5xx masking.** For non-idempotent requests, an origin's own 500 is replaced by the failover 503 (handler.ts:1360-1368), so a POST validation error page never reaches the visitor. That is at odds with the AGENTS rule "an app-origin HTTP 500 is the app's response". Documented as a NOTE. **S**: only fail over on network errors or on 502/503/504 from the origin.
- **No clean URLs.** `/about` looks up key `about` and never tries `about/index.html` or `about.html` (normalizeRequestPath, handler.ts:344). There's no trailing-slash redirect either. Astro, Hugo and Eleventy links without a trailing slash 404 unless SPA fallback is on, in which case they return index.html with a 200, which is worse. Netlify, Vercel and Pages all handle this. Documented as IMPORTANT. **S/M**: try `path/index.html`, then `path.html`, or 308 to `path/`.
- **Non-index HTML is cached for an hour.** `about.html` gets `max-age=3600` (handler.ts:473, EdgeArtifactPublisher.php:327), so a deploy can take an hour to reach returning visitors on those pages. Only `*/index.html` revalidates. **S**: treat every `.html` as must-revalidate.
- **The PHP instance floor isn't enforced.** `EdgeContainerSettings::minimumInstanceType()` is only used for request capacity. A PHP app can be put on Lite (256 MB) from the App sheet and will crash. The OOM auto-bump then raises it one size and redeploys (CheckEdgeContainerHealthJob.php:84), which changes the bill without asking. **S**: hide or disable Lite for PHP apps, and Small-below for Octane.
- **The "Instances" help promises a spare that isn't there.** It says "Queues run on these same instances". With **Run jobs on their own instance** on, they don't. Minor.
- **The changelog and the code disagree on re-placement.** changelog.md:84 says "restarted once". The code tries up to twice (`REPLACE_ATTEMPTS = 2`, EdgeContainerDeployer.php:51). compliance.md names DigitalOcean while AGENTS.md says never to name vendors in customer copy. The data-regions page says "New York" only.
- **The org "Edge data region" setting is misleading.** The copy (organizations/settings.blade.php:218-231) implies it sets where the org's data lives. It only applies to resources auto-created from repo bindings (EdgeBindingsAutoResolver.php:129), and the EU jurisdiction only to R2. It doesn't affect dply Postgres or Valkey, container placement, or resources created in the builder. **S**: reword, or make it the default for everything.
- **"EU only" doesn't keep data in the EU.** The Container and App sheet copy says "Use this when data has to stay in the EU". `DataRegion::forSite` falls back to the default region (nyc3) when no EU gateway region exists, so an EU-only app's dply database and Valkey sit in New York. This is a compliance problem. The page has a WARNING. **S** copy fix now; **L**: EU region (docs/DATA_REGIONS.md option C).
- **Unverified in production**, per docs/EDGE_PLATFORM_STATUS.md:
  - containers inside the dispatch namespace (item 1),
  - WebSocket behaviour across a rollout,
  - cron triggers on dispatch-namespace scripts.

  I documented the code's intent.

## 2. Gaps vs Laravel Cloud

- **Cold starts.**
  - dply: several seconds, with a 45 s port wait and two retries.
  - Cloud: new Flex wakes in under 500 ms.

  Our docs can't promise a number. That is a real UX gap for scale-to-zero PHP.
- **PHP concurrency per instance is very low.**
  - dply: Flex (¼ vCPU, 1 GiB) takes **2** concurrent PHP requests. The limit is set by CPU (8 × vCPU), not memory, and Small (4 GiB) takes only 4.
  - Cloud: floor(memory/30 MB), which is about 34 per GiB.

  One slow request ties up half a Flex instance, and autoscaling kicks in almost immediately, which raises cost. Worth revisiting: 128 MB per child, `vcpu*8`. Node and Ruby use a flat 50 with no setting.
- **Workers are less flexible.**
  - dply: Laravel only (`queue:work`), with no custom command and no Symfony or Rails workers. Workers are always the app's size, with no independent worker size. There's no FIFO or ordered queue, no per-queue metrics dashboard (only a point-in-time "Check workers"), and no job duration or throughput charts.
  - Cloud: managed queues (Flex or Pro), worker clusters with custom commands, and a queue metrics dashboard.
- **No Octane or Inertia SSR toggle.** Octane is auto-detected from composer, not chosen. EDGE_PLATFORM_STATUS says the Inertia SSR image is unbuilt.
- **No compute metrics tab.** There are no CPU or memory graphs for containers, only the hourly memory-peak sampling used for right-sizing and the 15-minute log view.
- **No maintenance-mode guidance and no "Commands" tab.** The only in-app command is **Run now** for the scheduler, plus the database tools.
- **Where dply is ahead:**
  - Scheduled scaling windows are on every paid plan (Cloud limits them to Business and Enterprise).
  - Queue workers can scale to zero.
  - The scheduler wakes the app only when a task is due.
  - Skew protection.
  - EU and FedRAMP jurisdictions.

## 3. Pricing / limits questions

- **Container prices vs Laravel Cloud.** Maximum with all vCPU busy, derived from config/product/dply.php:337-340 and the size-scaled markup in EdgeContainerComputeCost::sizeMarkup:

  | Size | Spec | $/hr | $/mo always on |
  |---|---|---|---|
  | Lite | 1/16 vCPU, 256 MB | 0.0091 | 6.62 |
  | Flex | ¼ vCPU, 1 GiB | 0.0350 | 25.55 |
  | Small | ½ vCPU, 4 GiB | 0.0858 | 62.66 |
  | Medium | 1 vCPU, 6 GiB | 0.1445 | 105.47 |
  | Large | 2 vCPU, 8 GiB | 0.2420 | 176.66 |
  | XL | 4 vCPU, 12 GiB | 0.4331 | 316.15 |

  - A 1 vCPU instance at about $105/mo always on is expensive next to Cloud's Flex and Pro sizes.
  - CPU is billed on use (collected from Cloudflare), so real bills are lower than these numbers.
  - The "up to" framing on the App sheet is correct but reads as scary.
  - The pricing page shows a per-minute price with five decimals, which is hard to compare with Cloud's per-hour pricing.
  - Memory-heavy fixed shapes (Small is ½ vCPU with 4 GiB) force PHP users to buy RAM they don't need to get CPU.
- **Compute credit.** $5 (Pro) or $20 (Team) covers about 142 hours or 571 hours of one always-on Flex instance respectively. It doesn't cover one Flex app plus one always-on worker for a month on Pro. Worth a clear example on the pricing page.
- **Workers are billed at the app's size.** A Medium app's workers each cost up to $105/mo always on. There's no way to run small workers beside a large web app.
- **The no-plan tier doesn't block workers in `allowance()`.** `worker_instances => 0` becomes `max(1, 0) = 1` (EdgeQueueWorkers.php:180). Enforcement relies on the billing pause instead. **S**
- **The trial spending limit ($5) also pauses container traffic.** The copy calls this "usage credit".
- **SSR is $7/app with no included slot on any plan.** Nothing says whether Worker SSR requests and egress also meter under the per-site 1M-request allowance. That belongs to the pricing writer.

## 4. Suggested fixes (smallest first)

- **S**: Fix the scheduler card copy, the Crons container copy and the Container tab tips; map the stale `docSlug`s.
- **S**: Validate at most 5 schedules in Crons and warn when the scheduler takes one.
- **S**: Hide Lite for PHP and below Small for Octane.
- **S**: Use one naming scheme for sizes (labels with the key secondary) on the App sheet, Container tab, pricing page and worker cost line.
- **S**: Make every `.html` must-revalidate. Only fail over on network errors and 502/503/504 from the origin.
- **S**: Show a branded, owner-neutral paused page, and don't leak `ssrUnavailable` details.
- **S**: Reword "EU only" and the org Edge data region so neither promises data residency.
- **S**: Fail the deploy when middleware is detected but doesn't bundle.
- **S/M**: Clean URLs (`path/index.html`, `path.html`, or 308 to `path/`).
- **M**: Put Min instances and scaling windows in the App sheet; retire the duplicate Container form.
- **M**: Let a worker size differ from the app size; raise PHP concurrency per instance.
- **M**: Generate a default `scheduled` export for SSR, or hide Crons for SSR.
- **L**: Scope SSR and middleware bindings per tenant (security item 1).
- **L**: EU data region for dply databases and Valkey.
