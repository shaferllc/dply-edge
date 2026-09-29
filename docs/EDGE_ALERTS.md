---
title: "Edge alerts"
slug: edge-alerts
category: "Edge"
order: 119
description: "Route Edge events to notification channels and set RUM / error thresholds."
group: edge
---

# Edge alerts

**Alerts** opens with a one-line summary ("N of 11 events reach someone, through <channels>. The last alert was <ago>.") and lists the site's events as rules, one sentence each. Each rule shows the channels it reaches, or **Nobody** (amber) when none do.

## Rules and channels

| Rule | Events |
|------|--------|
| When a deploy fails, slows down or succeeds | Deploy succeeded / failed / got noticeably slower |
| When a custom domain verifies or starts failing | Domain verified / verification failing |
| When a real-user metric crosses a threshold | RUM breach (`edge.rum.breach`) |
| When usage goes over budget | Guardrail trip |
| When queue jobs fail or workers keep exiting | Queue jobs failing / workers keep exiting |
| When a database fills up or nears its connection limit | Disk over 80% / near connection limit |

Click a rule to open its dialog: a grid of the rule's events × your channels. Tick the boxes and **Save**; **Cancel** or ✕ discards the edits. **Add channel** creates one inline; **Manage channels** opens your profile's **Notification channels**. Same channel system as BYO site notifications.

**Recent alerts** shows the last 5 alerts sent in the past 30 days.

## Thresholds

The real-user metric rule's dialog also holds the thresholds. Its one **Save** stores channels and thresholds together, and the rule's sentence is built from the enabled checks.

| Metric | Typical start |
|--------|----------------|
| LCP p75 | 2500 ms |
| 5xx error rate | 5% |
| 5xx count | 50 / hour |

Checked hourly against the last 60 minutes, at most one alert per kind every 6 hours. Thresholds can also live in `dply.yaml` under `alerts:`; the **Advanced** panel shows what the repo declares.

## Tips

- Wire channels **before** a launch so deploy failures and RUM breaches reach someone.
- Start with **Forms-only** bot protection and conservative RUM thresholds, then tighten.
- In-app inbox still receives events for stakeholders even without a channel.

## Related sections

- **Traffic & analytics** — Core Web Vitals and live requests that feed RUM checks
- **Bot protection** / **Rate limits** — reduce noise before alerting
- Profile **Notification channels** — org-wide destinations
