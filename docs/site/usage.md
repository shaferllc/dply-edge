---
title: "Usage & metering"
description: "How dply measures each usage meter, how often it is collected, where you can see it, and how it reaches your invoice."
---

Everything that costs money to run on dply is metered: traffic, builds, container compute, databases and every other resource. Usage is billed at the rates on [Plans & pricing](/docs/pricing), less your plan's included usage credit. This page explains how each meter is measured and where to watch it.

## How metering works

1. dply collects usage from Cloudflare and from its own database and cache clusters, most of it every hour.
2. Usage is added up per organization for the current billing period, which starts on your subscription's renewal date.
3. At the end of the period, the total for each category is added to your renewal invoice as a usage line, then the plan's included usage credit is subtracted as a negative line. See [Invoices & taxes](/docs/invoices).

Preview deployments are metered exactly like production. They don't count toward the fair-use limit on apps, but their builds, traffic and compute count.

Sites that run on your own Cloudflare account aren't metered by dply. Cloudflare bills you for them directly.

## Meters

| Meter | How it's measured | Collected | Invoice line |
|---|---|---|---|
| Requests | Every request your sites' Workers answer, including cache hits | Hourly, then again the next day | Delivery (requests, bandwidth, site storage) |
| Bandwidth | Bytes delivered to visitors | Hourly, then again the next day | Delivery (requests, bandwidth, site storage) |
| Site storage | The size of each site's published build output at its peak in the period, added up across sites | Hourly, then again the next day | Delivery (requests, bandwidth, site storage) |
| Site storage writes and reads | Writes (publishing a deploy) and reads (cache misses) against your sites' storage | Hourly, then again the next day | Delivery (requests, bandwidth, site storage) |
| Build time | Time in the build container, billed per second | When each build finishes | Build time |
| Container apps and queue workers | vCPU-seconds, memory and disk for every second an instance runs, and bytes sent out | Hourly, then again the next day for late samples | Apps and workers (compute) |
| Postgres, MySQL and MongoDB | Compute-unit seconds while the database is awake, and disk size for every hour | Hourly | Databases |
| Valkey | Seconds the store is awake, priced by its size and capped at the size's monthly maximum | Hourly | Valkey |
| Key-value | Reads; writes, deletes and lists; stored bytes per store | Hourly, then again the next day | SQL, queues and key-value |
| Edge SQL (D1) | Rows read, rows written, stored bytes | Hourly, then again the next day | SQL, queues and key-value |
| Queues | Queue operations | Hourly, then again the next day | SQL, queues and key-value |
| Realtime | Connection-minutes and messages (publishes; deliveries are free) | Hourly | Realtime |
| Workers CPU | CPU time used by your SSR, middleware and container front Workers | Hourly, then again the next day | Workers CPU, Durable Objects, object storage and images |
| Durable Objects | Requests, duration, rows read and written, stored bytes, for [State](/docs/resources/state) and container apps | Hourly, then again the next day | Workers CPU, Durable Objects, object storage and images |
| Object storage buckets | Stored bytes, writes and reads on buckets you add as a resource | Hourly, then again the next day | Workers CPU, Durable Objects, object storage and images |
| Image transformations | Unique transformations | Hourly, then again the next day | Workers CPU, Durable Objects, object storage and images |
| [AI](/docs/resources/ai) | Neurons, from each call's model and tokens | As each call finishes | AI, browser rendering and vector search |
| [Browser rendering](/docs/resources/browser-rendering) | Browser time, per second | As each session closes | AI, browser rendering and vector search |
| [Vector search](/docs/resources/vector-search) | Queried dimensions per query; stored dimensions per index | Queries as they run; storage hourly | AI, browser rendering and vector search |

Collection times are in UTC. The next-day run picks up samples Cloudflare reports late, so today's figures can rise slightly after the day ends. When your plan renews, the period's last day is collected again before its usage is invoiced.

### Included usage credit

Every meter above is billed from the first unit — there's no per-meter allowance. Instead, each plan has one included usage credit that comes off the total usage charge each period: Starter $5, Pro $20, Team $49. See [Plans & pricing](/docs/pricing#plans) for the full table. The credit applies to usage total, not to any one meter, never goes below $0, and doesn't carry over to the next period.

## Where to see usage

### Billing page

Your organization's **Billing** page shows, for the current period:

- **Usage this period** — what your usage lines come to today, at customer price.
- **Included credit** — the plan's usage credit.
- **Estimated charge** — usage this period less the included credit, projected to the end of the billing period.

The page also shows the billing period's dates.

### Projects usage

The **Usage** page, reached from **Projects** on your dashboard, shows requests, bandwidth, storage and an estimated cost for each project side by side.

> [!NOTE]
> The **Usage** page counts the calendar month, while invoices count your billing period. If your subscription renews mid-month, the two cover different dates.

### A single project

Each project's observability section has **Billing & usage**, with the project's usage this month. Resource sheets for key-value and object storage have their own **Usage** tab.

### The API

`GET /api/v1/billing` and `GET /api/v1/billing/breakdown` return the same figures as the Billing page, and `GET /api/v1/billing/invoices` lists invoices. Each needs a token with the `billing.read` ability. See [HTTP API](/docs/api).

## Keep usage down

- Let container apps, databases and Valkey sleep when idle. See [Scaling & sleep](/docs/scaling-and-sleep).
- Cache responses at the edge, so requests don't reach your app or storage. See [Caching](/docs/caching).
- Delete preview deployments you no longer need.
- Set a usage alert, so you hear about growth before the invoice. See [Spending caps & alerts](/docs/spending-alerts).

## Related

- [Plans & pricing](/docs/pricing)
- [Invoices & taxes](/docs/invoices)
- [Spending caps & alerts](/docs/spending-alerts)
