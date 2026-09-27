# Pricing review (internal)

Written 2026-09-27 for the owner's review. It covers the whole pricing model in one place. Numbers come from `config/product/subscription.php`, `config/product/dply.php` (`edge.usage_billing`), `App\Modules\Billing\Support\UsagePrice`, `EdgeValkey`, `EdgeSizeLadder`, `EdgeAppDatabase` and `php artisan dply:billing:price-table plans|limits|fair-use|rates|sizes`, run on that date at the default **30% margin**. Rulings: r-2zxevg4sj675qn1m (model), r-bc0k0cta8e50x8vr (fair use), r-jnv0r3qf1xk49kmc (30% margin, $0.06 bandwidth, Starter limits, 30-day purge), r-f17p5zgeh120cm5t (trial). This document changed no product code when written. **Update, same day:** items 3, 6, 11–16 of §8 were then fixed in code and docs; each is marked **Fixed** below, and §3, §5, §6 and §7 are updated to match.

**Conventions used below.** A month is 720 h (2,592,000 s), the same as `EdgeAppDatabaseCost::monthly()`. "Cost" is the value in config, and it is not always a real provider cost (see §3). "Gross margin" is the bill minus that config cost. It leaves out Stripe fees (about 2.9% + $0.30 per invoice) and fixed platform costs: the Workers Paid and Workers for Platforms base fees, the database and Valkey cluster, build hosts and support.

---

## 1. The model in one paragraph

