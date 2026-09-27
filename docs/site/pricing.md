---
title: "Plans & pricing"
description: "What each plan includes, the included usage credit, and the rate for every usage meter."
---

dply bills each organization a monthly plan fee plus usage past what the plan's included credit covers. Every new organization starts with a 5-day trial of the plan it chooses; there is no free plan. Sites and apps are unlimited on every plan, subject to fair use. This page lists what each plan includes and the rate for every meter, so you can predict a bill before it arrives.

All prices are in US dollars. Plans are billed monthly; there is no annual option.

## Plans

<!-- generated: php artisan dply:billing:price-table plans -->
| Plan | Price | Seats | Extra seat | Included usage |
| --- | --- | --- | --- | --- |
| Starter | $5/mo | 1 | — | $5/mo |
| Pro | $20/mo | 3 | — | $20/mo |
| Team | $49/mo | 10 | $5/mo each | $49/mo |
| Enterprise | Contact us | Custom | Custom | Custom |

**Included usage** is a credit applied to your usage charges each billing period: usage is billed at the rates below, then the smaller of the plan's credit or your usage total is subtracted. Usage never goes below $0, and unused credit does not carry over. See [Usage & metering](/docs/usage) for how usage is measured and [Invoices & taxes](/docs/invoices) for how the credit appears on your bill.

> [!NOTE]
> Enterprise is sales-led and invoiced by hand. Its limits and price are set in your contract.

### Sites and apps

Every plan, including the trial, deploys as many sites and apps as you want — static, hybrid, Worker SSR and container apps all count as apps, and none of them has a per-site or per-app fee. **Preview deployments** don't count toward anything; their builds and usage still bill like any other deploy.

### Fair use

"Unlimited" is subject to fair use: a hidden limit on non-preview apps, meant to catch abuse rather than normal growth. At the limit, creating another app shows "You've reached the fair-use limit for your plan — contact us to raise it." Nothing is charged, and there's no upgrade prompt — contact [support](/docs/support) to raise it.

<!-- generated: php artisan dply:billing:price-table fair-use -->
| Plan | Apps (previews don’t count) |
| --- | --- |
| Starter | 25 |
| Pro | 250 |
| Team | 1,000 |
| Enterprise | None |

### Seats

Every member of the organization is a seat, and pending invitations count too, except view-only members: someone who can only view the organization's apps, and an invitation for that role, doesn't take a seat and is free. On Starter and Pro the seat count is a hard limit: you can't invite past it, and you can't move down to a plan with fewer seats than you have members. On Team, the first 10 seats are included and each additional member costs $5 a month.

### Other limits

Non-price limits — build concurrency and timeout, custom domains, database and queue counts, worker instances, realtime connections — stay per plan. See [Usage & metering](/docs/usage) for how usage bills and the tables below.

<!-- generated: php artisan dply:billing:price-table limits -->
|  | Starter | Pro | Team |
| --- | --- | --- | --- |
| Sites | Unlimited | Unlimited | Unlimited |
| Concurrent builds | 1 | 2 | 5 |
| Build timeout | 20 min | 45 min | 60 min |
| Custom domains (per organization) | 3 | 20 | 100 |
| Container app instances | 1 per app | Autoscaling | Autoscaling |
| Queue workers per app | 1 | 5, autoscaling | 10, autoscaling |
| SQL databases (D1) | 2 | 10 | 50 |
| Queues | 2 | 10 | 50 |
| Realtime connections per app | 200 | 1,000 | 5,000 |
| Audit log | No | No | Yes |

Enterprise's limits are set in your contract; none of the above is a hard ceiling there.

## Usage rates

Usage is billed in arrears, on the invoice after the billing period ends, at the rates below, less your plan's included usage credit. See [Usage & metering](/docs/usage) for how each meter is measured and [Invoices & taxes](/docs/invoices) for how it appears on your bill.

Rates are shown to more precision than a cent where the unit is small. Bandwidth is a flat price dply sets, not a pass-through of a provider charge. Each invoice line is rounded to the nearest cent.

