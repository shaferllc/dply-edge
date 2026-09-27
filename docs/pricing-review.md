# Pricing review (internal)

Written 2026-09-27 for the owner's review. It covers the whole pricing model in one place. Numbers come from `config/product/subscription.php`, `config/product/dply.php` (`edge.usage_billing`), `App\Modules\Billing\Support\UsagePrice`, `EdgeValkey`, `EdgeSizeLadder`, `EdgeAppDatabase` and `php artisan dply:billing:price-table plans|limits|fair-use|rates|sizes`, run on that date at the default **30% margin**. Rulings: r-2zxevg4sj675qn1m (model), r-bc0k0cta8e50x8vr (fair use), r-jnv0r3qf1xk49kmc (30% margin, $0.06 bandwidth, Starter limits, 30-day purge), r-f17p5zgeh120cm5t (trial). This document changed no product code when written. **Update, same day:** items 3, 6, 11–16 of §8 were then fixed in code and docs; each is marked **Fixed** below, and §3, §5, §6 and §7 are updated to match. **Update 2 (honest prices):** items 1, 5, 7 and 8 were fixed by measuring what dply's own infrastructure costs (§9, `dply.unit_costs`, `php artisan dply:billing:unit-costs`), making database, Valkey, build and realtime prices fixed customer prices set from those costs, and adding a break-even (§10).

**Conventions used below.** A month is 720 h (2,592,000 s), the same as `EdgeAppDatabaseCost::monthly()`. "Cost" is the value in config, and it is not always a real provider cost (see §3). "Gross margin" is the bill minus that config cost. It leaves out Stripe fees (3.6% + $0.30 per invoice: card 2.9% + 30¢ plus Stripe Billing 0.7%) and fixed platform costs (§10): the Workers Paid and Workers for Platforms base fees, the database and Valkey cluster, build hosts and support.

---

## 1. The model in one paragraph

Each organization pays a monthly **plan fee**: Starter $5, Pro $20 or Team $49. Enterprise is sales-led. Team also pays **$5 for each seat past 10**. **Usage** is billed in arrears for each closed Stripe period. Every meter is priced at **cost × (1 + margin/100)**, and the margin is one setting (30%); the exceptions are the **fixed-price** meters (bandwidth $0.06/GB, and since §9 build time, databases, Valkey and realtime), whose configured value is the price. The plan's **included usage credit** ($5 / $20 / $49) then comes off the usage total, and usage can never go below $0. Sites and apps are unlimited, with a hidden fair-use cap on apps. Nothing metered is throttled on a paid plan. Owners get alert emails at 50%, 80% and 100% of a soft limit instead. There is no free plan. New orgs get a 5-day trial of the plan they choose, with a card up front and a $5 usage cap. Trial usage is never invoiced.

### How a bill is computed

```
renewal invoice (Stripe, per monthly period)
├─ in advance:  plan fee                                  subscription.standard.tiers.<plan>.price_cents
├─ in advance:  extra seats × $5 (Team only, >10 seats)   extra_seat_cents; view-only members are free
└─ in arrears (UsageInvoicer::bill, on invoice.created for the period just ended):
     for each category in DesiredBillingState::USAGE_KEYS
       delivery | builds | compute | databases | valkey | data | realtime | platform
       line = round( Σ meter_qty × cost × (1 + margin/100) )   UsagePrice::cents(), nearest cent, once per category
     credit line = −min(plan usage_credit_cents, Σ lines)       "Included usage credit"
     → usage owed = max(0, Σ lines − credit)
total = plan fee + extra seats + usage owed        (DesiredBillingState::monthlyTotalCents)
```

- **When usage is collected.** Collectors run hourly, and yesterday is re-collected between 01:30 and 02:10 UTC (`app/Console/Scheduling/DplySchedule.php`). At renewal, the period's last day is re-collected before billing (`UsageInvoicer::collectLastDay`). Valkey and realtime collect increments, so a late sample bills in the next period.
- **Cancellation.** It triggers a final invoice for the partial period, with the **full** credit because the fee was paid in advance (`UsageInvoicer::onSubscriptionDeleted`).
- **Enterprise.** `tierOf()` returns null, so no usage is invoiced by code. Enterprise is billed by hand.
- **Trial.** It is capped at **$5 of usage at customer price, with no credit** (`subscription.standard.trial.spending_limit_cents`, `StarterUsageBudget`). At 80%, the next build sends a notification. At 100%, new builds stop, and the hourly `dply:billing:enforce` pauses the org. The `StarterTrafficGate` KV flag stops container traffic. Trial usage is **never billed** (`UsageInvoicer.php:65`, `:109`).
- **Paid-plan alerts.** `UsageAlerts` emails owners when the usage owed after the credit passes 50%, 80% or 100% of `organizations.usage_alert_cents`. The default is 2× the plan price: $10, $40 or $98. Each threshold goes out once per period. The check runs on billing sync, daily at 02:30 and after Stripe webhooks, so an alert can arrive up to a day late. Alerts never stop anything.
- **No plan.** When a trial ends unpaid or a subscription lapses, the org is paused. Its data is deleted after `keep_data_days` = **30** while `purge_enabled` is on (env `DPLY_BILLING_PURGE_ENABLED`, default **true**).
- **BYO Cloudflare.** Sites on the customer's own Cloudflare account are not metered.

---

## 2. Plans

| | Starter | Pro | Team | Enterprise |
|---|---|---|---|---|
| Price | $5/mo | $20/mo | $49/mo | Contract |
| Seats included | 1 (hard cap) | 3 (hard cap) | 10 | Custom |
| Extra seat | — | — | $5/mo each | Custom |
| Included usage credit | $5/mo | $20/mo | $49/mo (**Fixed**: was $50, above the fee) | n/a (usage billed by hand) |
| Credit ÷ fee | 100% | 100% | 100% | — |
| Sites / apps | Unlimited | Unlimited | Unlimited | Unlimited |
| Fair-use app cap (hidden, previews excluded) | 25 | 250 | 1,000 | none |
| Concurrent builds | 1 | 2 | 5 | 10 |
| Build timeout | 20 min | 45 min | 60 min | 120 min |
| Custom domains (per org) | 3 | 20 | 100 | unlimited |
| Container apps | yes, 1 instance per app | yes, autoscaling | yes, autoscaling | yes |
| Queue workers per app | 1, no autoscale, 0 extra groups | 5, autoscale, 2 groups | 10, autoscale, 4 groups | unlimited, 4 groups |
| SQL databases (D1) | 2 | 10 | 50 | unlimited |
| Queues | 2 | 10 | 50 | unlimited |
| Queue consumer concurrency / batch wait | 5 / 2 s | 10 / 2 s | 50 / 1 s | unlimited / 0 s |
| Realtime connections per app | 200 | 1,000 | 5,000 | 20,000 |
| Audit log | no | no | yes | yes |
| SSR, add-ons | yes | yes | yes | yes |
| AI / Browser / Images / Vectorize bindings | paid plan only, not on trial (`EdgeContainerConnections::PAID_ONLY`) | same | same | same |
| Usage-alert default | $10 | $40 | $98 | — |

Trial: 5 days, the plan chosen at checkout (`trial.tier` = `pro` applies only to a card-less trial or the first-deploy shortcut), card required, $5 cap. Eligibility is checked per org, per owner and per card fingerprint (see `docs/site/free-trial.md`). `none` (no plan) has every allowance at 0.

---

## 3. Every meter

Columns: the config key under `dply.edge.usage_billing`, what it measures, the unit, how and when it is collected, dply's **cost**, where that cost comes from, and the customer price at 20%, **30% (current)** and 40%.

Source codes: **CF** = Cloudflare list price, verified 2026-09-27 at the URL. **dply** = a dply-set figure, not a provider list price. **back-out** = derived from an older customer price divided by an older margin.

CF sources: Workers <https://developers.cloudflare.com/workers/platform/pricing/>, R2 <https://developers.cloudflare.com/r2/pricing/>, Containers <https://developers.cloudflare.com/containers/pricing/>, Durable Objects <https://developers.cloudflare.com/durable-objects/platform/pricing/>, Images <https://developers.cloudflare.com/images/pricing/>.

