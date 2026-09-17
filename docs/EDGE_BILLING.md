---
title: "Edge billing & usage"
slug: edge-billing
category: "Edge"
order: 140
description: "Summarizes per-site Edge charges including the platform fee and month-to-date CDN request and bandwidth usage, with a link to org-wide billing."
group: edge
---

# Edge billing & usage

**Billing & usage** summarizes Edge-specific charges for this site. Org-wide invoices live under **Settings → Billing**.

> Preview child sites do not include this tab. Previews are free; billing applies to the parent production site.

## Plan

Your organization's plan (Free, Pro or Team) includes a number of sites. On Pro and Team, sites past that count cost $2/mo each and Worker SSR sites $7/mo each. See [Billing & plans](BILLING_AND_PLANS.md).

## Usage this month

When usage billing is enabled for your org, see month-to-date:

- **CDN requests**
- **Bandwidth (egress GB)**

Requests and egress allowances come from your plan and are shared across the organization. Usage past them is metered on Pro and Team.

## Usage charts

Historical charts help spot traffic spikes after launches or marketing campaigns. Compare with **Traffic & analytics** for the same period.

## Link to org billing

**View organization billing** opens **Billing analytics** for invoices, payment method, and combined BYO + managed product lines.

## Managed vs BYO

- **Managed delivery** — usage collected from dply’s Cloudflare analytics integration
- **BYO Cloudflare** — pass-through usage may still apply for dply platform fees; CDN usage details may be in Cloudflare directly

## Suspended or deleted sites

Deleting an Edge site stops any extra-site fee after teardown completes. Historical invoices remain in **Billing → Invoices**.
