---
title: "Traffic & analytics"
description: "See requests, bandwidth, cache performance, response times, Core Web Vitals, and live requests for an app."
---

**Traffic & analytics** shows what visitors are doing on your app: how many requests reach it, how much data it serves, how fast it responds, and how the pages feel in real browsers. Use it to confirm a launch is taking traffic, to spot a slow deploy, or to watch requests arrive while you debug.

To open it, go to your app and choose **Traffic & analytics** in the **Traffic** group of the sidebar.

> [!NOTE]
> Preview deployments do not have a **Traffic & analytics** section and do not collect visitor metrics. Open the production app instead.

## The summary

The page opens with one sentence about the last seven days: how many requests your app answered, about how many a day, how much data it sent, and how long responses took on average. When same-day numbers are available it adds today's count so far, and how many of those requests failed (4xx or 5xx) in amber.

Seven-day and monthly totals come from a daily collection job, so today's traffic appears the next day (usually by mid-morning UTC). Below the rows, **Counted on** lists the hostnames included.

## Look closer

Each row under **Look closer** opens a dialog with the detail:

| Row | Dialog |
|-----|--------|
| **Busiest day** | **Requests**: this month's requests and bandwidth, and a bar for each day of requests and of bandwidth. Hover a bar for the exact value. |
| **Today** | Same-day requests counted at the edge (UTC day) without waiting for the daily job: requests and bandwidth today, the mix of response codes, and the top paths. |
| **Response time** | Average and slowest-5% (p95) response time over seven days, the share served from the edge cache, and how many requests were measured. Hybrid apps also link to **Cache controls**; a low cache share on static paths usually means your build sends headers that stop caching. See [Caching](/docs/caching). |
| **Page speed** | Core Web Vitals from real visitors, described below. |
| **Watch requests as they arrive** | **Live requests**, described below. |

### Page speed in browsers

The **Page speed in browsers** dialog reports the 75th percentile of three real-user metrics over the last seven days, plus the number of samples:

| Metric | Measures |
|--------|----------|
| **LCP** | Largest Contentful Paint: how long the main content takes to appear |
| **INP** | Interaction to Next Paint: how quickly the page responds to a tap or click |
| **CLS** | Cumulative Layout Shift: how much the layout moves while loading |

dply collects these by adding a small script to the HTML pages it serves from your build output. The script reports each page view's metrics back to dply. You don't need to install anything. When there are no samples in the last seven days, the row says so.

> [!TIP]
> You can alert on LCP and on 5xx errors. See [Alerts](/docs/alerts).

### Live requests

**Live requests** is a real-time view of requests as they reach your app. Each row shows the time, method, status, response time in milliseconds, cache status, visitor country, and path.

- Filter by **Method**, **Status**, or a **Path…** substring.
- Choose **Pause** to freeze the view while you read it, then **Resume**.
- Choose **Clear** to empty the view.
- Choose **CSV** to download the recent request log as a CSV file.

The view loads with today's most recent requests and keeps up to 200 rows. If nothing appears, visit your app's URL to generate a request.

To follow requests from a terminal, use `dply logs` (see [CLI](/docs/cli)) or the logs endpoint of the [HTTP API](/docs/api).

## Apps delivered through your own Cloudflare account

If an app is delivered through a Cloudflare account you connected, dply does not collect its traffic. The summary says the stats live in your connected Cloudflare account and lists the hostnames to look up there. Response time and page speed still show.

## Related

- [Logs](/docs/logs)
- [Alerts](/docs/alerts)
- [Caching](/docs/caching)
- [Usage & metering](/docs/usage)
