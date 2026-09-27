---
title: "Paused accounts"
description: "Why an organization is paused, what stops while it is paused, how long its data is kept, and how to resume."
---

An organization without a paid plan is paused. Pausing stops everything that costs money to run, while keeping your sites, apps and data in place so you can pick up where you left off. This page explains what triggers a pause, what it does, and how to get out of it.

## Why an organization is paused

dply checks every organization's billing every hour, and again after each billing event from Stripe. An organization is paused when any of these happens:

- **The trial ended without a plan.** You canceled during the trial, or the trial ended without a successful first payment.
- **The first payment after the trial failed.** A new customer's first charge doesn't get Stripe's retry window.
- **A canceled subscription reached the end of its period.** Canceling keeps you running until the period you paid for ends; the pause starts after that.
- **A paying subscription ran out of payment retries.** While Stripe is retrying a failed renewal, your organization keeps running. Once Stripe stops retrying and the subscription ends, the organization is paused.
- **A trial reached its $5 spending cap.** See [Free trial](/docs/free-trial). This pause lifts when the trial converts to a paid plan.

The **Billing** page's status tile shows **Paused** while this is in effect.

## What stops while paused

| Area | While paused |
|---|---|
| Sites | Every live site serves a paused page instead of your content. Custom domains stay attached. |
| Container apps | Requests are refused before a container starts, so no compute is used. |
| Queue workers | Stopped. Workers you had paused yourself stay paused after you resume. |
| Postgres, MySQL, MongoDB and Valkey | Put to sleep. If something wakes one, it goes back to sleep after a minute idle. Storage is kept. |
| Builds and deploys | New deploys fail with a message to choose a plan on the **Billing** page. |
| Your data | Kept, including databases, stores, environment variables and deploy history. |

You and your team can still sign in, open every page, and change settings.

## Emails

Owners of the organization receive an email when the organization is paused after its trial or subscription ends. The email says how long your data is kept. A trial paused at its spending cap sends its own email when the pause starts.

Owners also get two warnings before the organization's data is deleted: one 7 days before and one a day before. Each email gives the deletion date.

## Resume

How you resume depends on why the organization was paused.

| Why it was paused | How to resume |
|---|---|
| A canceled subscription hasn't ended yet | On the **Billing** page, under **Cancel or resume**, choose **Resume subscription**. Your organization isn't paused until the period ends. |
| A payment failed | Open the **Billing** page, choose **Manage card**, and update your card in Stripe's billing portal. Once Stripe collects the payment, the organization resumes. |
| A trial reached its spending cap | On the **Billing** page, under **Plan**, choose **End trial now**. Or wait for the trial to convert on its end date. |
| The trial or subscription has ended | On the **Billing** page, under **Plan**, choose **Choose Pro** or **Choose Team** and enter a card in Stripe's checkout. A new subscription starts paid right away, with no second trial. |

Once the subscription is active, dply republishes your sites without the paused page, reopens container traffic, restarts the queue workers it stopped, and restores each database and Valkey store to its own sleep setting. This happens within the hour, usually right after the payment succeeds.

## How long your data is kept

A paused organization's data is kept for 30 days from the day it was paused. The paused banner in the dashboard shows the deletion date.

> [!WARNING]
> After the 30 days, dply deletes the organization's data: every site with its dply database and Valkey stores, its Edge SQL databases, its queues and its realtime apps. The organization, its members and its billing history remain, so choosing a plan later starts from an empty workspace.

Owners are emailed 7 days before deletion and again a day before. Deletion never happens without both warnings: if a warning goes out late, the deletion date moves back so each warning still gets its full notice. Export anything you need, or choose a plan, before the deletion date. Resuming cancels the deletion; if the organization is paused again later, the 30 days and the warnings start over.

## Related

- [Free trial](/docs/free-trial)
- [Plans & pricing](/docs/pricing)
- [Invoices & taxes](/docs/invoices)
