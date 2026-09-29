---
title: "Edge billing & usage"
slug: edge-billing
category: "Edge"
order: 140
description: "What this site has used this period, how it sits against the plan's included usage credit, with a link to org-wide billing."
group: edge
---

# Edge billing & usage

**Billing & usage** shows what this site has used in the current billing period. Org-wide invoices live under **Settings → Billing**.

> Preview child sites do not include this tab. Preview usage (builds, traffic, container compute) bills against the organization's plan like production.

## Summary

The tab opens with one sentence: "This site has used $X so far", noting when all or most of it is one category (for example "— mostly delivery"), then how it sits against the plan:

- **Covered** — "Your <Plan> plan's usage credit covers it, so you won't be charged for it."
- **Past the credit** — "Your workspace is past its <Plan> usage credit, so usage from here is billed at the end of the period."
- **No plan credit** — "Usage is billed on the organization plan."

Sites have no fee of their own. See [Billing & plans](BILLING_AND_PLANS.md).

## Credit bar

When the plan includes a usage credit, a bar shows the **whole workspace's** usage against it, split into **This site** and **Other sites and projects**. The credit is applied org-wide at invoice time.

## Cost lines

One row per cost line, with its detail and amount: **Delivery** (requests, egress GB, storage), build time, compute, Valkey, realtime and whatever else the site used.

Two rows expand:

- **Monthly quota** — soft cap on requests and bandwidth, with an **OK** / **Warn** / **Over** badge (warn at 80%, flag at 100%). Checked daily.
- **Daily activity** — daily requests and egress charts, plus daily compute. Appears once daily data exists (after the first nightly collection).

Prices are before the included usage credit. Databases bill per project on the org billing page, not here.

## Link to org billing

**Open org billing** opens the organization's billing page to compare all Edge sites and see invoices.

## Managed vs BYO

- **Managed delivery** — usage collected from dply’s Cloudflare analytics integration
- **BYO Cloudflare** — Cloudflare bills delivery directly. dply does not add a delivery meter for these sites.

## Suspended or deleted sites

Deleting an Edge site stops its usage after teardown completes. Historical invoices remain in **Billing → Invoices**.