| Meter (key) | Measures / unit | Collected | Cost | Source | @20% | **@30%** | @40% |
|---|---|---|---|---|---|---|---|
| Requests (`requests_…_per_million`) | every request a site's Worker answers, cache hits included; per M | zone analytics, hourly + next day | $0.30 | CF Workers | $0.36 | **$0.39** | $0.42 |
| Bandwidth (`egress_…_per_gb`) | bytes to visitors (`edgeResponseBytes`), including container apps' responses; per GB | hourly + next day | $0 real (CF Workers/R2 egress is free); config holds the **price** | **fixed price**: listed in `fixed_price_meters`, so the margin is not added (**Fixed**, §8 item 3) | $0.06 | **$0.06** | $0.06 |
| Site storage (`r2_storage_…`) | peak published build output; per GB-month | hourly + next day | $0.015 | CF R2 | $0.018 | **$0.0195** | $0.021 |
| Site storage writes (`r2_class_a_…`) | Class A ops (publishing); per M | hourly + next day | $4.50 | CF R2 | $5.40 | **$5.85** | $6.30 |
| Site storage reads (`r2_class_b_…`) | Class B ops (cache misses); per M | hourly + next day | $0.36 | CF R2 | $0.432 | **$0.468** | $0.504 |
| Build time (`build_…_per_minute`) | build container time, per second; per minute | on build finish (`EdgeDeployment.build_seconds`) | real ≈ $0.0037 (§9) | **fixed price** (§9) | $0.005 | **$0.005** | $0.005 |
| vCPU (`container_vcpu_…`) | **active** CPU seconds (`cpuTimeSec`); per vCPU-s | container analytics, hourly + next day | $0.000020 | CF Containers | $0.000024 | **$0.000026** | $0.000028 |
| Memory (`container_memory_…`) | **provisioned** GiB × seconds awake | same | $0.0000025 | CF Containers | $0.000003 | **$0.00000325** | $0.0000035 |
| Disk (`container_disk_…`) | provisioned GB × seconds awake | same | $0.00000007 | CF Containers | $0.000000084 | **$0.000000091** | $0.000000098 |
| ~~Container bandwidth~~ | container `tx_bytes`: still collected, **no longer billed** (it double-counted delivery bandwidth, §8 item 6) | same | — | CF includes 1 TB/month in NA/EU | — | — | — |
| Database compute (`database_compute_…_per_cu_second`) | compute-unit seconds awake (1 CU = 1 vCPU + 4 GB); Postgres/MySQL/MongoDB | `dply:edge:collect-valkey-usage`, hourly | real ≈ $0.076/CU-h (§9) | **fixed price** (§9) | $0.0000333/s | **$0.0000333/s ($0.12/CU-h)** | $0.0000333/s |
| Database storage (`database_storage_…`) | disk GB-month, prorated by the hour, awake or asleep | hourly | real ≈ $0.12 (§9) | **fixed price** (§9) | $0.20 | **$0.20** | $0.20 |
| D1 rows read | per M | hourly + next day | $0.001 | CF | $0.0012 | **$0.0013** | $0.0014 |
| D1 rows written | per M | same | $1.00 | CF | $1.20 | **$1.30** | $1.40 |
| D1 storage | per GB-month | same | $0.75 | CF | $0.90 | **$0.975** | $1.05 |
| Queue operations | per M | same | $0.40 | CF | $0.48 | **$0.52** | $0.56 |
| KV reads | per M | same | $0.50 | CF | $0.60 | **$0.65** | $0.70 |
| KV writes/deletes/lists | per M | same | $5.00 | CF | $6.00 | **$6.50** | $7.00 |
| KV storage | per GB-month | same | $0.50 | CF | $0.60 | **$0.65** | $0.70 |
| Realtime connection-minutes | open socket-minutes; per M | hourly | real ≈ $0.15 at a 3-minute average connection (§9) | **fixed price** (§9) | $0.25 | **$0.25** | $0.25 |
| Realtime messages | publishes only (deliveries free); per M | hourly | real ≈ $0.466 per publish nobody receives, $0 per delivery (§9) | **fixed price** (§9) | $0.62 | **$0.62** | $0.62 |
| Valkey (per class, per second awake, monthly cap) | awake seconds × class rate, capped per app | hourly | §9 | **fixed price** in `EdgeValkey::CLASSES` (§9) | same | §4 | same |
| Workers CPU | per M CPU-ms | hourly + next day | $0.02 | CF | $0.024 | **$0.026** | $0.028 |
| DO requests | per M | same | $0.15 | CF | $0.18 | **$0.195** | $0.21 |
| DO duration | per M GB-s | same | $12.50 | CF | $15.00 | **$16.25** | $17.50 |
| DO rows read / written | per M | same | $0.001 / $1.00 | CF | $0.0012 / $1.20 | **$0.0013 / $1.30** | $0.0014 / $1.40 |
| DO storage | per GB-month | same | $0.20 | CF | $0.24 | **$0.26** | $0.28 |
| Bucket storage / Class A / Class B | GB-month / M / M | same | $0.015 / $4.50 / $0.36 | CF R2 | $0.018 / $5.40 / $0.432 | **$0.0195 / $5.85 / $0.468** | $0.021 / $6.30 / $0.504 |
| Image transformations | per 1,000 unique | same | $0.50 | CF Images | $0.60 | **$0.65** | $0.70 |

### Meters whose "cost" is not a real provider cost

**Fixed (§9):** every meter below is now a fixed customer price set from an estimate of dply's real cost, so none of them claims cost + 30% any more. The list is kept for the history.

1. **Bandwidth.** Cloudflare charges $0 for Worker and R2 egress, so this whole line is margin. **Fixed:** it is now a fixed customer price ($0.06) in `fixed_price_meters`, so it no longer moves with the margin (r-jnv0r3qf1xk49kmc). Its "cost" in `UsagePrice::cost()` is backed out at the current margin only so the shared arithmetic lands on exactly $0.06.
2. **Valkey.** Backed out at 20%. At 30%, every Valkey price is 8.3% higher than the owner's 2026-09-24 table (0.25 cap $6.00 → $6.50). The per-second rates also match Laravel Cloud **compute** rates exactly (see §8).
3. **Realtime connection-minutes.** Backed out at 20%.
4. **Realtime messages.** "$0.45/M" is not a Cloudflare list price. With hibernation, real DO cost for fan-out traffic is close to $0.
5. **Database compute and storage.** dply "Launch" figures, not a measured cost of the database cluster.
6. **Build minute.** A dply figure. Builds run on dply's own hosts.

### Not metered at all (dply pays, customer doesn't)

**Fixed for Workers AI, Browser Rendering and Vectorize (2026-09-27):** every call goes through dply's proxy (`EdgeMeter`), is billed at list × margin on the "AI, browser rendering and vector search" line, and is capped per org ($25 default, platform ceiling, kill switch per service). See §8 item 4.

Originally: `EdgePlatformUsageCollector.php:27-29` and `EdgeContainerConnections.php:68-72` confirm that **Workers AI**, **Browser Rendering** and **Vectorize** are unmetered. They have no per-script dimension in Cloudflare analytics. They are limited only by being unavailable on the trial. **Hyperdrive** (`database_pool`), Workers Logs / Analytics Engine datasets and DO requests from container front-Workers beyond what the collector attributes are also dply overhead.

---

## 4. Size ladder at 30%

