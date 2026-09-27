---
title: "Changelog"
description: "Notable changes to dply, newest first."
---

What changed in dply, newest first. Each entry links to the page that covers the feature in full.

## 2026-09-26

### One page for your app and its resources

A project's **Overview** now shows the app and every resource it can use in one place, and the separate **Resources** page is gone. Settings open in side sheets that save as you go and prompt you to redeploy when a change needs one. A project's Overview is now the project's own URL; old links redirect. See [Resources overview](/docs/resources).

### Realtime: WebSockets for Laravel Echo and Pusher clients

Add **Realtime** to an app for a Pusher-protocol WebSocket service that works with Laravel Echo, Reverb clients and Pusher SDKs. Each app gets its own host, and the connection settings are added to your app's environment for you. Pro includes 5 million connection-minutes and 10 million messages a month; Team includes 25 million and 50 million. See [Realtime (WebSockets)](/docs/resources/realtime) and [Laravel broadcasting with Realtime](/docs/guides/laravel-broadcasting).

### No free plan: a 5-day Pro trial

New organizations start with a 5-day trial of Pro, with a card added at checkout. The trial has a $5 spending cap. An organization whose trial ends without payment is paused, and its data is kept for 7 days. Organizations that were on the free plan received a fresh 5-day trial. See [Free trial](/docs/free-trial) and [Paused accounts](/docs/paused-accounts).

### Usage billed in arrears, once per period

Usage past your plan now appears as a few lines on each renewal invoice, covering exactly the billing period that has ended. Previously, usage changes could produce small prorated invoices during the month. Canceling sends one final usage invoice. Usage alerts email owners at 50%, 80% and 100% of an amount you set. See [Invoices & taxes](/docs/invoices) and [Spending caps & alerts](/docs/spending-alerts).

### New meters

Workers CPU time, Durable Objects, object storage buckets and image transformations are now metered. See [Usage & metering](/docs/usage) for how each is measured and [Plans & pricing](/docs/pricing) for the rates.

### Payment retries

If a renewal payment fails, your organization keeps running while Stripe retries the card. It is paused only if the subscription ends unpaid. A failed first payment after a trial still pauses the organization.

### Faster sites and deploys

- Hashed framework assets (Vite, Next.js, Astro, SvelteKit) are served as immutable, and unchanged files answer with `304 Not Modified`.
- Server-rendered and container HTML streams to the browser.
- Deploys upload files in parallel and copy unchanged files from the live deploy instead of uploading them again. A failed upload now fails the deploy instead of publishing an incomplete site.
- The dashboard's **Traffic**, **Cache** and **Billing** tabs load faster.

See [Deployments](/docs/deployments) and [Caching](/docs/caching).

### Security

- Resources can be bound only by apps in the organization that owns them, including bindings declared in a repository's `wrangler.toml`.
- A hostname can be attached to only one site, and the organization-wide custom domain limit per plan is enforced.
- Builds run in a sandbox: non-root, with no Linux capabilities, resource limits, a private network and per-organization package caches.

See [Platform security & isolation](/docs/platform-security).

### Databases

The database panel gains **Queries** (running and top queries, with cancel), **Health** (disk, connections, backups, missing and unused indexes) and a read-only **Console**. You can export, download and import backups, and create a read-only login. Opening the panel doesn't wake a sleeping database. See [Postgres, MySQL & MongoDB](/docs/resources/databases).

### Cheaper idle apps

- Queue workers can scale to zero: a worker starts when jobs arrive and stops after 5 quiet minutes.
- The scheduler wakes your app only when a task is due, so an app with one nightly task can sleep the rest of the day.
- Apps suggest a smaller instance size when a week of memory use shows it would fit, with the saving and a one-click switch.
- Valkey shows evicted and expired keys and its slowest recent commands.

See [Scaling & sleep](/docs/scaling-and-sleep) and [Queue workers](/docs/queue-workers).

### Logs by source

Container logs can be filtered by **App**, **Queue workers** or **Routing**, and by worker. See [Logs](/docs/logs).

### Placement visibility

Each queue worker reports where Cloudflare placed it and its round trip to your database and Valkey, and the workers card flags one that is far from its data. See [Data regions](/docs/data-regions).

## 2026-09-25

### Queue worker plan limits

Queue workers per app are now set by plan: Pro allows 5 instances with autoscaling and 2 worker groups; Team allows 10 instances and 4 groups. See [Queue workers](/docs/queue-workers).

### Scheduler as a resource

Add **Scheduler** from **Add resource**, with **Run now** to run it once and see the output. When the app has queue workers, the scheduler runs inside a worker instead of waking the web container every minute. See [Scheduled tasks](/docs/scheduled-tasks).

### Placement

Container apps that land far from their database are restarted once to be placed again, and stop retrying when Cloudflare picks the same location. Placement is best effort. See [Data regions](/docs/data-regions).
