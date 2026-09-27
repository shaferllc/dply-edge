# Report: Billing, Guides, Support

Writer for: pricing, free-trial, usage, spending-alerts, invoices, paused-accounts,
guides/*, support, compliance, abuse, changelog. Written 2026-09-26 against
branch `edge/queue-workers` @ ed2e1b52.

Rates below are the customer rate after `markup_percent` (25) where the code
applies it: `EdgeUsageCostCalculator::applyMarkup`, `EdgeDataUsageCost`,
`EdgeAppDatabaseCost::rate`, `EdgeContainerComputeCost::sizeMarkup`. KV,
realtime, Valkey and platform meters carry no markup.

---

## 1. Mismatches

### Billing: UI and copy vs. code

| # | Where | What's wrong |
|---|---|---|
| M1 | `resources/views/livewire/billing/partials/how-billing-works.blade.php:25`; `OrganizationBillingEnforcer.php` `overBudget()` body; `BuildEdgeSiteJob.php:109` | All three tell the user to "end it early on the billing page". **No UI calls `Show::endTrial()`** (`Show.php:342`); grep finds no `wire:click="endTrial"`. A capped trial is stuck paused until day 6. Commit 4c982c31 says "End trial now lifts the $5 cap", so the button was meant to exist. |
| M2 | `show.blade.php:288`, `:328-330` | The cancel copy says "no further charges after that" and "billing just stops". `UsageInvoicer::onSubscriptionDeleted` sends a **final usage invoice**. Also, "Cancel keeps your sites and data" is only true until the period ends: then the org is paused, and its data is purged after 7 days once purge is on. |
| M3 | `show.blade.php:177` ("Printed on every Stripe invoice") | **Invoice email, VAT number, currency and legal details never reach Stripe.** `saveBillingDetails` (`Show.php:96-145`) writes only to `organizations`. `StripeOrganizationTaxIdSync` is dead code with no callers. The currency select has no effect: all charges are USD. |
| M4 | `bill-hero.blade.php:42-58` | Shows "/yr on annual billing (save 20%)" and a **Switch to annual billing** button that dispatches `open-modal 'switch-interval'`. **No such modal exists**, so the button does nothing. Rulings r-zdescb7y05vp1bxx and r-f17p5zgeh120cm5t say monthly only. `subscription.standard.annual_discount_pct = 20` is a leftover. |
| M5 | `show.blade.php:65`, `:254`; `bill-hero.blade.php:28-30` | Stale per-site copy: "Pay per live Edge site plus metered delivery usage. Previews stay free.", "Invoices appear here once a live site is billed", "Based on your live Edge sites. Add a card to bill them". Previews are *not* free: their usage counts. |
| M6 | `show.blade.php:8,16-18,116-124`; `seatCapFromSubscription()` | A **beta org shows "Beta — $0, nothing due"**, but `billingTier()`, `computeFresh()` and the enforcer ignore beta. Ruling r-f17p5zgeh120cm5t says "no free beta". The seat cap is also skipped for beta orgs without a paid plan. |
| M7 | `show.blade.php:39-40` | "Pay as you go / Add a card to bill live sites" is an unreachable, stale state. |
| M8 | `BuildEdgeSiteJob.php:97,109-111` | Stale Free-plan messages: "Upgrade to Pro for more, or wait until the 1st", "pause until the 1st, or upgrade to Pro". Neither applies without a Free tier. |
| M9 | `OrganizationBillingNotice.php:54` | The trial-started email always says "trial of Pro", even for a Team trial. The "paused" email (`:73`) says "The trial … has ended" even when a paid subscription lapsed. |
| M10 | Ruling r-f17p5zgeh120cm5t ("card required at signup") vs. `PlanCheckout` | The trial starts only at Checkout: from the plan picker, or when a no-plan org hits Deploy (`ManagesEdgeDeploy.php:90`). Registration takes no card. The docs describe this as it works. |
| M11 | Ruling ("email… 1 day before the trial ends") vs. `OrganizationBillingEnforcer::run` | Also sends `trial_ending_soon` at ≤3 days. With a 5-day trial, that email fires on day 2 and the 1-day email follows. This is fine, but it isn't in the ruling. |
| M12 | `plan-picker.blade.php:11,67-74`; `Show::getSubscriptionProperty` (`Show.php:147`); `Show::changeTier` (`:295`) | **BLOCKING: a paused org whose subscription has ended can't resubscribe.** `$hasSubscription = (bool) $this->subscription` is true for an ended Cashier subscription, so the picker shows **Switch to Pro/Team** and hides **Choose Pro**. `changeTier` rejects anything not `valid()` ("No active subscription to change."). `subscribeTier` would work, but it's unreachable. paused-accounts.md tells these users to contact support. Fix: `$hasSubscription = $this->subscription?->valid()`. |
| M13 | `OrganizationBillingEnforcer::overBudget`, `BuildEdgeSiteJob::notifyStarterBudget` | **A trial that hits the $5 cap pauses without emailing anyone.** The only signal is `NotificationPublisher` `edge.usage.over_budget`, which reaches only configured notification channels. A new trial org has none, so it pauses silently. `billing_notices['capped']` is recorded but no `OrganizationBillingNotice` is sent. |
| M14 | `Show::stripeInvoiceRows` → Cashier `invoices(false)` (paid only) | Unpaid or open invoices never appear in **Invoices**. A customer with a failed payment can't see what they owe in dply. |

### Pricing page vs. config vs. invoice

| # | Where | What's wrong |
|---|---|---|
| P1 | `pricing.blade.php:84-91` via `ManagedProductCostEstimator::edgeUsageRates()` (`round(…, 2)`) | **Displayed rates are rounded; the invoice uses exact rates.** Requests show $0.63, bill $0.625/M. Egress shows **$0.06**, bills **$0.0625/GB**. R2 storage shows $0.04, bills $0.0375. Class A shows $5.63, bills $5.625. Egress is under-stated on the page by 4%. |
| P2 | `pricing.blade.php:87` | Build-minute overage is hard-coded (`'$0.006 / $0.005'`) instead of read from `build_minute_overage_millicents`. It matches config today, but will drift. |
| P3 | `pricing.blade.php` | The page **omits these rates**: KV, D1 rows and storage, queue operations, realtime connection-minutes (only the message rate is shown), Workers CPU, Durable Objects, customer R2 buckets, Image transformations, and the container egress rate. The FAQ says "Nothing is throttled", but seats (Pro), custom domains, D1/queue counts, realtime per-app connections, concurrency and timeout are hard caps. |
| P4 | `pricing.blade.php:45` | The fallback default for `included_r2_class_a_ops_per_site` is `100_000`; config is `20_000`. It only matters if the key is missing. The doc-comment cites the superseded ruling r-wdxrhbm4ww21hgj3. |
| P5 | `pricing.blade.php:208`, `docs/BILLING_AND_PLANS.md` | "Allowances are per organization, per calendar month". Invoices and allowances use the Stripe billing period (`EdgeOrganizationUsageReader::currentWindow`). Build-minute *gating* (`EdgeBuildMinutes::usedThisMonth`) and the Projects **Usage** page (`livewire/edge/usage.blade.php`) use the calendar month. There are two windows. |
| P6 | `EdgeAppDatabaseCost::monthly()` (×720) vs. `pricing.blade.php:104` (container ×730) | The page uses two different month lengths. |
| P7 | `pricing.blade.php:307` | Claims "backups and point-in-time restore" for managed DBs. PITR billing keys don't exist (config comment only), so I couldn't verify PITR. The databases writer should confirm. |
| P8 | Ruling r-zdescb7y05vp1bxx ("Load balancing stays $8/endpoint") vs. `subscription.php` `edge_lb_endpoint_cents` "Retired" | The ruling and the code disagree. The docs don't mention load balancing. |
| P9 | `subscription.php` tier key `addons` | Never read anywhere (0 references). "Add-ons" are a plan feature in the ruling but aren't enforced. Commit 02b51dda says AI/Browser/Images/Vectorize need a paid plan, which every plan now is. |
| P10 | `DesiredBillingState::usageLineLabel('data')` = "Databases, queues, KV and realtime usage" | This line also carries Valkey, Workers CPU, Durable Objects, customer R2 and Images (`OrganizationBillingStateComputer.php:168`). The customer can't see what drove it. Suggest splitting it. |

### Metering / billing bugs (found while verifying)

| # | Where | Issue |
|---|---|---|
| B1 | `EdgeOrganizationUsageReader::totalsForOrganization` (`MAX(r2_storage_bytes)` across all rows) | `edge_usage_snapshots` has one row per **site** per day, so MAX returns the **single largest site's** storage. That figure is compared with `5 GB × site count`. **Site storage is effectively never billed.** It should be SUM of the per-site MAX. |
| B2 | `UsageInvoicer` (own ponytail note) | Usage collected in the hour before a renewal lands after `invoice.created` and is never billed. The container, D1, KV and platform "yesterday" re-runs (01:40–02:10 UTC) also land after a renewal that happens at night. Late samples for the last day are lost. |
| B3 | `StarterUsageBudget::usedCents` | The trial counts **every** build minute at 1¢ (Pro has no `build_minute_credit_millicents`; the default is 1000). The $5 cap is therefore about 500 build minutes, not the 1,000 the trial advertises. The trial also gets no compute credit, and `estimate($totals, 1, $tier)` gives R2 allowances for 1 site only. Documented as-is in free-trial.md. |
| B4 | `OrganizationBillingEnforcer::run` purge path | If `purge_enabled` is switched on when an org is already past `keep_data_days`, the "deleting" email and the purge happen **in the same run**, with no 2-day notice. Guard: purge only when `billing_notices.deleting` is ≥2 days old. |
| B5 | `UsageAlerts::check` is called only from `SyncOrganizationBillingJob` (daily 02:30 + webhooks + site lifecycle) | Alerts can lag by up to a day. Documented. |
| B6 | Seat changes | Nothing dispatches `SyncOrganizationBillingJob` on member join/leave (`SiteBillingObserver` only). Team extra seats reach Stripe at the nightly sweep. Documented. |
| B7 | `EdgePlatformUsageCollector` header | Workers AI and Browser Rendering usage can't be attributed to an org, so it is **unbilled** and paid by dply. |
| B8 | Valkey `EdgeRedisCost::valkeyCents` ponytail | A mid-month resize prices the whole month at the new class. |

---

## 2. Gaps vs. Laravel Cloud (billing / support docs)

- **No spending limit on paid plans.** Cloud has a hard spending limit per org. dply has only an email alert (`usage_alert_cents`) and never stops paid usage. Beta customers from Cloud will look for this.
- **No tax handling.** No Stripe Tax and no `automatic_tax`. VAT IDs aren't sent to Stripe, and there are no reverse-charge notes on invoices. Cloud documents taxes. EU/UK customers will need a VAT-ID-bearing invoice.
- **No support channel.** No support address, form, SLA or plan-based support tiers. The only contact is the pricing page's `mailto:{mail.from.address}`, which is `hello@example.com` in `.env.example`. Support, abuse and security pages all point to that link. Needs a real `support@`/`abuse@`/`security@` address and a `/security.txt`.
- **No legal pages.** Terms, privacy, DPA, subprocessor list, AUP. The abuse process in abuse.md is policy I had to state without a written AUP. **The owner must confirm** the "disable site / suspend org / notify owner" steps. There's no admin "suspend org for abuse" tool in code. The billing pause is the only suspend mechanism.
- **No compliance posture.** No SOC 2 or other reports, and no SSO/SCIM. compliance.md says so plainly.
- **No refunds or credits policy**, no invoice PDF in-app (Stripe hosted page only), and only the last 12 invoices are listed (`Show::stripeInvoiceRows` limit 12).
- **No "end trial early" or "skip trial" control** (see M1). Cloud lets you upgrade out of a trial.
- **Annual billing** isn't available, while the UI half-offers it (M4).

---

## 3. Pricing / limits questions

1. **Low end is uncompetitive.** With no Free plan and Pro at $20, a single hobby static site costs $20. Netlify Personal is $9 and Vercel Hobby is $0 (non-commercial). This is intentional per ruling r-f17p5zgeh120cm5t, but it's worth knowing (see the comparison below).
2. **Site storage costs 2.5× bucket storage.** Site R2 storage bills $0.0375/GB-mo (cost floor 3¢ plus markup). Customer R2 buckets bill $0.015/GB-mo (Cloudflare list, no markup). The site storage cost floor of 3¢ is 2× Cloudflare's $0.015 list. Class A/B on sites also carry markup; buckets don't. This is moot until B1 is fixed.
3. **Requests cost floor is $0.50/M vs. Cloudflare's $0.30/M.** The config comment itself says ~$0.30/M. With markup, that's $0.625/M. Still far under Vercel's $2/M.
4. **Workers CPU has no allowance at all.** Cloudflare includes 30M CPU-ms per account. Every SSR request pays from the first ms, on top of the $7/SSR-site fee. Small, but it's a second SSR charge customers may not expect.
5. **SSR at $7/site is never covered by the plan.** A Pro customer with 10 Next.js SSR sites pays $20 + $70. Vercel Pro covers unlimited SSR projects in $20/seat.
6. **The Pro seat hard cap of 3** with no paid extra seat forces Team ($49) for a 4-person team. Vercel charges +$20/seat; Netlify Pro has unlimited members.
7. **The trial cap of $5 ≈ 500 build minutes** (B3), and one always-on `basic` container is ~$0.85/day. A trial with a container app plus a database can hit the cap in ~3–4 days and be paused mid-trial with no way to convert early (M1). This is a conversion risk.
8. **Default usage alert = 2× plan price** ($40 / $98). This is reasonable, but it's usage only; the name "Usage alert" is fine.
9. **Enterprise `compute_credit_cents => null`** means "unlimited" in `DesiredBillingState` (billed 0). Fine, since Enterprise is hand-invoiced, but any Enterprise org would show $0 compute.

### Competitive comparison (prices fetched 2026-09-26)

Sources: laravel.com/cloud/docs/pricing.md, vercel.com/pricing and netlify.com/pricing, fetched 2026-09-26. The Laravel Cloud Postgres and egress rates didn't come through the fetch reliably, so they're marked "n/c". dply figures are config arithmetic.

**Shape A: small static marketing site (Astro).** 1 seat, 300k requests, 20 GB egress, 10 deploys × 3 min.

| | Monthly |
|---|---|
| dply | **$20.00**: Pro; everything is inside the allowances |
| Vercel | $0 Hobby (non-commercial only), or $20 Pro |
| Netlify | $9 Personal: 400 credits bandwidth + 60 requests + 150 deploys = 610 of 1,000 |
| Laravel Cloud | Not a static host. Starter $5 with a $5 credit ≈ $5–6 |

**Verdict:** dply is the most expensive option for a commercial static site. We lose this shape to Netlify at less than half the price.

**Shape B: Next.js SSR app.** 3 developers, 5M requests, 200 GB egress, 500 build minutes, ~10 ms CPU per request (50M CPU-ms), 30 deploys.

| | Monthly |
|---|---|
| dply | **$28.00**: Pro $20 + 1 SSR site $7 + Workers CPU 50M × $0.02/M = $1.00. Requests and egress are within the allowances. Builds are within 1,000 minutes. |
| Vercel Pro | 3 × $20 = **$60**. Within 1 TB and 10M requests; invocations are covered by the $20 credit. |
| Netlify Pro | $20 + credits: 200 GB × 20 = 4,000, 5M requests = 1,000, 30 deploys × 15 = 450, plus compute. That's about 5,450 credits against 3,000 included, so 2 × $10 packs ≈ **$40+**. |
| Laravel Cloud | N/A (PHP only) |

**Verdict:** dply wins clearly, up to a 4th developer. The Pro seat cap forces Team at $49 + $7 + CPU = $57, which is still under Vercel's $80.

**Shape C: Laravel app.** Web on `basic` awake 50% with ~10% CPU. 1 queue worker `basic` always on at ~5% CPU. Postgres 0.25 CU awake 50% with 5 GB. Valkey Flex 250 MB awake 50%. 2 seats, 2M requests, 50 GB egress.

- **dply, about $54/month:**
  - Pro plan: $20
  - Container compute ≈ $15.33, less the $5 credit = $10.33:
    - web: memory 1 GiB × 365 h × $0.01125 = $4.11; disk 4 GB × 365 h × $0.0003125 = $0.46; CPU 0.25 × 365 × 0.1 × $0.09 = $0.82
    - worker: memory $8.21, disk $0.91, CPU $0.82
  - Postgres: 0.25 × 365 × $0.1325 = $12.09, plus storage 5 × $0.4375 = $2.19, so $14.28
  - Valkey: 365 × $0.00893 = $3.26
  - Plus the Durable Object in front of each container at $12.50 per million GB-s: at 128 MB, ~164k GB-s for the web's 365 awake hours ≈ $2, and ≈ $4 for the always-on worker. That makes the total about **$54**.
- **Laravel Cloud Starter:** $5 − $5 credit, plus app flex-1gb at 50% ≈ $6–7, a flex-512mb worker always on ≈ $6, Valkey flex-250mb at 50% ≈ $3.5, and Postgres (n/c). That's about $16 plus database. Starter has no worker clusters or autoscaling; on Growth ($20) it's about $36 plus database.
- **Vercel and Netlify:** can't run it.

**Verdict:** dply is somewhat above Cloud Growth for this shape, and about 2× Cloud Starter. A 1 GiB container always on costs $9–26/mo on dply depending on CPU busy-ness (memory alone is $8.21), against a flat ~$12/mo for Cloud's flex-1gb. We're cheaper when idle and dearer when busy. The database is the swing factor: our $0.1325/CU-hr (+25% markup on a Neon-style list price) needs checking against Cloud's serverless Postgres. dply wins on bundled delivery: 10M requests and 500 GB are included. A Laravel customer comparing a single small app will see Cloud as cheaper.

**Where a price looks wrong or uncompetitive:** see P1 (egress under-stated), B1 (site storage unbilled), items 1, 5 and 6 (low end, SSR fee, Pro seat cap), and item 2 (site storage 2.5× bucket).

---

## 4. Suggested fixes

| Fix | Size |
|---|---|
| M1: add an **End trial and start paying** button on the plan picker (the current plan card, while `onTrialPlan()` and a Stripe trial exist) calling `endTrial` | S |
| M2: cancel modal copy: "…no further plan charges. Usage from the final period is invoiced once when it ends. After that, the organization is paused and its data is kept 7 days." | S |
| M3: push `invoice_email` → Stripe `email`, `billing_details` → `invoice_settings.footer` or `address`, and call `StripeOrganizationTaxIdSync` on save. Remove the currency select or disable it with an "USD only" note. Change the panel note until then. | M |
| M4: delete the annual toggle and the annual line from `bill-hero`, and drop `annual_discount_pct` | S |
| M5, M7, M8, M9: replace stale per-site and Free-plan copy. Parameterise the trial email's plan and the paused email's reason. | S |
| M6: decide beta vs. trial; remove the beta "$0" status or make billing honour it | S (decision) |
| P1: show rates to 4 significant figures (or round the config rates to whole cents) so page = invoice | S |
| P2, P3: read the build rate from config; add the missing meters table (reuse the docs pricing tables); fix the "Nothing is throttled" FAQ | S |
| P5: pick one window. Use the billing period for build-minute gating and the Projects usage page. | M |
| P10: split the `data` usage line into Databases / Valkey / KV / D1 & queues / Realtime / Platform | S |
| B1: `SUM` of per-site `MAX(r2_storage_bytes)` (subquery grouped by site_id) | S |
| B2: bill usage on `invoice.created` + delay (or on `invoice.upcoming`) and let the daily re-runs finish first | M |
| B3: give Pro a `build_minute_credit_millicents => 0` for the plan's included minutes, and apply `compute_credit_cents` within the trial cap — or document it (done) | S |
| B4: require the `deleting` notice to be ≥2 days old before purging | S |
| B6: dispatch a billing sync on organization member add/remove | S |
| Support: set a real support/abuse/security address; add terms, privacy and AUP pages; add `/.well-known/security.txt` | M |
| Spending limit on paid plans (Cloud parity): an optional hard cap reusing `StarterTrafficGate` and the pause path | M |
| Internal docs: `docs/BILLING_AND_PLANS.md` still lists a Free plan, `edge_usage` line billing, and a vCPU rate of "$0.020/hour" (actual is $0.072/hr list, $0.09 billed). `docs/EDGE_BILLING.md` says Free and "Settings → Billing". Update or delete both. | S |

---

## 5. Guides (verified by the guides pass)

**Create flow**

- **G1.** The create page has no mode picker, build command or output field (`resources/views/livewire/edge/create.blade.php` step 3). Worker SSR is reachable only at `/projects/create?runtime_mode=ssr` (`app/Modules/Edge/Livewire/Create.php:371`). Nothing switches an existing app to SSR. `ManagesEdgeDeploy.php:164` still says 'Pick "Worker-native SSR"'. **This also affects pricing Shape B:** the $7 SSR price assumes a customer finds that URL. The guides document the workaround.
- **G2.** Next.js detection disagrees with itself:
  - The GitHub fast path returns `out` with no start command (`ManagesEdgeRepoDetection.php:648`), which leads to static (`EdgeSsrDetection.php:25-33`). A non-export Next app then fails with "Build output directory not found: out".
  - The clone path suggests `next start`, which leads to hybrid (`NodeRuntimeDetector.php:182`).
  - The preset registry says SSR (`EdgeFrameworkPresetRegistry.php:40`), but the recommender honours only `hybrid`.
- **G3.** Vercel import marks Nuxt as `ssr` (`VercelImporter.php:212`), but there is no Nuxt SSR profile, so the build fails.
- **G4.** **Import a site** is shown only on a non-empty dashboard (`edge-index-page.blade.php:160`).

**Manifest**

- **G5.** `dply.yaml` has two conflicting schemas. `DplyManifestParser` reads `build:` as a string or list. `Config/EdgeRepoConfigLoader.php:14-37` reads `build:` as a map. A malformed manifest is silently ignored (`RepositoryRuntimePlanComposer.php:108`).

**Container images**

- **G6.** The `EdgeContainerDockerfile.php:13` docblock says the PHP image runs FrankenPHP. `detectPhpServer` (`:647-653`) returns fpm unless Octane is installed. An app on Octane with the FrankenPHP driver is built as Swoole.
- **G7.** `APP_URL` and `ASSET_URL` are persisted once with the first live URL (`EdgeContainerEnvDefaults.php:30-37`). They are never updated when a custom domain is added. The guides tell users to update them by hand. Suggested fix: re-derive them unless the user edited them.
- **G8.** Failures are swallowed by `|| true`: Rails `assets:precompile` (`EdgeContainerDockerfile.php:753`) and PHP `composer dump-autoload` (`:606`).
- **G9.** Rails with no database attached keeps SQLite inside the container, so the data is lost on sleep. `sqliteDefaults` handles Laravel only (`EdgeContainerEnvDefaults.php:27`).
- **G10.** Migrate-on-boot defaults to `false` in `EdgeContainerSettings.php:100` but `true` in `Workspace/Container.php:39`.
- **G11.** The Container tab says to install dply/laravel, but it's auto-injected (`EdgeContainerDeployer.php:157-181`) unless the repo has its own Dockerfile. It's unclear whether `dply-rails` is published, and the Rails guide tells users to add it. **Owner: confirm.**
- **G12.** `EdgeSsrAvailability` gates container delivery with a confusing "dispatch namespace" error (`ManagesEdgeDeploy.php:132`).

**Messages and copy**

- **G13.** The container-gate 503 page and the Realtime sheet still speak of a "usage credit" and "until a card is on the account". See `packages/edge-worker/src/handler.ts:685` and `EdgeContainerDeployer.php:1417`. With card-up-front trials, both are stale.
- **G14.** `docs/edge-ssr.md` is stale: it says "only Next.js" and "env vars will arrive". Code now supports Astro, SvelteKit, Remix and Keel, with env as `secret_text`.
- **G15.** The database sheet can show managed Postgres, MySQL and MongoDB as "Coming soon" (`database.blade.php:24-25`), while the pricing page sells them.
- **G16.** Queue workers are Laravel-only (`artisan queue:work`). There is no Sidekiq or Solid Queue equivalent.

**Guide gaps vs. Laravel Cloud**

- No deploy or release commands; migrations run only on boot or manually.
- No customer console or command runner.
- No Horizon.
- No persistent disk.
- `_redirects` in the build output isn't read.
- No build settings on the create page.
- No SvelteKit, Nuxt, Remix or Symfony guides.
- No guided database import beyond uploading a dump.

**Fixes**

| Item | Fix | Size |
|---|---|---|
| G1 | Add a Mode select (Static / Hybrid / SSR / Container) plus build and output fields to create step 3 | M |
| G2 | Make the fast path match the clone path: `next` without `output: export` means SSR (or container) | S |
| G5 | Merge onto one parser, or reject a mixed schema loudly | M |
| G7 | Re-derive `APP_URL` on domain attach unless user-set | S |
| G8 | Drop `\|\| true` on `assets:precompile` | S |
| G10 | Use one default | S |
| G13 and G15 | Copy fixes | S |

### Pages that need owner sign-off (policy I had to state)

- **support.md:** email-only support via the pricing page's **Email us** link, no SLA, "as quickly as we can on business days", no support tiers. There is no support address in code. `mail.from.address` defaults to `hello@example.com`.
- **abuse.md:** the review → disable site → suspend org → notify owner → reply process, and "phishing and malware first". Nothing in code implements an abuse suspension.
- **compliance.md:** the subprocessor list (Cloudflare, DigitalOcean, Stripe, Git providers), "no certifications / no BAA / no DPA / no SSO", and the vulnerability-disclosure wording.
- **changelog.md "Security" entry:** it states that resources are now bound only by the owning org, and that one hostname is allowed per site. That publicly implies the earlier cross-tenant exposure (commit 02b51dda). Decide whether to disclose it or drop the entry.
- **paused-accounts.md:** says automatic deletion is off today and could be turned on. Confirm the owner wants that stated.
