---
title: "Free trial"
description: "How the 5-day Pro trial works: starting it, what it includes, its $5 spending cap, and what happens when it ends."
---

Every new organization can try dply on the Pro plan for 5 days. You add a card when the trial starts, and dply bills the Pro plan on day 6 unless you cancel first. There is no free plan, so the trial is how you evaluate dply before paying.

## Start a trial

You start the trial the first time you choose a plan. There are two ways in:

- **From the Billing page.** Open your organization's **Billing** page. Under **Plan**, choose **Start 5-day Pro trial** (or **Start 5-day Team trial**).
- **From your first deploy.** If your organization has no plan when you choose **Deploy**, dply sends you to checkout for Pro and brings you back to your draft afterwards.

Either way, you enter a card on Stripe's checkout page. You aren't charged when the trial starts.

> [!IMPORTANT]
> A card is required. Checkout won't start the trial without one.

### Who gets a trial

An organization gets a trial only if all of these are true:

- It has never had a trial or a subscription.
- None of its owners has had a trial or subscription on another organization.
- The card hasn't been used for a trial on another organization.

If the card has already had a trial, the new subscription starts paid right away. If the organization isn't eligible at all, the button reads **Choose Pro** instead of **Start 5-day Pro trial**, and checkout starts a paid subscription.

## What the trial includes

The trial is the Pro plan: 3 seats, unlimited sites and apps (subject to fair use), container apps, databases, queues and realtime. See [Plans & pricing](/docs/pricing) for the full list.

If you start a Team trial instead, you get Team's limits for the same 5 days.

## The $5 spending cap

The trial isn't charged, so dply caps what it can spend. Everything the trial uses that has a price counts toward a $5 cap, at the usual customer rates, starting on the day the trial began — the plan's included usage credit does not apply during the trial:

- Requests, bandwidth and site storage.
- Build time, billed per second from the first second.
- Container compute, from the first second.
- Postgres, MySQL, MongoDB and Valkey time.
- Key-value, Edge SQL, queues and realtime.
- Workers CPU, Durable Objects, object storage buckets and image transformations.

When a build starts after usage has reached 80% of the cap, dply sends a **Usage credit almost used up** notification to your [notification channels](/docs/notifications). When the trial reaches the cap:

1. New builds stop. The deploy fails with a message that the trial's usage credit is used up.
2. Within the hour, the whole organization is paused: sites serve a paused page, container apps stop accepting traffic, queue workers stop, and databases and Valkey go to sleep.
3. Owners get a **trial usage cap reached** email, and your notification channels get a **Usage credit used up** notification.

The organization stays paused until the trial converts to a paid plan. It converts at its end date, or right away if you end the trial early (below). Once the first payment succeeds, everything resumes, at the latest on the next hourly check. See [Paused accounts](/docs/paused-accounts).

## End the trial early

On the **Billing** page, under **Plan**, choose **End trial now** on your current plan and confirm. Stripe charges the plan to your card that day, the trial's $5 cap is lifted, and your usage bills at the normal rates from then on. Use this if the trial hits its cap or you're ready to pay before day 6.

## Emails during the trial

Owners of the organization receive:

| When | Email |
|---|---|
| The trial starts | Trial started |
| 3 days or less remain | Trial ending soon |
| About a day before it ends | Trial ending |
| Usage reaches the $5 cap and the organization is paused | Trial usage cap reached |
| The trial ends without payment and the organization is paused | Paused |

The **Billing** page also shows **Trial** and the end date in its status tile.

## When the trial ends

On day 6, Stripe charges your card for the plan (plus any extra seats, if you're on a Team trial with more than 10 members). Usage during the trial is never billed.

Usage from day 6 onward bills in arrears on your next invoice. See [Invoices & taxes](/docs/invoices).

If the first payment fails, the organization is paused. It isn't given the retry grace that an existing paying customer gets. Update your card with **Manage card** on the **Billing** page; the organization resumes once the payment succeeds.

## Cancel during the trial

On the **Billing** page, under **Cancel or resume**, choose **Cancel subscription**. The trial continues to its end date and you're never charged. Nothing from the trial period is invoiced.

When the trial ends without a plan, the organization is paused and its data is kept for 7 days. You can choose a plan at any time in those 7 days to resume. See [Paused accounts](/docs/paused-accounts).

## Related

- [Plans & pricing](/docs/pricing)
- [Spending caps & alerts](/docs/spending-alerts)
- [Paused accounts](/docs/paused-accounts)
