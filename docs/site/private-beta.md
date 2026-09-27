---
title: "Private beta"
description: "What the dply private beta includes, what it costs, its current limits, and how to reach us."
---

dply is in private beta. You can deploy real apps and attach real resources, but the product is still changing, and some parts are rougher than they will be at general availability. This page covers what beta participants get, what it costs, the known limits, and how to tell us what you find.

## Joining the beta

Sign-up is open. You don't need an invitation: [create an account](/docs/quickstart), then choose a plan to start your trial. Beta participants get the same plans, trial and limits as everyone else, with no extra perks.

If you received an invitation email, its link opens the sign-up form with your address filled in. Invitations expire after 30 days, but you can always sign up without one.

## What it costs

Beta organizations use the same plans and trial as everyone else. There is no free beta tier.

- A new organization starts with a 5-day trial of the plan it chooses. Checkout asks for a card before the trial begins.
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
- **The end of the beta.** No end date is set. When the beta ends, beta organizations continue on their current plan.

There are no beta-only terms and no service level agreement during the beta.

## Feedback and support

We read every report. The most useful ones include the app name, the deployment ID from **Deploys**, what you expected, and what happened instead. For a failed build, include the relevant lines from **Build & deploy logs**.

Email [hello@dply.io](mailto:hello@dply.io) from the address on your dply account. We answer as quickly as we can on business days; there is no guaranteed response time. To report a vulnerability, email [security@dply.io](mailto:security@dply.io) instead.

See [Support](/docs/support) and [Report abuse](/docs/abuse).

## Your data during the beta

If your trial ends or a payment fails, your organization is paused: apps stop serving, containers and databases sleep, and builds stop. The organization resumes when you choose a plan. Your data is kept for 30 days after the pause; we email you before it's deleted, and after 30 days the organization's apps, resources and data are deleted permanently. See [Paused accounts](/docs/paused-accounts).

> [!WARNING]
> Keep your own backups of anything you can't recreate. Beta software can have bugs that affect data.

## Related

- [Quickstart](/docs/quickstart)
- [Free trial](/docs/free-trial)
- [Support](/docs/support)
- [Changelog](/docs/changelog)
