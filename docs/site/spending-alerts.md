---
title: "Spending caps & alerts"
description: "The trial's $5 spending cap, usage alert emails on paid plans, and what happens when you reach each kind of limit."
---

dply has two kinds of spending control. During the trial, a hard **spending cap** stops usage at $5, because nothing has been charged yet. On a paid plan, a **usage alert** emails your organization's owners as usage past the included credit grows, but never stops anything. This page covers both, and the hard limits that stop work regardless of spend.

## Trial spending cap

Every trial is capped at $5 of usage, at the usual customer rates with no included credit applied. Everything with a price counts, from the day the trial started: delivery, build time, container compute, databases, Valkey, key-value, Edge SQL, queues, realtime and Workers usage.

| Usage reaches | What happens |
|---|---|
| 80% ($4) | The next build that starts sends a **Usage credit almost used up** notification to your notification channels. |
| 100% ($5) | New builds fail with a message that the usage credit is used up. Within the hour the organization is paused: sites serve a paused page, container apps stop taking traffic, queue workers stop, and databases and Valkey sleep. |

Owners are emailed when the organization is paused at the cap. The pause lifts once the trial converts to a paid plan and the first payment succeeds. To convert now, choose **End trial now** on the **Billing** page. See [Free trial](/docs/free-trial) and [Paused accounts](/docs/paused-accounts).

> [!NOTE]
> The cap isn't configurable.

## Usage alerts on paid plans

On a paid plan, usage past your included usage credit is billed on your next invoice and is never capped. To avoid surprises, dply emails every owner of the organization when this billing period's usage charge — usage past the included credit — passes 50%, 80% and 100% of your usage alert amount.

- Each threshold is emailed once per billing period.
- The amount compared is the usage charge past the included credit — what will appear as usage lines on your next invoice, net of the credit. The plan fee and extra seats don't count.
- The default alert amount is twice your plan price: $10 on Starter, $40 on Pro, $98 on Team.
- Reaching 100% doesn't pause or throttle anything. The email says so.

### Change the alert amount

1. Open your organization's **Billing** page.
2. In **Cost forecast**, find **Usage alert**.
3. Enter an amount in dollars and choose **Save**. Leave it empty to go back to twice the plan price.

Only owners and members who can manage billing can change it.

> [!NOTE]
> Alerts are checked when billing syncs: once a day, and after each billing event from Stripe. An alert can arrive up to a day after usage crosses a threshold.

## Hard limits

Some limits stop work instead of billing more. These apply on every plan:

| Limit | What happens at the limit |
|---|---|
| Seats on Starter (1) and Pro (3, including pending invitations) | You can't invite another member. Move to a higher plan for more seats. |
| Concurrent builds (Starter 1, Pro 2, Team 5) | Extra builds wait in a queue until a slot frees up. |
| Build timeout (Starter 20 min, Pro 45 min, Team 60 min) | The build is stopped and the deploy fails. |
| Custom domains per organization (Starter 3, Pro 20, Team 100) | You can't add another domain. |
| Edge SQL databases and queues (Starter 2, Pro 10, Team 50) | You can't create another one. |
| Realtime connections per app (Starter 200, Pro 1,000, Team 5,000) | New connections are refused while the app is at its limit. |
| Fair-use limit on apps (Starter 25, Pro 250, Team 1,000) | Creating another app is refused with a message to contact support. |

See [Plans & pricing](/docs/pricing) for every limit.

## See what you're spending

Your organization's **Billing** page shows "Usage this period · included credit · estimated charge" for the current billing period. See [Usage & metering](/docs/usage).

## Related

- [Free trial](/docs/free-trial)
- [Usage & metering](/docs/usage)
- [Invoices & taxes](/docs/invoices)
