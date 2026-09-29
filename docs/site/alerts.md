---
title: "Alerts"
description: "Send an app's deploy, domain, usage, and error events to your notification channels, and alert on slow pages and 5xx errors."
---

**Alerts** decides who hears about an app and when. You subscribe notification channels (Slack, email, PagerDuty, a webhook, and others) to the app's events, such as a failed deploy or a failing custom domain. You can also set thresholds on real-user page speed and on 5xx errors, so dply tells you when visitors start having a bad time.

To open it, go to your app and choose **Alerts** in the **Manage** group of the sidebar.

## Before you start

You need at least one notification channel. Create one from the **Alerts** page with **Add channel**, or ahead of time under **Profile** → **Notification channels** or your organization's **Notification channels**. See [Notification channels](/docs/notifications).

## What the page shows

The page opens with one sentence that sums up the current setup, in the form "N of 11 events reach someone, through <channels>. The last alert was <time> ago." If nothing is routed yet, it says so.

Below it, **When something happens** lists the app's events grouped into rules, one sentence each:

- When a deploy fails, slows down or succeeds
- When a custom domain verifies or starts failing
- When a real-user metric crosses a threshold
- When usage goes over budget
- When queue jobs fail or workers keep exiting
- When a database fills up or nears its connection limit

Each rule shows the channels it reaches, or **Nobody** when no channel receives any of its events. **Manage channels** opens your **Notification channels** page.

**Recent alerts** lists the last five alerts sent for the app in the past 30 days.

## Route events to channels

1. Choose a rule.
2. In the dialog, tick the events each channel should receive. Each row is one of the rule's events and each column is one of your channels.
3. Choose **Save**.

**Cancel** or closing the dialog discards your changes. The channels you can route to are your personal channels, the organization's channels if you are an owner or admin, and the channels of teams you manage.

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

Thresholds live on the **When a real-user metric crosses a threshold** rule. Choose it, turn on any of the three checks and set a value, then choose **Save**. The same **Save** stores the rule's channels and its thresholds together. Once a check is on, the rule's sentence reads from it, for example "When LCP p75 goes over 2,500 ms or the 5xx rate goes over 5%".

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

The **Advanced** panel at the bottom of the **Alerts** page shows what the live deploy's file declares, marked **Repo**. Once you save thresholds in the dashboard, the dashboard values take precedence over the file. See [Configuration files](/docs/configuration-files).

## Who can change alerts

Anyone who can change the app's settings can edit subscriptions and thresholds. Changes to thresholds are recorded in the [Activity log](/docs/activity-log). See [Roles & permissions](/docs/roles-and-permissions).

## Related

- [Notification channels](/docs/notifications)
- [Traffic & analytics](/docs/traffic)
- [Spending caps & alerts](/docs/spending-alerts)
- [Queue workers](/docs/queue-workers)