Each organization pays a monthly **plan fee**: Starter $5, Pro $20 or Team $49. Enterprise is sales-led. Team also pays **$5 for each seat past 10**. **Usage** is billed in arrears for each closed Stripe period. Every meter is priced at **cost × (1 + margin/100)**, and the margin is one setting (30%); the one exception is bandwidth, a fixed $0.06/GB. The plan's **included usage credit** ($5 / $20 / $50) then comes off the usage total, and usage can never go below $0. Sites and apps are unlimited, with a hidden fair-use cap on apps. Nothing metered is throttled on a paid plan. Owners get alert emails at 50%, 80% and 100% of a soft limit instead. There is no free plan. New orgs get a 5-day trial of the plan they choose, with a card up front and a $5 usage cap. Trial usage is never invoiced.

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
| Included usage credit | $5/mo | $20/mo | **$50/mo** (> fee) | n/a (usage billed by hand) |
| Credit ÷ fee | 100% | 100% | 102% | — |
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
| ⚠ Build time (`build_…_per_minute`) | build container time, per second; per minute | on build finish (`EdgeDeployment.build_seconds`) | **$0.005** | **dply** (the old Team overage rate; builds run on dply's own hosts) | $0.006 | **$0.0065** | $0.007 |
| vCPU (`container_vcpu_…`) | **active** CPU seconds (`cpuTimeSec`); per vCPU-s | container analytics, hourly + next day | $0.000020 | CF Containers | $0.000024 | **$0.000026** | $0.000028 |
| Memory (`container_memory_…`) | **provisioned** GiB × seconds awake | same | $0.0000025 | CF Containers | $0.000003 | **$0.00000325** | $0.0000035 |
| Disk (`container_disk_…`) | provisioned GB × seconds awake | same | $0.00000007 | CF Containers | $0.000000084 | **$0.000000091** | $0.000000098 |
| ~~Container bandwidth~~ | container `tx_bytes`: still collected, **no longer billed** (it double-counted delivery bandwidth, §8 item 6) | same | — | CF includes 1 TB/month in NA/EU | — | — | — |
| ⚠ Database compute (`database_compute_…_per_cu_second`) | compute-unit seconds awake (1 CU = 1 vCPU + 4 GB); Postgres/MySQL/MongoDB | `dply:edge:collect-valkey-usage`, hourly | **$0.0000294/s** ($0.106/CU-h) | **dply** ("Launch" rate, the old cost floor; not a measured cluster cost) | $0.0000353 | **$0.0000383** | $0.0000412 |
| ⚠ Database storage (`database_storage_…`) | disk GB-month, prorated by the hour, awake or asleep | hourly | **$0.35** | **dply** (same) | $0.42 | **$0.455** | $0.49 |
| D1 rows read | per M | hourly + next day | $0.001 | CF | $0.0012 | **$0.0013** | $0.0014 |
| D1 rows written | per M | same | $1.00 | CF | $1.20 | **$1.30** | $1.40 |
| D1 storage | per GB-month | same | $0.75 | CF | $0.90 | **$0.975** | $1.05 |
| Queue operations | per M | same | $0.40 | CF | $0.48 | **$0.52** | $0.56 |
| KV reads | per M | same | $0.50 | CF | $0.60 | **$0.65** | $0.70 |
| KV writes/deletes/lists | per M | same | $5.00 | CF | $6.00 | **$6.50** | $7.00 |
| KV storage | per GB-month | same | $0.50 | CF | $0.60 | **$0.65** | $0.70 |
| ⚠ Realtime connection-minutes | open socket-minutes; per M | hourly | **$0.4167** | **back-out** of old $0.50/M at 20% (`0.05/1.2`, `dply.php:374`). No CF per-unit price exists. | $0.50 | **$0.542** | $0.583 |
| ⚠ Realtime messages | published in + delivered out; per M | hourly | **$0.45** | **dply** (comment says "our Cloudflare cost", `dply.php:368`). CF DO bills incoming WebSocket messages at **20:1** as requests ($0.0075/M effective), **outgoing is free**, and hibernation removes duration. | $0.54 | **$0.585** | $0.63 |
| ⚠ Valkey (per class, per second awake, monthly cap) | awake seconds × class rate, capped per app | hourly | see §4 | **back-out** of the owner's 2026-09-24 table at 20% (`EdgeValkey.php:29-43`). Not a cluster cost. | — | §4 | — |
| Workers CPU | per M CPU-ms | hourly + next day | $0.02 | CF | $0.024 | **$0.026** | $0.028 |
| DO requests | per M | same | $0.15 | CF | $0.18 | **$0.195** | $0.21 |
| DO duration | per M GB-s | same | $12.50 | CF | $15.00 | **$16.25** | $17.50 |
| DO rows read / written | per M | same | $0.001 / $1.00 | CF | $0.0012 / $1.20 | **$0.0013 / $1.30** | $0.0014 / $1.40 |
| DO storage | per GB-month | same | $0.20 | CF | $0.24 | **$0.26** | $0.28 |
| Bucket storage / Class A / Class B | GB-month / M / M | same | $0.015 / $4.50 / $0.36 | CF R2 | $0.018 / $5.40 / $0.432 | **$0.0195 / $5.85 / $0.468** | $0.021 / $6.30 / $0.504 |
| Image transformations | per 1,000 unique | same | $0.50 | CF Images | $0.60 | **$0.65** | $0.70 |

### Meters whose "cost" is not a real provider cost

1. **Bandwidth.** Cloudflare charges $0 for Worker and R2 egress, so this whole line is margin. **Fixed:** it is now a fixed customer price ($0.06) in `fixed_price_meters`, so it no longer moves with the margin (r-jnv0r3qf1xk49kmc). Its "cost" in `UsagePrice::cost()` is backed out at the current margin only so the shared arithmetic lands on exactly $0.06.
2. **Valkey.** Backed out at 20%. At 30%, every Valkey price is 8.3% higher than the owner's 2026-09-24 table (0.25 cap $6.00 → $6.50). The per-second rates also match Laravel Cloud **compute** rates exactly (see §8).
3. **Realtime connection-minutes.** Backed out at 20%.
4. **Realtime messages.** "$0.45/M" is not a Cloudflare list price. With hibernation, real DO cost for fan-out traffic is close to $0.
5. **Database compute and storage.** dply "Launch" figures, not a measured cost of the database cluster.
6. **Build minute.** A dply figure. Builds run on dply's own hosts.

### Not metered at all (dply pays, customer doesn't)

`EdgePlatformUsageCollector.php:27-29` and `EdgeContainerConnections.php:68-72` confirm that **Workers AI**, **Browser Rendering** and **Vectorize** are unmetered. They have no per-script dimension in Cloudflare analytics. They are limited only by being unavailable on the trial. **Hyperdrive** (`database_pool`), Workers Logs / Analytics Engine datasets and DO requests from container front-Workers beyond what the collector attributes are also dply overhead.

---

## 4. Size ladder at 30%

One ladder (`EdgeSizeLadder::RUNGS`). "Always-on" means 720 h. Container prices on the pricing page and in size pickers assume **100% CPU busy**. Cloudflare bills vCPU on active use, so real bills are lower. A 25% CPU column is shown for that reason.

| Rung | Container app (mem / disk) | App $/s (100% CPU) | App always-on /mo @100% CPU | @25% CPU | dply cost /mo @100% | Database (mem) | DB $/s | DB always-on /mo (+ storage) | DB cost /mo | Valkey (mem) | Valkey $/s | Valkey always-on /mo (= cap) | Valkey cost /mo | Valkey sleeps? |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1/16 vCPU (off-ladder, non-PHP) | 256 MB / 2 GB | $0.00000262 | $6.79 | $3.63 | $5.22 | — | — | — | — | — | — | — | — | — |
| 0.25 vCPU | 1 GB / 4 GB | $0.0000101 | $26.22 | $13.58 | $20.17 | 1 GB | $0.00000957 | $24.80 | $19.08 | 250 MB | $0.00000269 | $6.50 | $5.00 | yes |
| 0.5 vCPU | 4 GB / 8 GB | $0.0000267 | $69.28 | $44.01 | $53.29 | 2 GB | $0.0000191 | $49.61 | $38.16 | 1 GB | $0.0000107 | $26.00 | $20.00 | yes |
| 1 vCPU | 6 GB / 12 GB | $0.0000466 | $120.77 | $70.22 | $92.90 | 4 GB | $0.0000383 | $99.22 | $76.32 | 2.5 GB | $0.0000215 | $52.00 | $40.00 | yes |
| 2 vCPU | 8 GB / 16 GB | $0.0000795 | $205.95 | $104.86 | $158.42 | 8 GB | $0.0000766 | $198.43 | $152.64 | 5 GB | $0.0000345 | $83.42 | $64.17 | no (always on) |
| 4 vCPU | 12 GB / 20 GB | $0.000145 | $375.37 | $173.20 | $288.75 | 16 GB | $0.000153 | $396.86 | $305.28 | 12 GB | $0.0000806 | $195.00 | $150.00 | no (always on) |

Database storage is extra at $0.455/GB-month. Valkey reaches its monthly cap after about 671 h awake, so an always-on Valkey is billed at its cap. Container apps have **no monthly cap**. Memory dominates their cost: 4 GB always-on is $33.70/mo at 30% before any CPU.

---

## 5. Worked examples at 30%

These examples use the assumptions below. Reads from site storage are **cache misses** (10% of requests). Container CPU is billed on active use (25% for the web app, 50% for the worker). Realtime messages are counted as delivered frames. "Cost" is config cost. "True-bandwidth GM" re-runs gross margin with bandwidth at Cloudflare's real $0.

### Summary

| # | Shape | Plan | Usage @ price | Credit | **Bill** | Config cost | **Gross margin** | GM % | True-bandwidth GM |
|---|---|---|---|---|---|---|---|---|---|
| 1 | Hobby static site | Starter | $0.22 | −$0.22 | **$5.00** | $0.17 | **$4.83** | 97% | $4.92 |
| 2 | Marketing site, 2M req + 50 GB | Starter | $4.40 | −$4.40 | **$5.00** | $3.38 | **$1.62** | 32% | $3.92 |
| 2b | same, on Pro | Pro | $4.40 | −$4.40 | **$20.00** | $3.38 | **$16.62** | 83% | $18.92 |
| 3 | Next.js SSR, team of 3 | Pro | $16.61 | −$16.61 | **$20.00** | $12.78 | **$7.22** | 36% | $11.84 |
| 4a | Laravel container + Postgres + Valkey + worker, **always-on** | Pro | $97.75 | −$20.00 | **$97.75** | $75.19 | **$22.56** | 23% | $23.48 |
| 4b | same, **sleeping** (awake 200 h) | Pro | $30.64 | −$20.00 | **$30.64** | $23.57 | **$7.07** | 23% | $7.99 |
| 5 | Agency, 40 small sites, 5 people | Team | $6.79 | −$6.79 | **$49.00** | $5.22 | **$43.78** | 89% | $45.63 |
| 6 | Realtime-heavy, 1,000 concurrent sockets | Pro | $77.30 | −$20.00 | **$77.30** | $59.46 | **$17.84** | 23% | $19.22 (real CF realtime cost ≈ $0: ~$75 / ~97%) |

**The pattern.** Once usage passes the credit, gross margin is exactly **margin/(1+margin) = 23% of the usage price** on Starter and Pro. On Team it is 23% minus $1, because the credit is $1 larger than the fee. The plan fee contributes nothing net for any customer who uses their credit. Fixed costs and Stripe fees come out of that 23%.

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

App 0.5 vCPU / 4 GB / 8 GB disk at 25% CPU. Worker 0.25 vCPU / 1 GB at 50% CPU. Postgres 0.25 CU with a 5 GB disk. Valkey 0.25 (250 MB). 1M requests, 20 GB out, 30 builds × 4 min.

| Line | Always-on cost | Always-on price | Sleeping (200 h) cost | Sleeping price |
|---|---|---|---|---|
| Requests 1M | $0.30 | $0.39 | $0.30 | $0.39 |
| Bandwidth 20 GB (delivery) | $0.92 | $1.20 | $0.92 | $1.20 |
| App 0.5 vCPU / 4 GB | $33.85 | $44.01 | $9.40 | $12.22 |
| Worker 0.25 vCPU / 1 GB | $13.69 | $17.79 | $3.80 | $4.94 |
| Postgres 0.25 CU compute | $19.08 | $24.80 | $5.30 | $6.89 |
| Postgres storage 5 GB | $1.75 | $2.27 | $1.75 | $2.27 |
| Valkey 0.25 | $5.00 (cap) | $6.50 (cap) | $1.49 | $1.93 |
| Build time 120 min | $0.60 | $0.78 | $0.60 | $0.78 |
| **Usage** | **$75.19** | **$97.75** | **$23.57** | **$30.64** |
| **Bill** = $20 − $20 + usage | | **$97.75** | | **$30.64** |
| **Gross margin** | | **$22.56 (23%)** | | **$7.07 (23%)** |

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

1,000 sockets open 24/7, with a broadcast to all of them every 30 s (86.4M delivered messages). 3M HTTP requests, 30 GB.

| Line | Qty | Cost | Price |
|---|---|---|---|
| Requests | 3M | $0.90 | $1.17 |
| Bandwidth | 30 GB | $1.38 | $1.80 |
| Connection-minutes | 43.2M | $18.00 | $23.40 |
| Messages | 86.4M | $38.88 | $50.54 |
| Workers CPU | 15M ms | $0.30 | $0.39 |
| **Usage** | | **$59.46** | **$77.30** |
| $20 − $20 + $77.30 | | | **Bill $77.30** (GM $17.84 on config cost) |

At Cloudflare's actual DO pricing, the real realtime cost is well under $1: outgoing messages are free, incoming messages bill at 20:1, and hibernation removes duration. The $56.88 of "cost" is almost all margin. Growth past 1,000 sockets per app forces Team.

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
- Laravel Cloud Postgres, Valkey and Reverb unit prices. Its docs page did not list them.
- Pusher prices.

All competitor figures below are **estimates** for the same workload.

| Shape | **dply** | Laravel Cloud | Vercel | Netlify | Render | Railway |
|---|---|---|---|---|---|---|
| 1 Hobby static | **$5** | ~$5 (Starter; first month free) | $0 (Hobby, non-commercial only) | $0 (Free: ~200 credits) | $0 (static site, unverified) | ~$5 (Hobby) |
| 2 Marketing 2M req + 50 GB | **$5** (Starter) | ~$5–11 (Starter + sleeping Flex; 50 GB included with Flex 512) | $20 (Pro, well inside included) | ~$19–20 (≈1,850 credits: Personal + packs, or Pro) | ~$0–19 (unverified) | ~$5–8 |
| 3 Next.js SSR, 3 people | **$20** | n/a (PHP only) | ~$60 (3 × $20 seats, unverified per-seat) | ~$20–40 (≈5,900 credits) | ~$25 + workspace seats (unverified) | ~$20–25 |
| 4a Laravel always-on | **$97.75** | ~$40–60 (Pro 4 GiB app $32 cap + Flex worker $6–12 + Postgres/Valkey, unverified; Growth $20 − $20 credit) | n/a | n/a | ~$45–60 (Standard $25 + worker $7 + PG ~$19 + KV ~$10, unverified) | ~$25–35 (usage-based RAM/CPU) |
| 4b Laravel sleeping (200 h) | **$30.64** | ~$10–20 (LC's own "SaaS MVP 240 h" scenario is ~$34 of usage with 2 larger workers) | n/a | n/a | same as 4a (no scale-to-zero on paid instances) | ~$10–15 |
| 5 Agency 40 sites, 5 people | **$49** | n/a (per-app compute) | ~$100 (5 seats, unverified) | ~$40 (≈5,840 credits) | ~$0 static + team seats (unverified) | n/a (not a static host) |
| 6 Realtime 1,000 sockets | **$77.30** | Reverb 2,000-connection tier (price unverified) | n/a | n/a | n/a | self-host (~$10–20) |
| Pusher Channels (unverified) | — | — | — | — | — | Pro ~$99 for 2,000 connections |

**Takeaways.**

- dply is cheapest or at parity for **static and SSR** (shapes 1, 2, 3 and 5), mainly because there are no seat fees on Starter or Pro and Team is flat.
- dply is **roughly 2× Laravel Cloud and Render on always-on containers** (4a). The reason is Cloudflare Containers memory pricing: $6.48/GB-month cost, $8.42 at price. dply also has no monthly cap. Laravel Cloud caps Flex 1 GiB at $12 and Pro 4 GiB at $32.
- On realtime, $77 for 1,000 sockets is competitive with Pusher, but every dollar of it is unrelated to actual cost.

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
| Valkey prices | **Code**: `EdgeValkey::CLASSES` (`EdgeValkey.php:37-43`) | none | Via `UsagePrice::valkeyPerSecond` | Code change and deploy |
| Size ladder, instance shapes, DB CU per rung | **Code**: `EdgeSizeLadder`, `EdgeContainerSettings::INSTANCE_TYPES`, `EdgeAppDatabase::POSTGRES_SIZES` | none | Pricing page, pickers | Code change |

---

## 8. Risks, open questions, inconsistencies

### Economics

1. **Credit equals fee.** Every customer who uses their credit pays nothing net for the plan. Gross margin on them is 23% of usage (Starter, Pro) or 23% minus $1 (Team, `subscription.php:114`: credit $50 > fee $49). That 23% must cover Stripe (~3% + $0.30), fixed Cloudflare/cluster/build-host costs and support. Starter customers with small usage and bandwidth-heavy sites are where the money is. Options: credit < fee (e.g. $3 / $15 / $40), or a higher margin on low-cost meters only.
2. **Always-on containers are ~2× Laravel Cloud** (§6). Size pickers quote 100% CPU (`UsagePrice::containerPerSecond`, `pricing-calculator.blade.php:23`), which makes them look even worse than the real bill. Consider a monthly cap per size, like Valkey and Laravel Cloud have, or a lower memory markup.
3. **Bandwidth pricing. Fixed (the tie to the margin).** $0.06/GB is pure margin (CF egress $0), undercutting Laravel Cloud ($0.10), Render ($0.10), Vercel ($0.15) and Netlify (~$0.13). It is also the biggest single line for static customers. It is fine as a decision, but it is tied to the 30% margin through `6000/1.3` (`dply.php:324`), which contradicted r-jnv0r3qf1xk49kmc. Now `dply.edge.usage_billing.fixed_price_meters` lists `egress_millicents_per_gb`, whose value is the customer price; `UsagePrice::cost()` backs the cost out at the current margin, so every path (rates, invoices, pricing page, calculator) prices it at exactly $0.06 at any margin. Tested at 20/30/50% (`PricingModelTest`). Note `DPLY_USAGE_EGRESS_MC_PER_GB` now sets a **price**.
4. **Unmetered AI, Browser Rendering and Vectorize** (`EdgePlatformUsageCollector.php:27-29`, `EdgeContainerConnections.php:68-72`). A paid Starter org can run unbounded Workers AI or Browser Rendering on dply's account for $5. They are gated only by "not on trial". Needs a per-org cap, a separate account, or a kill switch.
5. **Realtime is priced far above cost.** Messages at $0.585/M and connection-minutes at $0.542/M sit against a real DO cost near $0, if the relay uses hibernation. It is safe for margin, but uncompetitive and hard to defend if someone does the math. Pro's 1,000-connection cap equals the example customer, so growth forces Team.
6. **Container egress was billed twice. Fixed.** Verified in code: `EdgeUsageCollector::billableEdgeSites()` takes every active site with an `edge_backend`, container apps included, and sums zone `edgeResponseBytes` for the site's hostnames. A visitor's request to a container app enters through its Worker on that hostname, so the response bytes are in `edgeResponseBytes`. `EdgeContainerUsageCollector` sums the container's `txBytes`. **Inferred, not verified** (Cloudflare's docs don't say what `txBytes` or container egress covers): `txBytes` counts the same bytes again on the container → Worker hop, and `EdgeContainerComputeCost` billed them again at $0.0325/GB. Fix: container `tx_bytes` is still collected but no longer billed, and the "Container bandwidth" rate and its config key are gone. Delivery bandwidth was kept rather than excluding container sites from it, because (a) it is the price the ruling fixes ($0.06), the same for every site; (b) excluding container hostnames would also drop static assets their front Worker serves from R2; and (c) what `tx_bytes` adds beyond visitor responses is the container's own outbound calls, which Cloudflare covers with 1 TB/month in NA/EU. Ceiling: a container that pushes lots of outbound traffic (uploads to a third-party API) is not billed for it; bill `max(0, tx − delivery)` if that shows up. Test: `EdgeContainerComputeBillingTest`.
7. **Invented costs.** Database CU ($0.106/h) and storage ($0.35), build minutes ($0.005), Valkey (backed out at 20%) and realtime (backed out or dply-set) are not measured infra costs. Gross margins in §5 for shapes 4 and 6 are therefore unknown, not 23%. Measure real per-CU and per-GB cluster cost before relying on them.
8. **Valkey mirrors Laravel Cloud compute, not Laravel Cloud Valkey.** `EdgeValkey.php:37` flex_250m is `0.00000248/1.2` with a $6 cap. That is exactly LC's **Flex 512 MiB compute** rate ($0.00000248016/s, $6 cap). flex_1g equals LC Flex 2 GiB ($0.00000992, $24). The LC docs fetch suggested LC's own Valkey 250 MB is far cheaper (~$1.70 cap, unverified).

### Trial

9. **Trial abuse and overshoot.** Each trial costs dply up to $3.85 (the $5 cap at cost) and is never billed. Enforcement is hourly (`DplySchedule.php`: `EnforceOrganizationBillingCommand` hourly), so running containers can overshoot the cap by up to an hour of usage. The eligibility checks (owner and card fingerprint) are the only abuse guard. Unmetered AI and Browser are correctly blocked on trial.
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

- Should the credit stay equal to the fee (item 1)?
- Cap container months, or reprice memory (item 2)?
- ~~Is bandwidth a fixed $0.06 regardless of margin (item 3)?~~ Answered by r-jnv0r3qf1xk49kmc and now enforced in code.
- How should AI and Browser be metered or capped (item 4)?
- Reprice realtime toward real cost (item 5)?
- Do we grandfather old Stripe prices (item 11)? The code now supports both (list the old id to grandfather), but decide before any price change.
