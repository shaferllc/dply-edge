---
title: "Alerts"
description: "Send an app's deploy, domain, usage, and error events to your notification channels, and alert on slow pages and 5xx errors."
---

**Alerts** decides who hears about an app and when. You subscribe notification channels (Slack, email, PagerDuty, a webhook, and others) to the app's events, such as a failed deploy or a failing custom domain. You can also set thresholds on real-user page speed and on 5xx errors, so dply tells you when visitors start having a bad time.

To open it, go to your app and choose **Alerts** in the **Manage** group of the sidebar.

## Before you start

You need at least one notification channel. Create one from the **Alerts** page with **Create a channel**, or ahead of time under **Profile** → **Notification channels** or your organization's **Notification channels**. See [Notification channels](/docs/notifications).

## Route events to channels

The **Channels** section lists every channel you can route to: your personal channels, the organization's channels if you are an owner or admin, and the channels of teams you manage. The **My channels** and **Organization channels** buttons open those pages.

1. Expand a channel.
2. Tick the events it should receive.
3. Choose **Save subscriptions**.

Subscriptions are per app. To send the same events from several apps to a channel at once, use **Bulk assign** on your **Notification channels** page.

### Events

| Event | Sent when |
|-------|-----------|
| **Edge deploy succeeded** | A deploy goes live |
| **Edge deploy failed (action required)** | A build or publish fails |
| **Edge deploy got noticeably slower** | A build takes much longer than recent builds |
| **Custom domain verified** | A custom domain finishes verification |
| **Custom domain verification failing (action required)** | A custom domain stops verifying |
| **Edge usage over budget (action required)** | Usage crosses a spending guardrail |
| **Real-user metric threshold breached (action required)** | A threshold below is crossed |
| **Queue jobs failing (action required)** | Queue jobs keep failing |
| **Queue workers keep exiting (action required)** | Queue workers crash repeatedly |
| **Database disk over 80% full (action required)** | A database is filling its disk |
| **Database near its connection limit (action required)** | A database is close to its connection cap |

People who receive in-app notifications still see these events in the dply inbox, even when no channel is subscribed.

## Set thresholds

Under **Thresholds**, turn on any of three checks and set a value, then choose **Save thresholds**.

| Check | Threshold | Allowed range | Starting value |
|-------|-----------|---------------|----------------|
| **LCP p75** | Largest Contentful Paint, 75th percentile, in milliseconds | 100–60000 | 2500 |
| **5xx error rate** | Share of requests that returned a 5xx status, in percent | 0.1–100 | 5 |
| **5xx count** | Number of 5xx responses | 1–1000000 | 50 |

dply checks each enabled threshold once an hour against the last 60 minutes of traffic. When a value is above its threshold, dply sends **Real-user metric threshold breached** to the subscribed channels. After a breach, the same check stays quiet for six hours, so a long incident does not flood your channels.

LCP comes from the Core Web Vitals dply collects in visitors' browsers, and the 5xx checks come from request logs. An app with no traffic in the last hour does not trigger either. See [Traffic & analytics](/docs/traffic).

> [!TIP]
> Wire up channels and thresholds before a launch, so a failed deploy or an error spike reaches someone.

### Thresholds in `dply.yaml`

You can commit thresholds to your repository instead. Add an `alerts` block to `dply.yaml`:

```yaml
alerts:
  lcp_p75_ms:
    enabled: true
    threshold: 2500
  error_rate:
    enabled: true
    threshold: 2
  five_xx_count:
    enabled: false
    threshold: 50
```

The **Repo** column on the **Alerts** page shows what the live deploy's file declares. Once you save thresholds in the dashboard, the dashboard values take precedence over the file. See [Configuration files](/docs/configuration-files).

## Who can change alerts

Anyone who can change the app's settings can edit subscriptions and thresholds. Changes to thresholds are recorded in the [Activity log](/docs/activity-log). See [Roles & permissions](/docs/roles-and-permissions).

## Related

- [Notification channels](/docs/notifications)
- [Traffic & analytics](/docs/traffic)
- [Spending caps & alerts](/docs/spending-alerts)
- [Queue workers](/docs/queue-workers)