One ladder (`EdgeSizeLadder::RUNGS`). "Always-on" means 720 h. **Container apps were rebalanced 2026-09-27** (§8 item 2): the 1 and 2 vCPU rungs are now Cloudflare **custom instance types** at the minimum memory Cloudflare allows (3 GiB per vCPU; custom types need ≥ 1 vCPU, ≤ 4 vCPU, ≤ 12 GiB, disk ≤ 2 GB per GiB, verified at <https://developers.cloudflare.com/containers/platform-details/limits/> on 2026-09-27). Below 1 vCPU only the named types exist, so 0.25 and 0.5 keep `basic` and `standard-1`. Pickers, the pricing page and the calculator now show the **typical** month (720 h at 25% CPU) and the **cap**, not the 100%-CPU figure alone.

**Monthly cap per app instance** (`UsagePrice::containerCapHours`, `EdgeContainerComputeCost::capMillicents`): cap = H × 3600 × (100%-CPU price per second), H = `container_monthly_cap_hours` (default **600**). An always-on instance at 100% CPU costs dply 720 h × cost/s; the cap bills H × 1.3 × cost/s. Positive margin needs H > 720 / 1.3 = **553.8 h**, so the suggested 540 h would sell below cost (−2.5%). At 600 h the capped always-on app carries 1 − 720/(600 × 1.3) = **7.7%** gross margin. The code floors H at 720 × 1.05 / (1 + margin) (581.5 h at 30%, 630 h at 20%) whatever the config says, and billing never goes below cost. Per period, each app's compute = max(cost, min(price, cap × max(1, memory-GiB-s / (instance GiB × 720 h)))), so N always-on instances get N caps. The cap bites only above ~74–77% CPU averaged over the month; at typical load the metered price is well under it.

| Rung | Container app (mem / disk) | App $/s (100% CPU) | App cap /mo (600 h @100% CPU) | App typical /mo (720 h @25% CPU) | dply cost /mo, 720 h @100% / @25% CPU | Database (mem) | DB $/s | DB always-on /mo (+ storage) | DB cost /mo | Valkey (mem) | Valkey $/s | Valkey always-on /mo (= cap) | Valkey cost /mo | Valkey sleeps? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1/16 vCPU (off-ladder, non-PHP) | 256 MB / 2 GB | $0.00000262 | $5.66 | $3.63 | $5.22 / $2.79 | — | — | — | — | — | — | — | — | — |
| 0.25 vCPU | 1 GB / 4 GB (`basic`) | $0.0000101 | $21.85 | $13.58 | $20.17 / $10.45 | 1 GB | $0.00000833 | $21.60 | $13.72 | 250 MB | $0.00000186 | $4.50 | $3.35 | yes |
| 0.5 vCPU | 4 GB / 8 GB (`standard-1`) | $0.0000267 | $57.73 | $44.01 | $53.29 / $33.85 | 2 GB | $0.0000167 | $43.20 | $27.43 | 1 GB | $0.00000744 | $18.00 | $13.71 | yes |
| 1 vCPU | **3 GB / 6 GB** (`custom-1`; was 6 GB) | $0.0000363 | $78.40 | $43.54 | $72.37 / $33.49 | 4 GB | $0.0000333 | $86.40 | $54.86 ⚠ | 2.5 GB | $0.0000186 | $45.00 | $34.29 | yes |
| 2 vCPU | **6 GB / 12 GB** (`custom-2`; was 8 GB) | $0.0000726 | $156.80 | $87.07 | $144.74 / $66.98 | 8 GB | $0.0000667 | $172.80 | $109.71 ⚠ | 5 GB | $0.0000475 | $115.00 | $85.00 | no (always on) |
| 4 vCPU | 12 GB / 20 GB (`standard-4`) | $0.000145 | $312.81 | $173.20 | $288.75 / $133.23 | 16 GB | $0.000133 | $345.60 | $219.43 ⚠ | 12 GB | $0.000062 | $150.00 | $86.40 | no (always on) |

Database and Valkey columns are the §9 fixed prices, and their cost columns are the §9 real-cost estimates (70% packing). ⚠ A 1, 2 or 4 CU database cannot be scheduled on today's db pool (4 GB nodes, 2.5 GiB allocatable): see §9. Database storage is extra at $0.20/GB-month. Valkey reaches its monthly cap after 672 h awake, so an always-on Valkey is billed at its cap. Container apps are capped per instance (above). Memory dominates their cost: 1 GiB always-on is $8.42/mo at 30% before any CPU. Apps on the retired `standard-2` / `standard-3` keep them and are billed their real shape until the owner picks a size (never mapped to a cheaper rung: Cloudflare still bills the 6 / 8 GiB).

---

## 5. Worked examples at 30%

These examples use the assumptions below. Reads from site storage are **cache misses** (10% of requests). Container CPU is billed on active use (25% for the web app, 50% for the worker). Realtime messages are publishes only; deliveries are free. "Cost" is config cost. "True-bandwidth GM" re-runs gross margin with bandwidth at Cloudflare's real $0.

### Summary

**Updated after §9.** "Cost" is Cloudflare's list cost for pass-through meters and the §9 real-cost estimate for the fixed-price meters (build, databases, Valkey, realtime); bandwidth is still counted at its backed-out $0.046/GB here, and the last column re-runs it at Cloudflare's real $0. Container lines are at the 2026-09-27 ladder; a later ladder or container-cap change moves 4a/4b. Shapes 1, 2, 3 and 5 changed only in the build line ($0.0065 → $0.005/min, real ≈ $0.00185), and their bills did not move because the credit covers them. The per-shape tables below are the originals except 4 and 6.

| # | Shape | Plan | Usage @ price | Credit | **Bill** | Cost | **Gross margin** | GM % | True-bandwidth GM |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Hobby static site | Starter | $0.20 | −$0.20 | **$5.00** | $0.14 | **$4.86** | 97% | $4.95 |
| 2 | Marketing site, 2M req + 50 GB | Starter | $4.31 | −$4.31 | **$5.00** | $3.19 | **$1.81** | 36% | $4.12 |
| 2b | same, on Pro | Pro | $4.31 | −$4.31 | **$20.00** | $3.19 | **$16.81** | 84% | $19.12 |
| 3 | Next.js SSR, team of 3 | Pro | $16.16 | −$16.16 | **$20.00** | $11.84 | **$8.16** | 41% | $12.78 |
| 4a | Laravel container (0.25 vCPU / 1 GB) + Postgres + Valkey + worker, **always-on** | Pro | $60.66 | −$20.00 | **$60.66** | $43.25 | **$17.41** | 29% | $18.33 |
| 4b | same, **sleeping** (awake 200 h) | Pro | $19.24 | −$19.24 | **$20.00** | $13.48 | **$6.52** | 33% | $7.44 |
| 5 | Agency, 40 small sites, 5 people | Team | $6.07 | −$6.07 | **$49.00** | $3.71 | **$45.29** | 92% | $47.14 |
| 6 | Realtime-heavy, 1,000 concurrent sockets | Pro | $67.73 | −$20.00 | **$67.73** | $2.64 | **$65.09** | 96% | $66.47 |

**The pattern.** Once usage passes the credit, gross margin on Cloudflare pass-through meters is **margin/(1+margin) = 23% of the usage price**; on the fixed-price meters it is whatever §9 shows (27–98%). Team's credit now equals its fee (§8 item 1), so no plan loses money on its credit. The plan fee contributes nothing net for a customer who uses the whole credit; fixed costs and Stripe fees come out of the margin (§10).

### 1. Hobby static site (Starter): 50k requests, 2 GB, 10 builds × 1 min

| Line | Qty | Cost | Price |
|---|---|---|---|
| Requests | 50k | $0.01 | $0.02 |
| Bandwidth | 2 GB | $0.09 | $0.12 |
| Site storage | 0.1 GB | $0.00 | $0.00 |
| Storage reads / writes | 5k / 2k | $0.01 | $0.01 |
| Build time | 10 min | $0.05 | $0.07 |
| **Usage** | | **$0.17** | **$0.22** |
| Plan $5.00 − credit $0.22 + usage $0.22 | | | **Bill $5.00** |

After Stripe fees (~$0.45), the net is about $4.38.

### 2. Marketing site: 2M requests, 50 GB, 1 GB site, 30 builds × 2 min

| Line | Qty | Cost | Price |
|---|---|---|---|
| Requests | 2M | $0.60 | $0.78 |
| Bandwidth | 50 GB | $2.31 | $3.00 |
| Site storage | 1 GB | $0.01 | $0.02 |
| Storage reads (misses) / writes | 0.2M / 20k | $0.16 | $0.21 |
| Build time | 60 min | $0.30 | $0.39 |
| **Usage** | | **$3.38** | **$4.40** |
| Starter: $5 − $4.40 + $4.40 | | | **Bill $5.00** (GM $1.62) |
| Pro: $20 − $4.40 + $4.40 | | | **Bill $20.00** (GM $16.62) |

Bandwidth is 68% of this bill. If every request missed the cache (2M reads), usage would be $5.24 and the Starter bill $5.24.

### 3. Next.js SSR, team of 3 (Pro): 5M requests, 100 GB, 10 ms CPU per request, KV-backed ISR

| Line | Qty | Cost | Price |
|---|---|---|---|
| Requests | 5M | $1.50 | $1.95 |
| Bandwidth | 100 GB | $4.62 | $6.00 |
| Workers CPU | 50M CPU-ms | $1.00 | $1.30 |
| KV reads / writes | 5M / 0.2M | $3.50 | $4.55 |
| Site storage + reads/writes | 2 GB, 0.5M / 0.1M | $0.66 | $0.86 |
| Build time | 100 × 3 min | $1.50 | $1.95 |
| **Usage** | | **$12.78** | **$16.61** |
| $20 − $16.61 + $16.61 | | | **Bill $20.00** (GM $7.22) |

### 4. Laravel container app (Pro)

**Recomputed 2026-09-27 after the container rebalance** (§4, §8 item 2). App **0.25 vCPU / 1 GB** / 4 GB disk at 25% CPU (was 0.5 vCPU / 4 GB: a 1 GB instance ran only 2 PHP workers, so a real app needed 4 GB; it now runs 12 php-fpm workers, `EdgeContainerSettings::phpFpmPool`). Worker 0.25 vCPU / 1 GB at 50% CPU (8 `queue:work` processes, was 3). Postgres 0.25 CU with a 5 GB disk. Valkey 0.25 (250 MB). 1M requests, 20 GB out, 30 builds × 4 min.

| Line | Always-on cost | Always-on price | Sleeping (200 h) cost | Sleeping price |
|---|---|---|---|---|
| Requests 1M | $0.30 | $0.39 | $0.30 | $0.39 |
| Bandwidth 20 GB (delivery) | $0.92 | $1.20 | $0.92 | $1.20 |
| App 0.25 vCPU / 1 GB (cap $21.85) | $10.45 | $13.58 | $2.90 | $3.77 |
| Worker 0.25 vCPU / 1 GB (cap $21.85) | $13.69 | $17.79 | $3.80 | $4.94 |
| Postgres 0.25 CU compute | $13.72 | $21.60 | $3.81 | $6.00 |
| Postgres storage 5 GB | $0.60 | $1.00 | $0.60 | $1.00 |
| Valkey 0.25 | $3.35 | $4.50 (cap) | $0.93 | $1.34 |
| Build time 120 min | $0.22 | $0.60 | $0.22 | $0.60 |
| **Usage** | **$43.25** | **$60.66** | **$13.48** | **$19.24** |
| **Bill** = $20 + max(0, usage − $20) | | **$60.66** | | **$20.00** |
| **Gross margin** | | **$17.41 (29%)** | | **$6.52 (33%)** |

- **The ~$30–35 target is not met for the whole bill.** The whole stack is $60.66, down from $91.10 (the same shape with the old 0.5 vCPU / 4 GB app, which is $44.01 on its own). Only compute reaches the target: app + worker is **$31.37** always-on. The rest is Postgres ($22.60) and Valkey ($4.50), which this change does not touch, plus the separate worker.
- **Without the separate worker** (queued jobs on the app instance, which now has the headroom) the always-on bill is **$42.87** (cost $29.56, GM $13.31 / 31%).
- **Neither app hits its cap** at these loads. The cap only binds above ~75% CPU averaged over the month; an always-on 0.25 vCPU app at 100% CPU bills $21.85, not $26.22.
- The 1 GB app runs 12 PHP requests at once. That is sized on a 56 MB average per worker; an app with fat requests may OOM, and `raiseForMemoryCrash` then steps it to 1 vCPU / 3 GB (typical $43.54).

Database, Valkey and build costs are the §9 real-cost estimates (they were config figures of $19.08, $1.75, $5.00 and $0.60 before).

The 20 GB is billed once, as delivery bandwidth. Before the §8 item 6 fix it was also billed as container bandwidth ($0.65 more).

### 5. Agency, 40 small sites, 5 people (Team)

Pro's cap of 20 custom domains forces this customer onto Team. 1.2M requests, 40 GB, 8 GB of sites, 320 builds × 1.5 min.

| Line | Qty | Cost | Price |
|---|---|---|---|
| Requests | 1.2M | $0.36 | $0.47 |
| Bandwidth | 40 GB | $1.85 | $2.40 |
| Site storage | 8 GB | $0.12 | $0.16 |
| Storage reads / writes | 0.12M / 0.1M | $0.49 | $0.65 |
| Build time | 480 min | $2.40 | $3.12 |
| **Usage** | | **$5.22** | **$6.79** |
| $49 − $6.79 + $6.79 (5 of 10 seats) | | | **Bill $49.00** (GM $43.78) |

### 6. Realtime-heavy app (Pro, at Pro's 1,000-connection cap)

1,000 sockets open 24/7, with a broadcast to all of them every 30 s (86.4k publishes, 86.4M deliveries). 3M HTTP requests, 30 GB.

| Line | Qty | Cost | Price |
|---|---|---|---|
| Requests | 3M | $0.90 | $1.17 |
| Bandwidth | 30 GB | $1.38 | $1.80 |
| Connection-minutes | 43.2M | ~$0.01 (≈30k reconnects × $0.45/M) | $10.80 |
| Messages | 86.4k publishes (86.4M deliveries, free) | ~$0.04 (publishes × $0.466/M) | $0.05 |
| Workers CPU | 15M ms | $0.30 | $0.39 |
| **Usage** | | **$2.64** | **$14.21** |
| $20 − $14.21 + $14.21 | | | **Bill $20.00** (GM $17.36) |

Updated 2026-09-27: only publishes bill and deliveries are free (ruling r-ez5s8c56zn0ry3sw); this bill was $67.73, almost all of it deliveries that cost dply nothing. The $0.62/M price is set against a publish nobody receives ($0.466/M real), so every publish clears 1.3× its cost (sending to sockets adds only milliseconds of Durable Object time). Connection-minutes now carry most of a broadcast app's bill. Growth past 1,000 sockets per app forces Team.

---

## 6. Competitive comparison (same shapes)

**Verified** means fetched on 2026-09-27 from the vendor's own pricing page:

- Laravel Cloud: plans (Starter $5 with $5 credit, Growth $20, Business $200), Flex/Pro compute rates and caps, bandwidth overage $0.10/GB, and its own scenario totals (~$0.39, ~$6.23, ~$34.29, ~$176 of usage). Source: <https://laravel.com/cloud/pricing>.
- Vercel Pro: $20 with a $20 credit, 10M edge requests and 1 TB included, then $2/M requests and $0.15/GB. Source: <https://vercel.com/pricing>.
- Netlify: Free (300 credits), Personal $9 (1,000 credits), Pro $20 (3,000 credits). A credit is about $0.0067. 1 GB costs 20 credits, 10k requests 2 credits, a deploy 15 credits. Source: <https://www.netlify.com/pricing/>.
- Railway: Hobby $5 with $5 credit, Pro $20 with $20 credit. $20/vCPU-month, $10/GB-month RAM, $0.05/GB egress. Source: <https://railway.com/pricing>.

**Unverified:**

- Vercel's per-seat multiplier.
- Netlify member pricing. The page read as "unlimited members" on Pro.
- Render instance and Postgres prices. These come from third-party summaries (Starter $7, Standard $25, Postgres Basic from $6, 100 GB then $0.10/GB). render.com's page did not render.
- Laravel Cloud Reverb tier prices (not on any page fetched). Laravel Cloud Postgres and Valkey prices are now in §9, *as reported by a fetch of* <https://laravel.com/cloud/docs/pricing> (the per-second column came back garbled, so only caps and hourly rates are quoted).
- Pusher prices.

All competitor figures below are **estimates** for the same workload.

| Shape | **dply** | Laravel Cloud | Vercel | Netlify | Render | Railway |
|---|---|---|---|---|---|---|
| 1 Hobby static | **$5** | ~$5 (Starter; first month free) | $0 (Hobby, non-commercial only) | $0 (Free: ~200 credits) | $0 (static site, unverified) | ~$5 (Hobby) |
| 2 Marketing 2M req + 50 GB | **$5** (Starter) | ~$5–11 (Starter + sleeping Flex; 50 GB included with Flex 512) | $20 (Pro, well inside included) | ~$19–20 (≈1,850 credits: Personal + packs, or Pro) | ~$0–19 (unverified) | ~$5–8 |
| 3 Next.js SSR, 3 people | **$20** | n/a (PHP only) | ~$60 (3 × $20 seats, unverified per-seat) | ~$20–40 (≈5,900 credits) | ~$25 + workspace seats (unverified) | ~$20–25 |
| 4a Laravel always-on | **$60.66** ($42.87 without the separate worker; compute alone $31.37) | ~$40–60 (Flex 1 GiB app $12 cap, or Pro 4 GiB $32 cap + Flex worker $6–12 + Postgres/Valkey, unverified; Growth $20 − $20 credit) | n/a | n/a | ~$45–60 (Standard $25 + worker $7 + PG ~$19 + KV ~$10, unverified) | ~$25–35 (usage-based RAM/CPU) |
| 4b Laravel sleeping (200 h) | **$20.00** (usage $19.24, inside the credit) | ~$10–20 (LC's own "SaaS MVP 240 h" scenario is ~$34 of usage with 2 larger workers) | n/a | n/a | same as 4a (no scale-to-zero on paid instances) | ~$10–15 |
| 5 Agency 40 sites, 5 people | **$49** | n/a (per-app compute) | ~$100 (5 seats, unverified) | ~$40 (≈5,840 credits) | ~$0 static + team seats (unverified) | n/a (not a static host) |
| 6 Realtime 1,000 sockets | **$67.73** | Reverb 2,000-connection tier (price unverified) | n/a | n/a | n/a | self-host (~$10–20) |
| Pusher Channels (verified 2026-09-27, pusher.com/channels/pricing) | — | — | — | — | — | Pro $99: 2,000 connections, 4M messages/day |

**Takeaways.**

- dply is cheapest or at parity for **static and SSR** (shapes 1, 2, 3 and 5), mainly because there are no seat fees on Starter or Pro and Team is flat.
- **Always-on containers are now at parity with Laravel Cloud and Render** (4a: $60.66 against ~$40–60, and $42.87 with jobs on the app instance). Before the 2026-09-27 rebalance dply was ~2× ($91.10–97.75) because the example app needed 4 GB to serve more than 2 PHP requests at once. Compute is close: dply's 1 GB app is $13.58 typical and capped at $21.85, against LC's Flex 1 GiB capped at $12. The gap that is left is memory price (Cloudflare $6.48/GB-month cost, $8.42 at price) and the Postgres line.
- On realtime, $67.73 for 1,000 sockets beats Pusher ($99 Pro), and the price is now tied to the real cost of a publish (§9).

---

## 7. How to change things

| To change | Where | Env var | Updates automatically | Needs manual follow-up |
|---|---|---|---|---|
| **Margin** | `config/product/dply.php:315` `margin_percent` | `DPLY_USAGE_MARGIN_PERCENT` | Every meter price, the size ladder, Valkey, pricing page and calculator, billing page, invoices, trial-cap accounting (all via `UsagePrice`) | **Docs tables:** run `php artisan dply:billing:price-table --write-docs`. It rewrites every `<!-- generated … -->` table in `docs/site` in place; `--check-docs` (what `DocsPriceTablesTest` runs) fails while any is stale. The prices that used to be hand-written prose (builds, databases, realtime) are generated tables now; the two worked examples left in prose (`containers.md` 1/16 vCPU, `resources/databases.md` monthly example) still need a manual recompute. Bandwidth stays $0.06 (`fixed_price_meters`). **Trap:** set the margin in the config default, not only in production `.env`. The docs test renders at the default, so an env-only change leaves docs at the old numbers while invoices move. |
| Included credit | `subscription.php:93/103/114` `usage_credit_cents` | none | Invoices, forecast, pricing page, calculator | Docs `plans` table and the prose in usage.md:43 |
| Plan price | `subscription.php` `price_cents` | none | Pricing page, forecast, calculator | **The real charge is the Stripe price.** Run `php artisan dply:billing:provision-stripe` (`StripeBillingProvisioner` archives the old price and creates a new one), then update `STRIPE_PRICE_STARTER` / `_TIER_PRO` / `_TIER_TEAM` / `_TEAM_SEAT`, and **add the archived id to `STRIPE_PRICE_STARTER_LEGACY` / `STRIPE_PRICE_TIER_PRO_LEGACY` / `STRIPE_PRICE_TIER_TEAM_LEGACY`** (comma lists). Listed ids are grandfathered: they keep their plan, and the syncer does not move them. An unlisted archived id is reported (Sentry) on every sync; the org reads as Pro so it isn't paused, the syncer leaves it alone, and the renewal bills the plan named in the price's `metadata.dply_role`. Then `--write-docs`. |
| Extra seat price | `extra_seat_cents` (Team) | `STRIPE_PRICE_TEAM_SEAT` | Same as plan price | Same as plan price |
| Seats included | `seats` | none | Invite gate, pricing page | Docs |
| Fair-use caps | `fair_use_apps` | none | `CreateEdgeSite` gate | Docs `fair-use` table |
| Non-price limits | `tiers.*` (concurrent_builds, custom_domains, databases, queues, worker_*, realtime_max_connections, …) | none | Enforced where each thing happens, plus the pricing page | Docs `limits` table; spending-alerts.md:48-54 prose |
| Trial length / cap / keep days / purge | `subscription.php:59-66` | `DPLY_BILLING_PURGE_ENABLED` only | Enforcer, budget, emails, pricing page line | free-trial.md, spending-alerts.md prose ("$5", "$4", "5 days") |
| Alert default | `UsageAlerts::limitCents` (2× plan price, code) | none | — | spending-alerts.md:28 |
| Individual meter costs | `dply.php:318-392`, one env var each (`DPLY_USAGE_*`, `DPLY_EDGE_*`) | yes | Everything priced via `UsagePrice` | Docs tables, and the same env-vs-default trap as the margin |
| Valkey prices | **Code**: `EdgeValkey::CLASSES` (`price_cap_cents`, `price_per_second`: customer prices, no margin) | none | Via `UsagePrice::valkeyPerSecond` | Code change and deploy; re-check `dply:billing:unit-costs` |
| Fixed-price meters (bandwidth, build, database compute/storage, realtime) | `dply.edge.usage_billing.fixed_price_meters`; the value is the **price** | the meter's `DPLY_USAGE_*` (sets a price) | Everything via `UsagePrice` | `--write-docs`; re-check `dply:billing:unit-costs` |
| Real-cost estimates | `dply.unit_costs` (node sizes, packing, build utilisation, realtime assumptions, fixed bills) | none | `dply:billing:unit-costs`, the ≥ cost × 1.3 test | §9 and §10 of this document |
| Size ladder, instance shapes, DB CU per rung | **Code**: `EdgeSizeLadder`, `EdgeContainerSettings::INSTANCE_TYPES`, `EdgeAppDatabase::POSTGRES_SIZES` | none | Pricing page, pickers | Code change |
| Container monthly cap | `dply.php` `container_monthly_cap_hours` (default 600) | `DPLY_USAGE_CONTAINER_CAP_HOURS` | Invoices, pickers, pricing page, calculator, docs table (via `UsagePrice::containerCapHours`, floored at 5% over cost) | `--write-docs`; the "600 hours" prose in `docs/site/pricing.md` |

---

## 8. Risks, open questions, inconsistencies

### Economics

1. **Credit equals fee. Fixed (Team):** Team's credit is now $49, equal to its fee (`subscription.php`, test `no plan credit is larger than its fee`); run `dply:billing:provision-stripe` if the Stripe product description should say $49. The rest of this item stands, and §10 shows it: a customer who uses the whole credit on pass-through meters still contributes $0.67 (Starter), $3.60 (Pro) or $9.25 (Team) a month after Stripe. Originally: Every customer who uses their credit pays nothing net for the plan. Gross margin on them is 23% of usage (Starter, Pro) or 23% minus $1 (Team, `subscription.php:114`: credit $50 > fee $49). That 23% must cover Stripe (~3% + $0.30), fixed Cloudflare/cluster/build-host costs and support. Starter customers with small usage and bandwidth-heavy sites are where the money is. Options: credit < fee (e.g. $3 / $15 / $40), or a higher margin on low-cost meters only.
2. **Always-on containers were ~2× Laravel Cloud** (§6). **Fixed 2026-09-27**, in four parts:
    - **PHP concurrency from memory.** A 1 GB instance ran 2 php-fpm workers (8 per vCPU), so a real Laravel app needed 4 GB. `EdgeContainerSettings::phpFpmPool` now sizes workers as (memory − 192 MB) / 56 MB (96 MB for Octane), at most 32 per vCPU (at least 12) and 128: 12 on 1 GB, 16 on 0.5 vCPU / 4 GB, 32 on 1 vCPU / 3 GB. The Worker's scale-out threshold (`requestsPerInstance`) follows. Octane gets `--workers`, FrankenPHP `num_threads` (via `FRANKENPHP_CONFIG`, an app's own value wins), and Inertia SSR reserves two workers' memory. Queue workers start with one process per 96 MB (8 on 1 GB, was 3). ponytail: sized on the average, so every worker peaking at the 128M `memory_limit` at once can OOM; the deploy then steps the size up.
    - **Ladder rebalanced to Cloudflare custom types.** 1 vCPU / 3 GiB (`custom-1`) and 2 vCPU / 6 GiB (`custom-2`) replace `standard-2` (6 GiB) and `standard-3` (8 GiB): −$25 and −$17.50 a month typical. Cloudflare's custom types need ≥ 1 vCPU and ≥ 3 GiB per vCPU, so the requested 0.5 GB / 1 GB / 2 GB / 4 GB / 8 GB rungs are not possible; below 1 vCPU only `lite`, `basic` and `standard-1` exist. Stored `standard-2` / `standard-3` keep working and are billed their real shape (Cloudflare still bills that memory); they are labelled *retired*, hidden from pickers, never a bump target, and the size suggestion offers the new rung when a week of peaks fits. They are not migrated automatically: dropping a running app from 6 GiB to 3 GiB on its next deploy could OOM it. The Medium plan now starts on `custom-1`.
    - **Monthly cap per app instance**, like Valkey and Laravel Cloud (§4): 600 h of the 100%-CPU price, never below 5% over cost.
    - **Pickers show typical and cap.** The App sheet, the estimate, the Container tab, the pricing page, the calculator and the docs table show 720 h at 25% CPU and the cap; the per-second rate stays at 100% CPU. `EdgeQueueWorkers::monthlyCents` uses typical CPU. 730 h became 720 h everywhere.

    Still open: `standard-1` (0.5 vCPU / 4 GiB) costs about the same as `custom-1` at typical load with half the CPU. It stays as the 0.5 rung and as the floor for resident PHP servers. Dropping it from the offered list would make the ladder monotone in memory.
3. **Bandwidth pricing. Fixed (the tie to the margin).** $0.06/GB is pure margin (CF egress $0), undercutting Laravel Cloud ($0.10), Render ($0.10), Vercel ($0.15) and Netlify (~$0.13). It is also the biggest single line for static customers. It is fine as a decision, but it is tied to the 30% margin through `6000/1.3` (`dply.php:324`), which contradicted r-jnv0r3qf1xk49kmc. Now `dply.edge.usage_billing.fixed_price_meters` lists `egress_millicents_per_gb`, whose value is the customer price; `UsagePrice::cost()` backs the cost out at the current margin, so every path (rates, invoices, pricing page, calculator) prices it at exactly $0.06 at any margin. Tested at 20/30/50% (`PricingModelTest`). Note `DPLY_USAGE_EGRESS_MC_PER_GB` now sets a **price**.
4. **Unmetered AI, Browser Rendering and Vectorize. Fixed:** metered per call by the proxy in the Worker entry wrapper and the container Worker (`EdgeMeter`), billed via `EdgeMeteredUsageCost`, capped per org (`organizations.metered_cap_cents`, default $25, `edge.metered_services.ceiling_cents` always applies), kill switch `DPLY_EDGE_{AI,BROWSER,VECTORS}_ENABLED`. Neurons are Cloudflare's per-model rates on reported tokens (estimated when a model reports none). Browser concurrency ($2/browser over 10) and free allocations stay dply overhead. Originally: (`EdgePlatformUsageCollector.php:27-29`, `EdgeContainerConnections.php:68-72`). A paid Starter org can run unbounded Workers AI or Browser Rendering on dply's account for $5. They are gated only by "not on trial". Needs a per-org cap, a separate account, or a kill switch.
5. **Realtime is priced far above cost. Fixed, with a correction:** the claim below was half wrong. Deliveries are free, but every *publish* is a Worker request + a DO request (~$0.466/M), so a publish nobody receives costs about what the old $0.45 figure said. Messages are now a fixed $0.62/M (1.33× that worst case, 4× under Ably) and connection-minutes a fixed $0.25/M (was $0.542), §9. Originally: Messages at $0.585/M and connection-minutes at $0.542/M sit against a real DO cost near $0, if the relay uses hibernation. It is safe for margin, but uncompetitive and hard to defend if someone does the math. Pro's 1,000-connection cap equals the example customer, so growth forces Team.
6. **Container egress was billed twice. Fixed.** Verified in code: `EdgeUsageCollector::billableEdgeSites()` takes every active site with an `edge_backend`, container apps included, and sums zone `edgeResponseBytes` for the site's hostnames. A visitor's request to a container app enters through its Worker on that hostname, so the response bytes are in `edgeResponseBytes`. `EdgeContainerUsageCollector` sums the container's `txBytes`. **Inferred, not verified** (Cloudflare's docs don't say what `txBytes` or container egress covers): `txBytes` counts the same bytes again on the container → Worker hop, and `EdgeContainerComputeCost` billed them again at $0.0325/GB. Fix: container `tx_bytes` is still collected but no longer billed, and the "Container bandwidth" rate and its config key are gone. Delivery bandwidth was kept rather than excluding container sites from it, because (a) it is the price the ruling fixes ($0.06), the same for every site; (b) excluding container hostnames would also drop static assets their front Worker serves from R2; and (c) what `tx_bytes` adds beyond visitor responses is the container's own outbound calls, which Cloudflare covers with 1 TB/month in NA/EU. Ceiling: a container that pushes lots of outbound traffic (uploads to a third-party API) is not billed for it; bill `max(0, tx − delivery)` if that shows up. Test: `EdgeContainerComputeBillingTest`.
7. **Invented costs. Fixed:** these meters are now fixed customer prices set from the real-cost estimates in `dply.unit_costs` (§9); `dply:billing:unit-costs` prints cost, price and markup for each, and a test fails if an offered one falls under cost × 1.3. The estimates still need replacing with measured bills. Originally: Database CU ($0.106/h) and storage ($0.35), build minutes ($0.005), Valkey (backed out at 20%) and realtime (backed out or dply-set) are not measured infra costs. Gross margins in §5 for shapes 4 and 6 are therefore unknown, not 23%. Measure real per-CU and per-GB cluster cost before relying on them.
8. **Valkey mirrors Laravel Cloud compute, not Laravel Cloud Valkey. Fixed:** repriced from the cache pool's memory cost (250 MB $6.50 → $4.50, §9). Laravel Cloud's own Valkey is confirmed far cheaper (250 MB $1.75 cap); dply can't match it on 4 GB DOKS nodes at 1.3×, see §9. Originally: `EdgeValkey.php:37` flex_250m is `0.00000248/1.2` with a $6 cap. That is exactly LC's **Flex 512 MiB compute** rate ($0.00000248016/s, $6 cap). flex_1g equals LC Flex 2 GiB ($0.00000992, $24). The LC docs fetch suggested LC's own Valkey 250 MB is far cheaper (~$1.70 cap, unverified).

### Trial

9. **Trial abuse and overshoot.** **Fixed.** Each trial costs dply at most its $5 cap at cost (**$3.85** at 30%, `$5 / (1 + margin)`), plus a few cents of overshoot, plus the traffic meters of the last hour. It is never billed. What changed:
   - **A 5-minute check with a fresh estimate.** `dply:billing:enforce --trialing` runs every 5 minutes (`DplySchedule`). It checks only trials that aren't paused and have something that bills while it runs: a container app (where workers, databases and Valkey hang) or a build in flight. The hourly full run stays. `StarterUsageBudget` adds `TrialRunningCost` to a running trial's spend: builds in flight since they started, plus each app's instances, workers, dply database and Valkey as if awake since their last collection (at most 65 minutes). At the cap the org is paused through the existing path, and its builds in flight are stopped (`CancelStuckEdgeDeployment::abandon`, which kills the build container). A trial paused at its cap stays capped until it converts, so the estimate going away on pause cannot resume it.
   - **Trial limits (`EdgeTrialLimits`).**
     - Apps run the smallest ladder rung (0.25 vCPU, `basic`), with 1 instance and none kept awake. They sleep after 5 minutes idle and get no dedicated or always-on jobs instance. Scaling windows are capped at 1.
     - Queue workers: 1, with no autoscale and no extra groups.
     - Builds run 1 at a time.
     - dply databases use the smallest size and never "stays on".
     - Valkey uses the smallest (sleeping) class and never "stays on".
     - Sizes only go down: an app on `lite` keeps it. The owner's stored choice returns when the trial converts. The Resources sheets show capped choices disabled with "Available after your trial".
   - **Worst case, at the current config.** At cost, the trial limits burn about $0.028/h per app, $0.028/h per worker, $0.023/h per database, under $0.01/h per Valkey, and $0.23/h for the one build: about $0.08/h per app, plus the build. The estimate already counts all of that, so the overshoot past the cap is at most one 5-minute window: about $0.02 for the build plus $0.007 per app. A 3-app trial comes to about **$3.89** at cost. Before this, an hourly check that ignored uncollected usage let a Team trial run 5 builds and many `standard-4` instances ($0.40/h each) for an hour or more past the cap, which is several dollars. The estimate counts a sleeping app as awake for up to 65 minutes, so most trials pause slightly *before* their true cap.
   - **Residual.** Delivery (requests, bandwidth), KV, D1/Queues, Workers CPU, Durable Objects, R2 and realtime are still collected hourly and not estimated. A trial whose site takes a traffic spike can pass the cap by up to about an hour of that traffic. After the pause, the paused page and the container gate stop almost all of it.
   - **Abuse.** Every trial is granted in `PlanCheckout` and captured by `CaptureTrialCardFingerprint`, including a card-less trial that moves into Stripe. It checks one trial per owner (`eligibleForTrial`) and one per card fingerprint. A trial whose card fingerprint can't be read, from either the subscription or the customer's default payment method, is now ended at once (it starts paid) instead of passing unchecked. **Not done:** a per-IP signup/trial throttle. `request()->ip()` is only the client when `TRUSTED_PROXIES` is set in production, which this repo can't confirm. With a proxy IP, a per-IP limit would block every trial after the first few. Unmetered AI and Browser stay blocked on trial.
   - **Owner decisions.**
     - FrankenPHP, Swoole and RoadRunner apps need `standard-1` (`minimumInstanceType`), so the trial size cap will crash them until the trial converts.
     - A card with no readable fingerprint (for example, a non-card method through Link) now gets no trial.
10. **Trial plan wording.** `free-trial.md:13` says the first-deploy path starts a **Pro** trial (`trial.tier`), while the ruling says the trial is the chosen plan. This is only consistent if the first-deploy path is meant as a default.

### Code / config correctness

11. **Price-change broke tier detection. Fixed.** The original analysis was wrong in the direction of the damage: `subscribedTier()` matched only the current `STRIPE_PRICE_*` ids, and `onStandardSubscription()` did too, so an org on an archived plan price read as **`none`** in `billingTier()` and `dply:billing:enforce` would have **paused a paying customer** (`OrganizationBillingEnforcer` pauses when `! hasPlan()` and the org has a subscription). Only `UsageInvoicer::tierOf()` fell back to Pro, and the syncer would have `swapAndInvoice`d anyone not on the current price onto it. Now:
    - `SubscriptionPlanResolver::tierPriceIds($tier)` = current id + `STRIPE_PRICE_*_LEGACY` (comma list). `subscribedTier()`, `onStandardSubscription()`, `UsageInvoicer::tierOf()` and the syncer's "already on the plan?" check all use it, so a listed old price keeps its plan and is never moved (grandfathered). Legacy ids are also excluded from the `retired` strip list.
    - `UsageInvoicer::tierOf()` falls back to the price's `metadata.dply_role` (`tier_<plan>`, set by the provisioner and kept on archived prices), which is in the Stripe payload it already has.
    - A price nothing identifies is reported, never guessed: `billingTier()` reports it and reads Pro so the org is not paused; the syncer reports it and does not move the subscription; the invoicer reports it and bills nothing (bill that period by hand).
    - Tests: `PlanPriceChangeTest`, and two renewal tests in `UsageInArrearsTest`.
    - Still the owner's call: whether to grandfather at all (list the old id) or move everyone to the new price (move them by hand, or leave the id unlisted and move them after the reports).
12. **Rounding wording. Fixed** (both docs now say "nearest cent"). `docs/site/pricing.md:69` and `docs/site/invoices.md:31` say each line is "rounded **up** to the next whole cent". The code rounds to the **nearest** cent (`UsagePrice.php:39`; `EdgeRedisCost.php:46-48` says so explicitly).
13. **Calculator vs invoice. Fixed:** `pricing.md` and the pricing page now call it an estimate and list what it leaves out. `pricing.md:138` says the estimator "runs the same arithmetic as the invoice". `pricing-calculator.blade.php:17-24` models only requests, bandwidth, build minutes and a 0.25 vCPU app-hour at 100% CPU. It leaves out storage ops, databases, Valkey, KV, realtime and more.

### Stale numbers and comments

14. **Docs prose at 20% margin. Fixed:** builds, database and Laravel-broadcasting prices are now generated tables; the two worked examples were recomputed at 30% / 720 h. Originally:
    - `docs/site/builds.md:132`: $0.006/min. Now $0.0065.
    - `docs/site/containers.md:71`: 1/16 vCPU $0.00000242/s ($0.0087/h). Now $0.00000262/s ($0.0094/h).
    - `docs/site/resources/databases.md:170`: storage $0.42. Now $0.455.
    - `docs/site/resources/databases.md:172`: $23.63 always-on / $8.05 at 8 h/day (20%, 730 h). Now ≈ $25.26 / $8.72 at 720 h.
    - `docs/site/guides/laravel-broadcasting.md:133`: $0.50 / $0.54 per M. Now $0.542 / $0.585.
15. **Stale comments. Fixed** (all four, plus the `SubscriptionPlanResolver` / `ManagesOrganizationSubscription` "no paid tiers" docblocks):
    - `config/product/subscription.php:30-36` ("No paid plan tiers. The one record is `free`").
    - `EdgeValkey.php:29-32` ("customer prices are unchanged at 20%").
    - `dply.php:368-369` ("~$0.45 … is our Cloudflare cost").
    - `EdgeContainerConnections.php:68` lists Images as unmetered, but `EdgePlatformUsageCollector` meters Images.
16. **CLAUDE.md was stale. Fixed:**
    - Line 120 says the margin "default 20". It is 30.
    - Line 128 says "5-day **Pro** trial". It is the chosen plan.
    - Line 130 says "7-day keep period". It is 30.
    - Lines 137-138 say purge "ships **off**". The default is on, per ruling r-jnv0r3qf1xk49kmc.

### Open questions for the owner

- Should the credit stay equal to the fee (item 1)? Team no longer exceeds it; §10 shows the thin contribution of a customer who uses all of it.
- Cap container months, or reprice memory (item 2)?
- ~~Is bandwidth a fixed $0.06 regardless of margin (item 3)?~~ Answered by r-jnv0r3qf1xk49kmc and now enforced in code.
- How should AI and Browser be metered or capped (item 4)?
- ~~Reprice realtime toward real cost (item 5)?~~ Done (§9). Done 2026-09-27: only publishes bill, deliveries are free (ruling r-ez5s8c56zn0ry3sw).
- Do we grandfather old Stripe prices (item 11)? The code now supports both (list the old id to grandfather), but decide before any price change.

---

## 9. Real unit costs and the fixed prices set from them

Added 2026-09-27 (update 2). Database compute and storage, Valkey, build time and realtime ran on made-up "costs" (§8 item 7). They are now **fixed customer prices**: listed in `dply.edge.usage_billing.fixed_price_meters` (Valkey in `EdgeValkey::CLASSES`), so the margin no longer moves them and the model no longer claims cost + 30% for them. Each price was set from an **estimate** of dply's real cost, which lives in `config/product/dply.php` → `unit_costs` and is printed next to the price by:

```
php artisan dply:billing:unit-costs
```

A test (`PricingModelTest`: *every offered size and repriced meter is priced at least 30% over its estimated real cost*) fails if any offered price drops under cost × `unit_costs.min_markup` (1.3). **Markup** here is price ÷ cost − 1, the same sense as `margin_percent`.

### What the infrastructure is (from the repo)

- **Databases and Valkey:** one DOKS cluster `dply-pods` in nyc3 (`deploy/valkey/terraform`): HA control plane; pool `flex` (cache) 2–3 × s-2vcpu-4gb; pool `db` 2–4 × s-2vcpu-4gb, tainted for databases; pool `pro-16` 0–1 × m-2vcpu-16gb for Pro Valkey; `pro-64` (m-8vcpu-64gb) not created; pools `db-large` 0–2 × s-8vcpu-16gb (1 and 2 CU databases) and `db-xl` 0–2 × m-4vcpu-32gb (4 CU), added for ruling r-s56bk4pq4cnv8dt4, not applied yet. Gateway behind one DO load balancer; basic registry. An awake database pod **requests its full memory** and 250m CPU per GB (`database.go`); a Valkey pod requests its `maxmemory` (`kube.go`). Asleep, a database drops to 16 MiB and a flex Valkey pod is deleted, so awake-seconds are what uses capacity.
- **Builds:** the DOKS `builders` pool (`deploy/builders/`, pool in `deploy/valkey/terraform`): one builder pod per s-4vcpu-8gb node, 2 builds at a time (`HORIZON_BUILD_MAX_PROCESSES` in `deploy/builders/k8s/builder.yaml`), KEDA scaling pods on the build queue from 1 to 4 and the cluster autoscaler adding nodes to match. Updated 2026-09-27: it was `docker run` on the control-plane worker, 4 at a time.
- **Realtime:** `packages/realtime-worker`, one hibernating Durable Object per app. Pings are auto-answered at the edge; usage flushes on a storage alarm (every 5 s at most), so an idle hub does not bill duration.

### Prices used (fetched 2026-09-27)

| Input | Value | Source |
|---|---|---|
| s-2vcpu-4gb / s-4vcpu-8gb droplet or DOKS node | $24 / $48 per month | digitalocean.com/pricing/droplets |
| m-2vcpu-16gb / m-4vcpu-32gb / m-8vcpu-64gb node | $84 / $168 / $336 | digitalocean.com/pricing/droplets (memory-optimized; DOKS nodes bill at droplet price) |
| s-8vcpu-16gb node | $96 | digitalocean.com/pricing/droplets |
| DOKS HA control plane / load balancer / registry | $40 / $12 / $5 | digitalocean.com/pricing/kubernetes; registry basic tier (unverified, $5) |
| Allocatable memory, 4 / 8 / 16 / 32 / 64 GB node | 2.5 / 6 / 13 / 28 / 58 GiB | docs.digitalocean.com/products/kubernetes/details/limits |
| Block storage / volume snapshots | $0.10 / $0.06 per GB-month | digitalocean.com/pricing/volumes |
| Workers for Platforms | $25/mo: 20M requests, 60M CPU-ms, 1,000 scripts; $0.02 per extra script | developers.cloudflare.com/cloudflare-for-platforms/workers-for-platforms/platform/pricing |
| Durable Objects | $0.15/M requests (incoming WS messages 20:1, alarms count); $12.50/M GB-s; outgoing messages free; no duration while hibernation-eligible | developers.cloudflare.com/durable-objects/platform/pricing |
| Stripe | 2.9% + 30¢ card, + 0.7% Stripe Billing | stripe.com/billing/pricing |

### Real cost vs price

Assumptions (all in `unit_costs`, change them there): 70% of the cache and db pools' allocatable memory is filled by paying tenants; Pro Valkey pays for the whole node it forces up (the pool scales from 0, max 1 node); builds keep 15% of the minimum node's 2 slots busy; a publish runs 10 ms at 128 MB in the DO; an average realtime connection lasts 3 minutes.

| Meter | Unit | Real cost (est.) | Old price @30% | **New price** | Markup | Competitors |
|---|---|---|---|---|---|---|
| Database compute 0.25, 0.5 CU (`db`) | per CU-hour | $0.076 ($24 ÷ 2.5 GiB ÷ 70% × 4 GiB ÷ 720 h) | $0.138 | **$0.12** | 58% | Laravel Cloud Postgres $0.135/CU-h (as reported) |
| Database compute 1 CU (`db-large`, always on) | per CU-hour | $0.059 ($96 ÷ 13 GiB ÷ 70% × 4 GiB ÷ 720 h); alone: $96/mo | — | **$0.18** | 207% shared, 35% alone | same |
| Database compute 2 CU (`db-large`, always on) | per CU-hour | $0.059; alone: $96/mo | — | **$0.12** | 105% shared, 80% alone | same |
| Database compute 4 CU (`db-xl`, always on) | per CU-hour | $0.058 ($168 per awake 4 CU: one fits a node, ÷ 4 ÷ 720 h) | — | **$0.12** | 106% | same |
| Database storage | per GB-month | $0.12 (volume $0.10 + dump copy in Spaces ~$0.02) | $0.455 | **$0.20** | 67% | Laravel Cloud $0.15 + $0.15 PITR (as reported); Neon $0.35 |
| Valkey flex | per GiB-month | $13.71 ($24 ÷ 2.5 GiB ÷ 70%) | $26.00 (1 GB) | **$18.00** | 31% | Upstash fixed 1 GB $20 |
| Valkey 250 MB | cap / month | $3.35 | $6.50 | **$4.50** | 34% | Laravel Cloud Flex $1.75 (as reported); Upstash fixed $10 |
| Valkey 1 GB | cap | $13.71 | $26.00 | **$18.00** | 31% | Laravel Cloud $7; Upstash $20 |
| Valkey 2.5 GB | cap | $34.29 | $52.00 | **$45.00** | 31% | Laravel Cloud $17.50 |
| Valkey Pro 5 GB | cap | $85.00 (whole m-2vcpu-16gb + AOF volume) | $83.42 | **$115.00** | 35% | Laravel Cloud Pro $105; Upstash $100 |
| Valkey Pro 12 GB | cap | $86.40 (same node) | $195.00 | **$150.00** | 74% | Laravel Cloud Pro $252 |
| Valkey 25 / 50 GB (not offered) | cap | $341 / $346 (whole m-8vcpu-64gb) | $270.83 / $541.67 | **$450 / $600** | 32% / 73% | Laravel Cloud $525 / $1,050 |
| Build time | per minute | $0.0037 ($48 ÷ (2 slots × 43,200 min × 15%)) | $0.0065 | **$0.005** | 35% | Render $0.005 (unverified); Laravel Cloud, Netlify include builds in plan/credits |
| Realtime messages | per million | $0.466 per publish nobody receives (Worker $0.30 + DO $0.15 + 10 ms duration); deliveries $0 | $0.585 | **$0.62** | 33% worst case, 166% at 1 delivery per publish | Ably $2.50/M (4.0× ours); Pusher Pro $99 for 2,000 connections + 4M/day |
| Realtime connection-minutes | per million | $0.15 (upgrade: Worker + DO request $0.45/M, over 3 min) | $0.542 | **$0.25** | 67% | Ably $1.00/M (4× ours) |

Why these numbers:

- **Databases.** Memory is the binding resource (a CU is 4 GiB against 1 vCPU, and a 4 GB node has 2.5 GiB for pods), so the cost is per GiB of allocatable memory. $0.12/CU-h keeps 58% over that cost and stays under Laravel Cloud's $0.135. A 0.25 CU database on all month is $21.60 (was $24.80). Storage drops to $0.20: the old $0.455 was 4× the DO volume price.
- **Valkey.** One price per GiB-month of memory for the sleeping (flex) sizes, $18, which is the cache pool's cost at 70% packing plus 31%; caps rounded to $4.50 / $18 / $45. The Pro sizes are priced on the node the first tenant brings up, not on a share, because `pro-16` scales from zero and holds at most one node: a $60 Pro 5 GB would lose $25 a month until a second tenant arrived. That is why Pro 5 GB went **up** ($83.42 → $115) while Pro 12 GB went down ($195 → $150). **dply cannot match Laravel Cloud's flex Valkey** ($1.75 for 250 MB, a resold Upstash product) at 1.3× on 4 GB DOKS nodes that give pods 62% of their memory. The levers are bigger cache nodes (more allocatable per dollar; the 8 GB figure was not fetched) or requesting less than `maxmemory` per pod (overcommit).
- **Build time.** Real cost depends on how busy the minimum builder node is; at 15% of its 2 slots it is about 0.37¢ a minute (35% under the price, just above the 1.3 floor). $0.005 is Render's published rate (unverified) and 23% under the old price. The minimum node is also a fixed cost (§10), whatever the utilisation. Extra nodes only run while builds queue, so they are variable: a busy node (2 slots, 100%) costs $48 ÷ 86,400 min ≈ $0.00056 a build-minute, well under the price. (Before the builder pool: 4 slots on the control-plane worker, $0.00185.)
- **Realtime messages.** The review said real cost was "near $0". That holds for **deliveries** (outgoing frames are free) but not for **publishes**: each one is a Worker request and a DO request, about $0.466/M. The price has to cover a publish that nobody receives (a broadcast to an empty private channel): $0.62 is 1.33× that and still 4× under Ably's $2.50. **Fixed 2026-09-27:** the meter now counts publishes only and deliveries are free (ruling r-ez5s8c56zn0ry3sw), so a fan-out app (shape 6) no longer pays for free deliveries.
- **Realtime connection-minutes.** An idle hibernated socket costs nothing; a connection costs its upgrade. $0.25/M covers it at 1.3× for connections that average 2.3 minutes or more. Multi-page apps that reconnect on every page load for a few seconds are the losing case. Its absolute size is small ($0.45 per million page loads).
- **Not in the unit price:** the alarm that flushes realtime usage (one DO request + two row writes at most every 5 s per busy app, at most ~$1.11 per app per month, mostly inside Cloudflare's included 1M requests / 50M rows); Cloudflare's included allowances (10M Worker requests, 1M DO requests), which make the real marginal cost lower still at dply's size.

### Larger databases (1, 2, 4 CU)

**Was:** databases of 1, 2 or 4 CU could not be scheduled. An awake database requests its full memory (4, 8 or 16 GiB), and the `db` pool's s-2vcpu-4gb nodes have 2.5 GiB allocatable, so only 0.25 and 0.5 CU fit; the bigger sizes were hidden (`EdgeDplyDatabase::OFFERED_SIZES`).

**Now (ruling r-s56bk4pq4cnv8dt4):** two pools in `deploy/valkey/terraform`, both scaling from zero and tainted `dply.dev/db` like the `db` pool. The gateway (`placement.go` `databasePool`) sends a database over 2 GiB to `db-large` and one over 8 GiB to `db-xl`; a database resized across a pool boundary is recreated on the right pool at its next wake. The sizes are sold once `DPLY_DATABASE_LARGE_SIZES=true` (`dply.databases.large_sizes_enabled`, `EdgeDplyDatabase::LARGE_SIZES`); the owner applies the terraform first (`docs/launch-checklist.md`).

| Pool | Node | $/month | Allocatable | Holds awake | Sizes |
|---|---|---|---|---|---|
| `db-large` | s-8vcpu-16gb | $96 | 13 GiB, 8 vCPU | three 1 CU, or one 2 CU + one 1 CU | 1, 2 CU |
| `db-xl` | m-4vcpu-32gb | $168 | 28 GiB, 4 vCPU | one 4 CU | 4 CU |

Why these nodes: an awake database also requests up to one core (250m per GB, capped at 1000m). A memory-optimized m-2vcpu-16gb ($84) has about one spare core (estimate: DOKS does not publish allocatable CPU; ~1.9 cores less DaemonSet requests; confirm with `kubectl describe node` after apply), so it would hold **one** awake 1 CU database and cost $84 per 1 CU ($0.117/CU-h, under 1.3× at $0.12). The 8-vCPU basic node costs $12 more and lets memory bind again. 4 CU (16 GiB) fits neither 16 GB node (13 GiB allocatable), so it gets the 32 GB one; only one awake 4 CU fits (16 of 28 GiB), so its cost is priced as a whole node per awake 4 CU (`packing` 16/28). 2 and 4 CU get one guaranteed core and burst above it (no CPU limit).

**Price and the guarantee (ruling r-gd2vgb7jd1b4vqtf): large databases never lose money.** Two rules together:

1. **1, 2 and 4 CU always stay on.** The sleep choice is not offered (the sheet disables it; `EdgeAppDatabase` forces `suspend = -1` for them whatever is asked; the gateway's `staysOn` never idle-sleeps a database over 2 GiB). A sleeping large database would hold a whole node while billing almost nothing; an awake one bills every hour.
2. **Each size, alone on the node it brings up, pays ≥1.3× that node.** 1 CU at the flat $0.12 would bill $86.40 on a $96 node, so it has its own price: $96 × 1.3 ÷ 720 h = $0.1733, rounded to **$0.18/CU-h** (`database_compute_price_by_size`). 2 and 4 CU clear it at $0.12. The collector records a priced size's compute units scaled by its price (1 CU bills as 1.5 CU-seconds per second), so every bill, estimate and the size picker go through `UsagePrice::databaseBilledCu()`.

| Size | Pool / node | Node $/month | Always-on bill | Alone on the node | Shared (per CU-h cost → markup) |
|---|---|---|---|---|---|
| 1 CU | `db-large` s-8vcpu-16gb | $96 | **$129.60** ($0.18/CU-h) | 1.35× | $0.059 → +207% |
| 2 CU | `db-large` s-8vcpu-16gb | $96 | **$172.80** ($0.12/CU-h) | 1.80× | $0.059 → +105% |
| 4 CU | `db-xl` m-4vcpu-32gb | $168 | **$345.60** ($0.12/CU-h) | 2.06× | $0.058 → +106% |

`PricingModelTest` checks both: every large size alone on its node running all month bills ≥1.3× the node, and large sizes cannot sleep. `dply:billing:unit-costs` prints a "Database N CU alone" row per large size. A second tenant on the same node only adds margin. The first large database in a region takes a few minutes to start while its node boots.

## 10. Break-even

### Fixed monthly costs (`dply:billing:unit-costs`, estimates)

| Line | $/month |
|---|---|
| DOKS pool cache (2 × s-2vcpu-4gb) | 48.00 |
| DOKS pool db (2 × s-2vcpu-4gb) | 48.00 |
| DOKS HA control plane | 40.00 |
| DOKS load balancer (gateway) | 12.00 |
| Container registry | 5.00 |
| Builder pool minimum (1 × s-4vcpu-8gb; extra nodes are variable, see §9 Build time) | 48.00 |
| Control plane: web s-2vcpu-4gb / Postgres s-4vcpu-8gb / Redis s-2vcpu-4gb | 96.00 |
| Control plane: weekly backups (+20%) / Spaces | 24.20 |
| Cloudflare Workers Paid / Workers for Platforms | 30.00 |
| Email, domains (no error tracker installed; Sentry Team would add $26) | 10.00 |
| **Total** | **$361.20** |

**When the large database pools have a node:** `db-large` adds $96 and `db-xl` $168 a month (`dply:billing:unit-costs` lists them only when `nodes` > 0; they scale from zero, so they are not in the total above). With both up the total is **$625.20**; see the break-even row below.

Excluded: the owner's time and support. The minimum node pools are counted here as fixed; the §9 unit costs for databases and Valkey count that same capacity again, so the contributions below are conservative (the break-even is an upper bound).

### Contribution per customer shape (§5 workloads)

Contribution = bill − real variable cost − Stripe (3.6% + $0.30). Real variable cost uses Cloudflare list prices for pass-through meters, §9 for the fixed-price meters, and $0 for bandwidth.

| Shape | Plan | Bill | Real variable cost | Stripe | **Contribution** |
|---|---|---|---|---|---|
| 1 Hobby static | Starter | $5.00 | $0.05 | $0.48 | **$4.47** |
| 2 Marketing site | Starter | $5.00 | $0.88 | $0.48 | **$3.64** |
| 3 Next.js SSR | Pro | $20.00 | $7.22 | $1.02 | **$11.76** |
| 4a Laravel always-on | Pro | $91.10 | $65.73 | $3.58 | **$21.79** |
| 4b Laravel sleeping | Pro | $27.71 | $19.07 | $1.30 | **$7.34** |
| 5 Agency, 40 sites | Team | $49.00 | $1.86 (+ up to $4 of custom hostnames past Cloudflare's free 100) | $2.06 | **$45.08** |
| 6 Realtime 1,000 sockets | Pro | $20.00 | $1.26 | $1.02 | **$17.72** |
| Uses the whole credit on pass-through meters | Starter / Pro / Team | $5 / $20 / $49 | $3.85 / $15.38 / $37.69 | $0.48 / $1.02 / $2.06 | **$0.67 / $3.60 / $9.25** |
| Trial that never converts | — | $0 | up to $3.85 (the $5 cap at cost); typically well under $1 | — | **−$0.50 typical, −$3.85 worst** |

**No paying shape loses money per customer** after the Team credit fix. The thinnest is a Starter customer who spends the whole $5 credit on Cloudflare pass-through meters: $0.67 a month.

### Customers needed to cover $361.20 a month

| Mix | Avg contribution | Paying customers to break even |
|---|---|---|
| All shape-1 Starter | $4.47 | 81 |
| All shape-3 Pro | $11.76 | 31 |
| **50% Starter (shapes 1–2), 35% light Pro (3, 4b), 10% Pro always-on container (4a), 5% Team (5)** | **$9.80** | **37** |
| Same mix, 3 unconverted trials per customer at $0.50 each | $8.30 | 44 |
| All Starter using the whole credit | $0.67 | 539 |
| Mix above, `db-large` node up ($457.20) | $9.80 | 47 before the large database's own contribution (which covers the node) |
| Mix above, both large pools up ($625.20) | $9.80 | 64 before the large databases' own contribution; each always-on large database already covers its node (1 CU +$33.60, 2 CU +$76.80, 4 CU +$177.60 a month over the node) |

### What loses money, and what was done

| Case | Loss | Status |
|---|---|---|
| Team credit $50 on a $49 fee | $1/customer/month on every Team customer who used it | **Fixed**: credit $49 |
| First Pro Valkey 5 GB tenant on an empty `pro-16` node, priced as a share | ~$25/month | **Fixed**: priced on the whole node ($115) |
| Valkey 25/50 GB priced below the $336 node they force | $70/month on 25 GB | **Fixed** ($450/$600) and still not offered |
| Realtime publishes nobody receives, at the old $0.585 | no loss, but only 1.26× cost | **Fixed**: $0.62 (1.33×) |
| Databases of 1 CU and up can't be scheduled (4 GB db nodes) | not billable, not usable | **Fixed** (terraform `db-large` / `db-xl`, gateway placement); sold after the owner applies it and sets `DPLY_DATABASE_LARGE_SIZES` |
| First large database on an empty pool | would have been up to $96 / $168 a month asleep; 1 CU always on −$9.60 | **Fixed** (§9): large sizes always on; 1 CU $0.18/CU-h; every size ≥1.3× its node alone |
| Cloudflare for SaaS custom hostnames: $0.10/month each past 100 per account, unmetered | Team allows 100 per org: up to $10/month on a $49 plan | **Flagged**: fine at today's scale; meter or lower Team's cap once the account passes 100 hostnames |
| Workers for Platforms scripts: $0.02/month each past 1,000, unmetered | 1,000-app Team fair-use cap: up to $20/month | **Flagged**: same |
| Connections shorter than ~2.3 minutes on average | cents per million page loads | **Accepted** |
| Trials: usage never billed, $5 cap | up to $3.85 per trial | **Flagged**: with poor conversion and trials that use the cap, this is the largest drain (3 such trials per paying customer = $11.55, more than the average contribution). Owner's call: a lower cap or card checks |
