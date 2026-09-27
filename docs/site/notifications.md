---
title: "Notification channels"
description: "Create the destinations dply sends alerts to, such as Slack, email, PagerDuty, or a webhook, and choose who owns them."
---

A notification channel is a destination for alerts: a Slack channel, an email address, a PagerDuty service, an HTTP webhook, and so on. You create a channel once, then subscribe it to events on each app from the app's [Alerts](/docs/alerts) page. A channel with no subscriptions receives nothing.

## Channel types

| Type | What you provide |
|------|------------------|
| **Slack** | Choose **Add to Slack** to connect a workspace once and pick a channel, or paste an incoming webhook URL. |
| **Discord** | Choose **Add to Discord** to connect a server once and pick a channel, or paste a webhook URL. |
| **E-mail address** | One email address. |
| **Telegram** | Choose **Connect Telegram** to add the dply bot to a chat, or enter a bot token and chat ID. |
| **Microsoft Teams** | A Power Automate workflow URL (see below). |
| **PagerDuty** | An Events API v2 integration key, region, and default severity. |
| **Intercom** | An access token, region, admin ID, and recipient. |
| **HTTP webhook** | An endpoint URL that receives a JSON `POST`. |

Credentials are stored encrypted.

## Who owns a channel

Every channel has an owner, which decides who can see it and route events to it.

| Scope | Where to manage it | Who can create, edit, test, and delete | Who can route events to it |
|-------|--------------------|----------------------------------------|----------------------------|
| **Personal** | **Profile** → **Notification channels** | You | You |
| **Organization** | Your organization → **Notification channels** | Owners and admins | Owners and admins |
| **Team** | Your organization → **Teams** → a team's **Notifications** | Organization owners and admins, and the team's admins | Organization owners and admins, and the team's admins |

Organization channels are the better default for shared alerts. They survive people leaving the organization, while a personal channel goes with its owner's account. Only owners and admins can open the organization's **Notification channels** page. Other members of a team can view that team's channels but not change them. See [Teams](/docs/teams).

## Create a channel

1. Open the page for the scope you want (see the table above).
2. Choose **Add channel**.
3. Pick a **Type**, give the channel a **Label** you'll recognize later, and fill in the destination.
4. Choose **Create channel**.

You can also create a channel without leaving an app: on the app's **Alerts** page, choose **Create a channel**.

### Microsoft Teams

Teams channels use a Power Automate workflow. The older Office 365 incoming webhook connector is retired, and dply rejects its URLs.

1. In Teams, right-click the channel you want alerts in, then choose **Workflows**.
2. Choose the template **Post to a channel when a webhook request is received**.
3. Sign in when prompted, confirm the team and channel, and create the flow.
4. Copy the URL it shows you at the end and paste it into dply.

### PagerDuty

In PagerDuty, add an integration to the service that should be paged and choose **Events API v2**. dply does not support the older Events API v1. Paste the integration key, pick the region your PagerDuty account is in, and choose a **Default severity**. dply groups repeated alerts about the same app and event into one incident instead of opening a new one each time.

### Intercom

Intercom needs an access token from an app in the Intercom Developer Hub, the ID of the admin the messages come from, and the workspace's region (US, EU, or AU). A token issued in one region is rejected by the others, so check the region first if a correct token is refused.

## Webhook URLs must be public

Every URL you give dply (Slack and Discord webhooks, Teams workflows, and HTTP webhooks) must be reachable from the public internet. dply refuses:

- private, loopback, and link-local IP addresses, such as `10.0.0.5`, `127.0.0.1`, or `169.254.169.254`
- internal-only hostnames, such as `localhost` or names ending in `.local`, `.internal`, `.intranet`, or `.corp`
- hostnames that resolve to a private address when dply sends
- URLs that contain a username or password

dply does not follow redirects when it delivers to a webhook, and it waits up to 10 seconds for a response. To reach a service that has no public address, put it behind a tunnel and use the tunnel's public hostname.

## HTTP webhook payload

An HTTP webhook channel receives a JSON `POST` for each event it is subscribed to:

