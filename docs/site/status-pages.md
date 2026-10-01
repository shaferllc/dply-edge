---
title: "Status pages & uptime"
description: "Publish a public status page for your apps, post incidents and updates, and list components with their current state."
---

A status page is a public page where you tell your users how your service is doing. It lists components and their current state, shows active incidents with updates, and keeps a history of resolved incidents. Status pages belong to your organization, and one page can cover several apps.

## Uptime checks

Every app gets two uptime checks when it's created: **Homepage (HTTPS)** and **Homepage (HTTP)**. They request the app's live URL every 5 minutes. When the app has a verified custom domain, the checks use that domain; otherwise they use the app's `on-dply.live` hostname. A check that is down is retried every 15 minutes until it recovers.

A check reads **Operational** when the page answers with a success status, **Degraded** when it answers slowly, and **Outage** when it fails. A [container app](/docs/containers) that is asleep reads **Asleep**: the check does not wake it, and a check of an awake app does not count as a request, so checks never keep an app from sleeping. Until an app has been deployed, it has no live URL, so its checks read **Outage**.

To hear about it when a check goes down or recovers, subscribe to the **Site uptime monitoring** events on the app's **Alerts** page. See [Notification channels](/docs/notifications).

## Create a status page

1. In the top navigation, open **Status**.
2. Choose **New status page**, enter a **Name** (for example `Acme Production`) and an optional **Description**, then choose **Create status page**. New pages are public.
3. Choose **Manage** on the new page to set it up. Choose **View public page** to see it as visitors do.

The public URL is `/status/<id>` on dply. It's shown on the status page list next to **Public URL:**.

## Page details and visibility

Under **Page details**, edit the **Name** and **Description**, and choose **Visibility**:

- **Public status page (anyone with the link can view)**: the page is served at its public URL.
- **Private**: the public URL returns 404. The page is only visible inside dply.

Choose **Save** to apply.

## Components (monitors)

Under **Monitors**, pick a **Type**, choose the app, optionally set a **Label** to override the display name, and choose **Add monitor**. There are two types:

- **App**: one component for the whole app. It shows the worst state among the app's uptime checks.
- **App uptime check**: one component for a single check, for example only **Homepage (HTTPS)**.

Each component shows one of these states on the public page:

| State | Meaning |
|-------|---------|
| **Operational** | Healthy. |
| **Degraded** | Up but impaired. |
| **Outage** | Down. |
| **Asleep** | A container app that is asleep. Its checks do not wake it, and it counts as healthy. |
| **Unknown** | No recent check: the app was just added, or its checks have stopped reporting. |

**Unknown** is shown on the component only. It doesn't change the page's overall banner.

## Incidents

Incidents are how you tell users what's happening.

### Open an incident

1. On the status page, go to **Incidents**.
2. Enter a **Title**, choose an **Impact** (**None**, **Minor**, **Major** or **Critical**), and write the **First update**.
3. Choose **Open incident**.

A new incident starts as **Investigating**.

### Post updates and resolve

- Write in the update box under an incident and choose **Post** to add a timestamped update.
- Move the incident through **Investigating**, **Identified**, **Monitoring** and **Resolved**. Resolving records the resolution time.

### How incidents affect the banner

The public page shows a banner at the top:

| Condition | Banner |
|-----------|--------|
| Any unresolved incident | **Active incidents** |
| No open incidents, and any component in **Outage** | **Service disruption** |
| No open incidents, and any component **Degraded** | **Partial service degradation** |
| Otherwise | **All systems operational** |

Active incidents are listed first, then resolved ones with their resolution times.

## Delete a status page

Under the danger section of **Manage**, choose **Delete status page** and confirm. The page, its components and its incidents are deleted, and the public URL stops working.

## Related

- [Alerts](/docs/alerts)
- [Notification channels](/docs/notifications)
- [Traffic & analytics](/docs/traffic)
- [Logs](/docs/logs)