<!-- generated: php artisan dply:billing:price-table rates -->
| Group | Meter | Price | Unit |
| --- | --- | --- | --- |
| Delivery | Requests | $0.39 | per million |
| Delivery | Bandwidth | $0.06 | per GB |
| Delivery | Site storage | $0.0195 | per GB-month |
| Delivery | Site storage writes | $5.85 | per million |
| Delivery | Site storage reads | $0.468 | per million |
| Builds | Build time | $0.005 | per minute, billed per second |
| Apps and workers | vCPU | $0.000026 | per vCPU-second |
| Apps and workers | Memory | $0.00000325 | per GiB-second |
| Apps and workers | Disk | $0.000000091 | per GB-second |
| Databases | Compute | $0.0000333 | per compute-unit-second (1 vCPU, 4 GB) |
| Databases | Storage | $0.20 | per GB-month |
| SQL (D1) | Rows read | $0.0013 | per million |
| SQL (D1) | Rows written | $1.30 | per million |
| SQL (D1) | Storage | $0.975 | per GB-month |
| Queues | Operations | $0.52 | per million |
| Key-value | Reads | $0.65 | per million |
| Key-value | Writes, deletes and lists | $6.50 | per million |
| Key-value | Storage | $0.65 | per GB-month |
| Realtime | Connection-minutes | $0.25 | per million |
| Realtime | Messages | $0.62 | per million |
| Workers | CPU time | $0.026 | per million CPU-ms |
| Durable Objects | Requests | $0.195 | per million |
| Durable Objects | Duration | $16.25 | per million GB-seconds |
| Durable Objects | Rows read | $0.0013 | per million |
| Durable Objects | Rows written | $1.30 | per million |
| Durable Objects | Storage | $0.26 | per GB-month |
| Object storage | Storage | $0.0195 | per GB-month |
| Object storage | Writes (Class A) | $5.85 | per million |
| Object storage | Reads (Class B) | $0.468 | per million |
| Images | Transformations | $0.65 | per 1,000 |
| AI | Neurons | $0.0143 | per 1,000 |
| Browser rendering | Browser time | $0.117 | per browser-hour |
| Vector search | Queried dimensions | $0.013 | per million |
| Vector search | Stored dimensions | $0.065 | per 100 million, per month |

Container apps, queue workers, dply databases (Postgres, MySQL, MongoDB) and Valkey are billed per second while they're awake; asleep is $0 (database storage still bills per GB-month, awake or asleep). See [Scaling & sleep](/docs/scaling-and-sleep).

## Sizes

Container apps, dply databases and Valkey share one size ladder. Memory differs by product; the price is per second while the resource is awake.

<!-- generated: php artisan dply:billing:price-table sizes -->
| Size | Container app | Database | Valkey |
| --- | --- | --- | --- |
| 0.25 vCPU | $0.0000101/s (1 GB) | $0.00000833/s (1 GB) | $0.00000186/s (250 MB) |
| 0.5 vCPU | $0.0000267/s (4 GB) | $0.0000167/s (2 GB) | $0.00000744/s (1 GB) |
| 1 vCPU | $0.0000363/s (3 GB) | — | $0.0000186/s (2.5 GB) |
| 2 vCPU | $0.0000726/s (6 GB) | — | $0.0000475/s (5 GB) |
| 4 vCPU | $0.000145/s (12 GB) | — | $0.000062/s (12 GB) |

Container apps also offer **1/16 vCPU** below this ladder, for non-PHP apps only. Valkey's 0.25, 0.5 and 1 vCPU sizes sleep when idle; 2 and 4 vCPU stay on. Each Valkey size also has a monthly maximum — see [Valkey (Redis)](/docs/resources/valkey). Each container app size has one too: an instance never bills more than 600 hours of its every-vCPU-busy rate in a month — see [Containers](/docs/containers#choose-a-size).

## What's never throttled, and what is

Going past your included usage credit doesn't stop anything that's metered: sites keep serving, builds keep running, and the extra bills on your next invoice. A few limits are hard caps rather than meters:

- Seats on Starter and Pro.
- Custom domains per organization.
- The number of D1 databases and queues.
- Concurrent builds and the build timeout.
- Realtime connections per app.
- The fair-use limit on apps.

The trial is different: it has a $2 spending cap. See [Free trial](/docs/free-trial).

## Estimate a bill

The public pricing page has an estimator for requests, bandwidth, build time and one small container app, at the same rates the invoice uses. It is an estimate: it leaves out storage operations, databases, Valkey, key-value, realtime and other meters. Inside the app, your organization's **Billing** page shows usage this period, the included credit, and the estimated charge for the current period.

A Pro organization with $32 of usage in a period pays:

| Line | Amount |
|---|---|
| Pro plan | $20.00 |
| Usage this period | $32.00 |
| Included usage credit | −$20.00 |
| **Total** | **$32.00** |

## Related

- [Free trial](/docs/free-trial)
- [Usage & metering](/docs/usage)
- [Spending caps & alerts](/docs/spending-alerts)
- [Invoices & taxes](/docs/invoices)
