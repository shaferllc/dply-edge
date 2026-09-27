---
title: "Invoices & taxes"
description: "What each dply invoice contains, when usage is charged, how plan and seat changes are prorated, what cancelling bills, and how taxes are handled."
---

dply bills through Stripe. Each organization has one subscription, renewed monthly on the day it started. Its invoices carry the plan fee for the month ahead and the usage from the month that has ended. This page explains each kind of invoice and each line on it.

## Your monthly invoice

On each renewal date, Stripe creates one invoice with two kinds of lines:

| Charged | Lines |
|---|---|
| In advance, for the period that is starting | The plan fee, and extra seats on Team |
| In arrears, for the period that has ended | One line per usage category, then the included usage credit as a negative line |

The usage lines are labeled by category, each with the dates they cover:

| Invoice line | What it includes |
|---|---|
| Delivery (requests, bandwidth, site storage) | Requests, bandwidth, and site storage and its writes and reads |
| Build time | Time in the build container, billed per second |
| Apps and workers (compute) | Container app and queue worker vCPU, memory and disk (their traffic to visitors is under Delivery) |
| Databases | Postgres, MySQL and MongoDB compute and storage |
| Valkey | Valkey time by size |
| SQL, queues and key-value | Edge SQL (D1), queues, and key-value |
| Realtime | Connection-minutes and messages |
| Workers CPU, Durable Objects, object storage and images | Workers CPU time, Durable Objects, object storage buckets, and image transformations |
| AI, browser rendering and vector search | Workers AI neurons, browser time, and vector search dimensions. Capped per organization each month (set on the Billing page) |
| Included usage credit | A negative line: the smaller of your plan's included credit or the usage total above |

A usage line appears only when it's more than zero. The included usage credit line appears only when you had usage to apply it to. If you used nothing, the invoice has no usage lines. Each line is rounded to the nearest cent.

The invoice is charged to your card automatically.

### Your first invoice

Your trial ends on day 6, and the first invoice charges the plan fee and any extra seat lines for the month ahead. Trial usage is never billed, so the first invoice has no usage lines. See [Free trial](/docs/free-trial).

## Changes during the month

Changes that affect the in-advance lines are prorated and invoiced right away, so you aren't surprised by them at renewal.

| Change | What is billed |
|---|---|
| Switch plans | The prorated difference for the rest of the period, invoiced immediately. |
| A Team member joins past the included seats | A prorated $5 charge for the rest of the period. |
| A member leaves Team | The unused part of the seat charge is credited, and the credit is applied to your next invoice. |

Seat lines are updated when dply syncs your billing: after each billing event from Stripe, and once a day. Each update is invoiced right away rather than held for the renewal.

During the trial none of this is prorated: the lines change, and the first invoice charges them in full.

Usage changes don't cause an invoice. Usage is always billed on the next renewal invoice.

To switch plans, open your organization's **Billing** page and, under **Plan**, choose the plan you want. You can't switch to a plan with fewer seats than the organization has members.

## Cancel

On the **Billing** page, under **Cancel or resume**, choose **Cancel subscription**. You keep full access until the end of the period you've paid for, and you can choose **Resume subscription** at any time before then.

When the period ends:

1. The subscription ends, and no further plan fee is charged.
2. dply sends one final invoice for the usage from that last partial period, charged to the card on the subscription, with the plan's full included usage credit applied (that period's plan fee was already paid in advance in full, so the credit isn't prorated). If there was no usage, there is no final invoice.
3. The organization is paused. See [Paused accounts](/docs/paused-accounts).

A subscription canceled during the trial has no final invoice.

## Failed payments

If a renewal payment fails, Stripe retries the card on its own schedule. Your organization keeps running while Stripe retries. Update the card with **Manage card** on the **Billing** page. If every retry fails and the subscription ends, the organization is paused.

The first payment after a trial is different: if it fails, the organization is paused right away.

## Find your invoices

Open your organization's **Billing** page. **Invoices** lists your 12 most recent paid invoices. Choose **Open in Stripe** to see an invoice on Stripe's hosted page, where you can download a PDF. Unpaid invoices aren't listed here yet.

Your card, and the billing address Stripe holds, are managed with **Manage card**, which opens Stripe's billing portal. Only members who can manage the organization's billing can open it.

## Billing details

The **Billing details** panel on the **Billing** page has fields for an invoice email, a VAT number, a currency and legal details.

> [!IMPORTANT]
> These fields are saved in dply but aren't sent to Stripe yet, so they don't appear on your invoices. To change the address on your invoices, use **Manage card**. Invoices are always in US dollars, whichever currency you pick.

## Taxes

dply doesn't calculate or collect sales tax, VAT or GST today. Invoices show the prices on [Plans & pricing](/docs/pricing) with no tax line. If your jurisdiction requires you to account for tax on purchased services (for example, reverse charge for VAT-registered businesses in the EU or UK), you are responsible for doing so.

A VAT number entered on the **Billing** page is checked against the EU VIES registry when you save it, but it isn't printed on invoices.

## Related

- [Plans & pricing](/docs/pricing)
- [Usage & metering](/docs/usage)
- [Spending caps & alerts](/docs/spending-alerts)
- [Paused accounts](/docs/paused-accounts)
