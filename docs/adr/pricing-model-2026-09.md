# Pricing model: three plans + usage credit + one margin

Ruling **r-2zxevg4sj675qn1m** (2026-09-27, owner-direct). Supersedes the tier
and allowance parts of r-zdescb7y05vp1bxx. The 5-day card-up-front trial and
its $5 cap (r-f17p5zgeh120cm5t) stay. Realtime's separate allowances
(r-p3dsj9znvtnyhphr) are replaced by the plan credit.

## Plans (config: `subscription.standard.tiers`)

| key | label | price | seats | included usage credit | extra seat |
|---|---|---|---|---|---|
| starter | Starter | $5 | 1 | $5 | — (hard 1) |
| pro | Pro | $20 | 3 | $20 | — (hard 3) |
| team | Team | $49 | 10 | $50 | $5 each |
| enterprise | Enterprise | contract | — | — | — (sales-led, as today) |
| none | (no plan) | — | — | $0 | — |

- The trial is the plan chosen at checkout, with the $5 trial cap (ruling r-jnv0r3qf1xk49kmc). `billingTier()` semantics unchanged.
- Credits are **per-plan config values** (`usage_credit_cents`) so the owner
  can lower them without code changes.
- Non-price limits stay per plan (build concurrency, build timeout, custom
  domains per org, databases/queues counts, worker instances, realtime
  connection cap, audit log, containers allowed…). Starter: containers
  allowed? — yes but 1 app instance max, 1 build at a time, 20 min timeout,
  3 custom domains, 2 databases, 2 queues, no audit log.
- **Removed:** per-site fees (`edge_cents`, `edge_ssr_cents`, extra-site
  counting), and every per-meter allowance (`requests`, `egress_gb`,
  `build_minutes`, `compute_credit_cents`, `realtime_connection_minutes`,
  `realtime_messages`, `included_*_per_site`, KV's free GB, etc.). Sites are
  unlimited on every plan.

## One margin (config: `dply.edge.usage_billing.margin_percent`)

- `margin_percent` default **30** (owner, ruling r-jnv0r3qf1xk49kmc; was 20), env `DPLY_USAGE_MARGIN_PERCENT`. The
  single knob. Replaces `markup_percent` (25) and every hard-coded markup.
- Every meter's config value is the **provider cost** (Cloudflare list /
  our infra cost for databases & Valkey). Customer price =
  `cost × (1 + margin/100)`, applied in ONE helper
  (e.g. `App\Modules\Billing\Support\UsagePrice::customer(int|float $costMillicents)`).
  No cost class applies its own markup.
- The pricing page, docs, billing page and invoices all derive displayed
  prices from the same helper, so changing the margin updates everything.

## Meters

Time-based, **billed per second while awake** (asleep = $0):
- Container apps & queue workers: vCPU-seconds, GiB-memory-seconds,
  GB-disk-seconds (+ container egress per GB).
- dply databases (Postgres/MySQL/MongoDB): compute CU-seconds + storage
  GB-month (storage is per unit, prorated by second held).
- Valkey: per second awake by size.
- Durable Objects duration (GB-s) — already per second.

Per unit:
- Requests, bandwidth (egress GB), site/build storage GB-month, customer
  bucket storage & ops, KV reads/writes/storage, D1 rows/storage, queue
  operations, realtime connection-minutes & messages, Workers CPU-ms, DO
  requests/rows/storage, image transformations, build minutes (per second of
  build time).

## Invoicing

- Renewal invoice: plan fee + one line per usage category (cost × margin),
  then one negative line **"Included usage credit"** = min(credit, usage
  total). Usage never goes below $0. UsageInvoicer already bills in arrears
  per Stripe period — extend it, don't replace it.
- Stripe sync: plan price + extra seats only. Remove the `edge`/`edge_ssr`
  per-site lines from subscriptions without proration/credits.
- Trial: the $5 cap is checked against usage at customer price (no credit
  applied during trial).
- Billing page / forecast: show "Usage this period $X · included credit $Y ·
  estimated charge $Z".

## Hosting choices (UI)

Create page offers **Site** (static) and **App** (dply picks Worker SSR or
container from detection). Hybrid under **Advanced**. No per-site prices
anywhere.

## Sizes

One ladder shared by container apps, databases and Valkey:
`0.25 vCPU`, `0.5 vCPU`, `1 vCPU`, `2 vCPU`, `4 vCPU` (memory per rung per
product), same display names everywhere; per-second price shown from the
helper. Map existing size keys (Lite/Flex/Small/… , Valkey classes, CU
sizes) onto the ladder without breaking existing rows (keep stored keys,
change labels + pricing source).

## Leftovers to clean up in the rebuild
- `subscription.standard.annual_discount_pct` (monthly only — remove).
- "Billed annually" label in `resources/views/livewire/billing/analytics.blade.php`.
- Per-site copy in `bill-hero` ("$X/mo per extra site").
- Unreachable "Pay as you go" status branch on the billing page.
- The docs pages pricing.md, usage.md, free-trial.md, spending-alerts.md,
  invoices.md and every resource page's "Pricing" table must be regenerated
  from the helper's numbers (cost × margin).
- In-app pricing hints: Resources sheets' cost lines, create page, Valkey /
  database / container size pickers, bucket Costs tab.

## Fair-use app limit (owner approved 2026-09-27)
- Sites/apps are unlimited on the pricing page; a hidden anti-abuse cap per
  plan (`fair_use_apps`): Starter 25, Pro 250, Team 1,000, Enterprise null,
  none 0. Counts non-preview apps. At the cap, creating an app shows
  "You've reached the fair-use limit for your plan — contact us to raise it"
  (no charge, no upgrade push). One config value per plan.
- Docs (`pricing.md`) mention "Unlimited sites, subject to fair use" with the
  numbers in a small table; the marketing pricing page says "Unlimited sites".
