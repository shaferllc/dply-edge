---
title: "Billing & plans"
slug: billing-and-plans
category: "Billing"
order: 10
description: "How dply Edge bills: Free, Pro and Team plans, what each includes, usage past the plan, Enterprise, and Stripe setup."
---

# Billing & plans

dply Edge bills a **monthly plan** plus **usage past what the plan includes**. Previews are always free. Plans are defined in `subscription.standard.tiers` (`config/product/subscription.php`).

## Plans

| | Free | Pro | Team |
|---|---|---|---|
| Price | $0 | $20/mo | $49/mo |
| Sites | 1 | 10, then $2 each | 50, then $2 each |
| Worker SSR sites | — | $7 each | $7 each |
| Seats | 1 | 3 | 5, then $5 each |
| Build minutes / mo | 300, then builds pause | 1,000, then $0.006/min | 3,000, then $0.005/min |
| Concurrent builds | 1 | 2 | 5 |
| Build timeout | 20 min | 45 min | 60 min |
| Requests / mo | 1M | 10M | 50M |
| Egress / mo | 10 GB | 500 GB | 2 TB |
| Custom domains per site | 1 | 100 | 100 |
| Load balancing ($8/endpoint) | — | Yes | Yes |
| Audit log | — | — | Yes |

Allowances are per organization, per calendar month. A site counts once it is live (`edge_active`) and older than `min_billable_age_days`. Plans are **monthly only** for now.

## Usage past the plan

On Pro and Team, requests and egress past the plan are billed at a cost-floor rate plus a markup (default **40%**). R2 storage and operations keep per-site allowances (5 GB, 20k writes, 1M reads per site).

| Meter | Cost-floor rate |
|-------|-----------------|
| Requests | $0.50 / million |
| Egress | $0.05 / GB |
| R2 storage | $0.03 / GB-month |
| R2 Class A | $4.50 / million |
| R2 Class B | $0.36 / million |

Rates live under `edge.usage_billing` in `config/product/dply.php` (`DPLY_EDGE_USAGE_*`). Build-minute and delivery overage are billed together on the `edge_usage` line, in cents. Free orgs are never billed usage; their builds pause when the month's minutes run out.

Usage billing applies to **managed (`dply_edge`) sites only**. BYO Cloudflare (`org_cloudflare`) sites pay Cloudflare directly.

## Changing plans

Subscribing goes through Stripe Checkout. Switching between Pro and Team swaps the subscription's line items and invoices the prorated difference immediately. Moving to Pro is refused while the organization has more members than Pro's seats.

Subscriptions from before plans existed (per-site pricing, monthly or yearly) are moved onto their cheapest fitting plan by the next billing sync.

## Enterprise

Negotiated contracts are created manually in Stripe against `STRIPE_PRICE_ENTERPRISE`. Enterprise has no allowance caps.

## Stripe

Billing runs on Laravel Cashier and Stripe Checkout. Line items are reconciled by `SyncOrganizationBillingJob`, plus a nightly sweep (`php artisan dply:billing:sync-all`).

```bash
php artisan dply:billing:provision-stripe --dry-run   # preview
php artisan dply:billing:provision-stripe              # create (idempotent)
```

## Required environment

```env
STRIPE_KEY=pk_...
STRIPE_SECRET=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...

STRIPE_PRICE_TIER_PRO=price_...
STRIPE_PRICE_TIER_TEAM=price_...
STRIPE_PRICE_TEAM_SEAT=price_...
STRIPE_PRICE_STANDARD_EDGE=price_...          # extra site
STRIPE_PRICE_STANDARD_EDGE_SSR=price_...      # SSR site
STRIPE_PRICE_STANDARD_EDGE_USAGE=price_...    # usage, per cent
STRIPE_PRICE_STANDARD_EDGE_LB_ENDPOINT=price_...

STRIPE_PRICE_ENTERPRISE=price_...
```

## Related

- [Edge billing & usage](EDGE_BILLING.md) — the per-site billing tab
- [Organization roles & plan limits](ORG_ROLES_AND_LIMITS.md)
