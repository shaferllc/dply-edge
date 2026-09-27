---
title: "Traffic & analytics"
description: "See requests, bandwidth, cache performance, response times, Core Web Vitals, and live requests for an app."
---

**Traffic & analytics** shows what visitors are doing on your app: how many requests reach it, how much data it serves, how fast it responds, and how the pages feel in real browsers. Use it to confirm a launch is taking traffic, to spot a slow deploy, or to watch requests arrive while you debug.

To open it, go to your app and choose **Traffic & analytics** in the **Traffic** group of the sidebar.

> [!NOTE]
> Preview deployments do not have a **Traffic & analytics** section and do not collect visitor metrics. Open the production app instead.

## CDN traffic

The top of the page summarizes traffic on your app's hostnames:

| Figure | What it counts |
|--------|----------------|
| **Requests MTD** | Requests served this calendar month |
| **Requests 7d** | Requests in the last seven days, with the average per day |
| **Bandwidth MTD** | Bytes served to visitors this month |
| **Peak day 30d** | The busiest day in the last 30 days |

Below the summary, **Daily requests** and **Daily bandwidth** chart each day of the month. Hover a bar to see the exact value.

These figures come from a daily collection job, so today's traffic appears the next day (usually yesterday's traffic by mid-morning UTC). The **Latest** date shows the most recent day collected. Expand **Tracked hostnames** to see which hostnames are counted.

### Today

The **Today** panel counts same-day requests at the edge, in UTC, without waiting for the daily job. It shows **Requests today**, **Bandwidth today**, a **Status mix** of response codes, and the **Top paths** for the day.

## Edge cache

Hybrid apps show an **Edge cache** panel with the **Hit ratio 7d** and the number of requests **Served from edge 7d**. A low hit ratio on static paths usually means your build sets cache headers that stop the edge from caching. Choose **Cache controls** to adjust caching. See [Caching](/docs/caching).

## Performance

The **Performance** panel has two parts.

**Edge response** reports the average and 95th-percentile response time over the last seven days, the cache hit ratio, and the request count.

**Core Web Vitals** reports the 75th percentile of three real-user metrics over the last seven days, plus the number of samples:

| Metric | Measures |
|--------|----------|
| **LCP p75** | Largest Contentful Paint: how long the main content takes to appear |
| **INP p75** | Interaction to Next Paint: how quickly the page responds to input |
| **CLS p75** | Cumulative Layout Shift: how much the layout moves while loading |

dply collects these by adding a small script to the HTML pages it serves from your build output. The script reports each page view's metrics back to dply. You don't need to install anything. When there are no samples in the last seven days, the panel says so.

> [!TIP]
> You can alert on LCP and on 5xx errors. See [Alerts](/docs/alerts).

## Live requests

**Live requests** is a real-time view of requests as they reach your app. Each row shows the time, method, status, response time in milliseconds, cache status, visitor country, and path.

- Filter by **Method**, **Status**, or a **Path…** substring.
- Choose **Pause** to freeze the view while you read it, then **Resume**.
- Choose **Clear** to empty the view.
- Choose **CSV** to download the recent request log as a CSV file.

The view loads with the most recent requests and keeps up to 200 rows on screen. If nothing appears, visit your app's URL to generate a request.

To follow requests from a terminal, use `dply logs` (see [CLI](/docs/cli)) or the logs endpoint of the [HTTP API](/docs/api).

## Apps delivered through your own Cloudflare account

If an app is delivered through a Cloudflare account you connected, dply does not collect its traffic. The page shows **Traffic stats live in your connected account** and lists the hostnames to look up in your Cloudflare dashboard.

## Related

- [Logs](/docs/logs)
- [Alerts](/docs/alerts)
- [Caching](/docs/caching)
- [Usage & metering](/docs/usage)
