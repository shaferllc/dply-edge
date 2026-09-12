---
title: "Billing & plans"
slug: billing-and-plans
category: "Billing"
order: 10
description: "How dply Edge bills: a per-site platform fee plus metered delivery usage, a three-site free allowance, annual pricing, Enterprise, and Stripe setup."
---

# Billing & plans

dply Edge bills **per live production site plus metered delivery usage**. There are no seat or server tiers. Previews are always free.

## Free allowance

An organization can run **up to three production Edge sites without a card**. Subscribing removes the cap. The allowance is `plans.free.max_edge_apps` in `config/product/subscription.php`.

## Platform fee

| Site type | Per live production site |
|-----------|--------------------------|
| Static / hybrid | $2/mo (`edge_cents`) |
| Worker SSR | $7/mo (`edge_ssr_cents`) |

A site is billable once it is live (`edge_active`) and older than `min_billable_age_days`.

## Delivery usage

Each live site includes, every month:

| Meter | Included per site |
|-------|-------------------|
| HTTP requests | 1M |
| Egress | 100 GB |
| R2 storage | 5 GB |
| R2 Class A ops (writes) | 20k |
| R2 Class B ops (reads) | 1M |

Usage above the allowance is billed at a cost-floor rate plus a markup (default **40%**):

| Meter | Cost-floor rate |
|-------|-----------------|
| Requests | $0.50 / million |
| Egress | $0.05 / GB |
| R2 storage | $0.03 / GB-month |
| R2 Class A | $4.50 / million |
| R2 Class B | $0.36 / million |

All of these live under `edge.usage_billing` in `config/product/dply.php` and can be overridden with the `DPLY_EDGE_USAGE_*` env vars. Usage billing is on by default (`DPLY_EDGE_USAGE_BILLING_ENABLED`).

Usage billing applies to **managed (`dply_edge`) sites only**. BYO Cloudflare (`org_cloudflare`) sites publish into the customer's own account and pay Cloudflare directly.

## Annual

Yearly prices are **20% off** monthly × 12 (`annual_discount_pct`). Every line item on a subscription uses the same interval, because Stripe Checkout requires it.

## Enterprise

Negotiated contracts are created manually in Stripe against `STRIPE_PRICE_ENTERPRISE`.

## Stripe

Billing runs on Laravel Cashier and Stripe Checkout. Subscription line items are reconciled against live sites and usage by `SyncOrganizationBillingJob`, plus a nightly sweep (`php artisan dply:billing:sync-all`).

Provision the Stripe products and prices once per environment:

```bash
php artisan dply:billing:provision-stripe --dry-run   # preview
php artisan dply:billing:provision-stripe              # create (idempotent)
```

## Required environment

```env
STRIPE_KEY=pk_...
STRIPE_SECRET=sk_...
STRIPE_WEBHOOK_SECRET=whsec_...

STRIPE_PRICE_STANDARD_EDGE=price_...
STRIPE_PRICE_STANDARD_EDGE_YEARLY=price_...
STRIPE_PRICE_STANDARD_EDGE_SSR=price_...
STRIPE_PRICE_STANDARD_EDGE_SSR_YEARLY=price_...
STRIPE_PRICE_STANDARD_EDGE_USAGE=price_...

STRIPE_PRICE_ENTERPRISE=price_...
```

## Related

- [Edge billing & usage](EDGE_BILLING.md) — the per-site billing tab
- [Organization roles & plan limits](ORG_ROLES_AND_LIMITS.md)
