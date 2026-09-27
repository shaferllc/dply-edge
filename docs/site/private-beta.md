---
title: "Private beta"
description: "What the dply private beta includes, what it costs, its current limits, and how to reach us."
---

dply is in private beta. You can deploy real apps and attach real resources, but the product is still changing, and some parts are rougher than they will be at general availability. This page covers what beta participants get, what it costs, the known limits, and how to tell us what you find.

## Joining the beta

Beta access is by invitation. An invitation is emailed to one address and is tied to it: the sign-up form locks the email field to the invited address. Invitations expire after 30 days.

> [!NOTE]
> Owner to confirm: whether sign-up is invitation-only for everyone during the beta, or whether public sign-up is also open, and how people request an invitation.

## What it costs

Beta organizations use the same plans and trial as everyone else. There is no free beta tier.

- A new organization starts with a 5-day trial of Pro. Checkout asks for a card before the trial begins.
- During the trial, usage is capped at $5. Past that, builds and container traffic pause until you pay.
- After the trial, the plan bills monthly unless you cancel first.

<!-- generated: php artisan dply:billing:price-table plans -->
| Plan | Price | Seats | Extra seat | Included usage |
| --- | --- | --- | --- | --- |
| Starter | $5/mo | 1 | — | $5/mo |
| Pro | $20/mo | 3 | — | $20/mo |
| Team | $49/mo | 10 | $5/mo each | $50/mo |
| Enterprise | Contact us | Custom | Custom | Custom |

There's no per-app or per-site fee on any plan. Usage past the included credit bills at the rates on [Plans & pricing](/docs/pricing). See [Free trial](/docs/free-trial) and [Usage & metering](/docs/usage) for the full limits and rates.

## What you can use

Everything in these docs is available to beta participants on the plan they choose, including all four runtime modes, resources, previews, custom domains, the CLI and the HTTP API. Some features need a paid plan or Team specifically; each page says so near the top.

## Known limits

These are current gaps you may run into.

- **Supported stacks.** Python, Go and WordPress apps can't be deployed. See [Frameworks & runtimes](/docs/frameworks#not-supported).
- **Hugo and Jekyll.** They are detected, but the build image doesn't include Hugo or Ruby, so their builds fail as detected.
- **Choosing a runtime mode.** The create page uses the mode dply detects. There is no picker for Worker SSR on the create page. The templates gallery can start an app in Worker SSR mode.
- **Build settings at creation.** The create page doesn't show the detected build command or output directory. Review them in **Build** after the app exists, and redeploy if you change them.
- **Hybrid apps** need a server you already run. dply serves the static files and proxies the rest to it.
- **Container rollbacks** rebuild the earlier commit rather than switching back instantly.

## What may change

- **Features and interface.** Pages, labels and defaults may change between releases. These docs are updated as they do.
- **Limits and prices.** Plan allowances and prices may change before general availability.
- **The end of the beta.** When the beta ends, beta organizations continue on their current plan.

> [!NOTE]
> Owner to confirm: the planned end date of the beta, how and how far ahead changes to pricing are announced, and whether any beta-only terms (such as a service level) apply.

## Feedback and support

We read every report. The most useful ones include the app name, the deployment ID from **Deploys**, what you expected, and what happened instead. For a failed build, include the relevant lines from **Build & deploy logs**.

> [!NOTE]
> Owner to confirm: the support channel for beta participants (email address, chat, or community forum), expected response times, and where to report security issues.

See [Support](/docs/support) and [Report abuse](/docs/abuse).

## Your data during the beta

If your trial ends or a payment fails, your organization is paused: apps stop serving, containers and databases sleep, and builds stop. Your data is kept for 7 days, and the organization resumes when you choose a plan. See [Paused accounts](/docs/paused-accounts).

> [!NOTE]
> Owner to confirm: what happens to a paused organization's data after 7 days. Automatic deletion exists but is currently switched off.

> [!WARNING]
> Keep your own backups of anything you can't recreate. Beta software can have bugs that affect data.

## Related

- [Quickstart](/docs/quickstart)
- [Free trial](/docs/free-trial)
- [Support](/docs/support)
- [Changelog](/docs/changelog)