```json
{
  "event": "site.deployments",
  "severity": "error",
  "source": "Site 01J9Z3K8QX4T6M2N5P7R8S9V0W",
  "dedup_key": "dply:App\\Models\\Site:01J9Z3K8QX4T6M2N5P7R8S9V0W:site.deployments",
  "subject": "Edge deploy failed: marketing-site",
  "text": "The build exited with code 1.",
  "action_url": "https://edge.dply.io/...",
  "sent_at": "2026-09-26T14:03:11+00:00"
}
```

`event` is the event's key, the same one you subscribe to on the [Alerts](/docs/alerts) page, so you can branch on it. `severity` and `source` can be `null`. A test message has `"event": "notification_channel.test"`, with `label`, `app`, `actor` and `sent_at`.

Every request also carries these headers:

| Header | Value |
|--------|-------|
| `X-Dply-Event` | The same value as `event`. |
| `X-Dply-Delivery-Id` | A unique ID for the delivery. It stays the same when a delivery is retried, so you can use it to ignore duplicates. |
| `X-Dply-Timestamp` | The Unix time the request was signed. |
| `X-Dply-Signature` | `t=<timestamp>,v1=<hex>`, an HMAC-SHA256 of `<timestamp>.<raw body>` keyed with the channel's signing secret. |

### Verify the signature

Open the channel with **Edit** to see its **Signing secret**. Compute the HMAC over the raw request body exactly as received, before any JSON parsing, and compare it in constant time. Reject requests whose timestamp is more than a few minutes old.

```php
$body = file_get_contents('php://input');
[$t, $v1] = array_map(fn ($p) => explode('=', $p, 2)[1], explode(',', $_SERVER['HTTP_X_DPLY_SIGNATURE']));
$expected = hash_hmac('sha256', $t.'.'.$body, getenv('DPLY_WEBHOOK_SECRET'));

if (! hash_equals($expected, $v1) || abs(time() - (int) $t) > 300) {
    http_response_code(401);
    exit;
}
```

```js
import { createHmac, timingSafeEqual } from 'node:crypto';

// rawBody: the request body as a string, before JSON parsing
const { t, v1 } = Object.fromEntries(req.headers['x-dply-signature'].split(',').map((p) => p.split('=')));
const expected = createHmac('sha256', process.env.DPLY_WEBHOOK_SECRET).update(`${t}.${rawBody}`).digest('hex');
const ok = expected.length === v1.length && timingSafeEqual(Buffer.from(expected), Buffer.from(v1))
  && Math.abs(Date.now() / 1000 - Number(t)) < 300;
```

The signing secret belongs to the channel and doesn't change when you edit its URL. To get a new secret, delete the channel and create it again. If dply's own application key is ever rotated, every channel's secret changes, and you must copy the new one.

### Delivery and retries

Return any `2xx` status to acknowledge delivery. Event deliveries are sent in the background. dply retries a delivery when your endpoint can't be reached, returns a `5xx` status, or returns `429`. It tries up to 5 times in total, waiting about 10 seconds, 1 minute, 5 minutes and 15 minutes between attempts. Each retry is signed again with a new timestamp and keeps the same `X-Dply-Delivery-Id`. Any other `4xx` status is treated as final and isn't retried. The **Test** button sends once and shows you the result.

## Send a test

Choose **Test** on a channel to send a test message right away. It proves the credential works now, instead of during an incident. If the destination rejects the message, dply shows the reason. Each test is recorded in the [Activity log](/docs/activity-log).

## Route events to a channel

Subscribe channels to events per app, from the app's **Alerts** page. See [Alerts](/docs/alerts) for the list of events.

To subscribe several channels to several events across many apps at once, open **Profile** → **Notification channels** and choose **Bulk assign**. Pick the channels, the events, and the apps, then assign.

## Edit or delete a channel

Choose **Edit** to change a channel's label or destination, or **Delete** to remove it. Deleting a channel removes its subscriptions. Creating, editing, and deleting channels is recorded in the [Activity log](/docs/activity-log).

## Related

- [Alerts](/docs/alerts)
- [Teams](/docs/teams)
- [Roles & permissions](/docs/roles-and-permissions)
- [Spending caps & alerts](/docs/spending-alerts)
