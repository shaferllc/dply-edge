---
title: "Messages"
description: "Send HTTP requests later, with retries, callbacks and cron schedules: a message queue your app publishes to over HTTPS."
---

dply Messages delivers HTTP requests for your app: now, after a delay, or on a cron schedule. It retries failures with backoff, can call you back when a delivery succeeds or gives up, and signs every request so the receiver can check it came from dply. Clients written for Upstash QStash work too: the `Upstash-*` header names are accepted alongside `Dply-*`.

> [!NOTE]
> Messages is being rolled out to organizations one at a time. If **Messages** isn't in **Add resource**, it isn't on for your organization yet.

## Add it to an app

1. In your app, open **Overview**.
2. Choose **Add resource**, then **Messages**.
3. Deploy the app.

The next deploy sets four variables:

| Variable | What it is |
| --- | --- |
| `MESSAGES_URL` | Where to publish |
| `MESSAGES_TOKEN` | Your app's publish token (a secret) |
| `MESSAGES_SIGNING_KEY` | Checks the signature on deliveries |
| `MESSAGES_NEXT_SIGNING_KEY` | The next signing key, for rotations |

Manage tokens and signing keys under **Projects → Messages**.

## Publish

Send the body you want delivered, with the destination URL in the path:

```bash
curl -X POST "$MESSAGES_URL/v2/publish/https://example.com/hooks/welcome" \
  -H "Authorization: Bearer $MESSAGES_TOKEN" \
  -H "Content-Type: application/json" \
  -H "Dply-Delay: 10m" \
  -d '{"user": 42}'
```

The reply holds the message id. Useful headers:

| Header | Effect |
| --- | --- |
| `Dply-Delay` | Deliver later, for example `30s`, `10m`, `2h`, `7d` (at most 7 days) |
| `Dply-Not-Before` | Deliver at a Unix time |
| `Dply-Retries` | Retries after a failed delivery, up to 5 |
| `Dply-Method` | The method to deliver with (default `POST`) |
| `Dply-Timeout` | How long a delivery may take, up to 5 minutes |
| `Dply-Callback` | A URL called with the response after a successful delivery |
| `Dply-Failure-Callback` | A URL called when the retries run out |
| `Dply-Deduplication-Id` | Publishing the same id twice delivers once |
| `Dply-Forward-*` | Sent on to the destination with the prefix removed |

A delivery is done when the destination answers 2xx. Anything else is retried with growing gaps (at most a day apart). After the last retry the message goes to the dead-letter list, where you can see it, retry it or delete it.

Delivery is at least once: make receivers safe to run twice.

## Schedules

Publish to `/v2/schedules/{url}` with a `Dply-Cron` header (5 fields, UTC) to send the same request on a schedule:

```bash
curl -X POST "$MESSAGES_URL/v2/schedules/https://example.com/jobs/nightly" \
  -H "Authorization: Bearer $MESSAGES_TOKEN" \
  -H "Dply-Cron: 0 3 * * *"
```

Schedules can be listed, paused, resumed and deleted. An organization can have up to 100.

## Check the signature

Each delivery carries a `Dply-Signature` header (also sent as `Upstash-Signature`): a JWT signed with `MESSAGES_SIGNING_KEY`, whose claims include the destination URL and a SHA-256 of the body. Verify it with the current key and fall back to `MESSAGES_NEXT_SIGNING_KEY`, so a key rotation never rejects a good delivery. QStash's `Receiver` from `@upstash/qstash` works with these keys.

## Limits

- Body: 1 MB
- Delay: 7 days
- Retries: 5
- Publishes: 100 per second per organization
- Destinations must be public `http(s)` URLs: not localhost, private or reserved addresses, `.internal` or `.local` names, or dply's own hosts.

Not supported yet: topics (URL groups), queues with parallelism, flow control and cron time zones.

## Pricing

<!-- generated: php artisan dply:billing:price-table rates --group="Messages" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Published messages (schedule firings and callbacks count) | $0.50 | per 100,000 |

<!-- /generated -->

Schedule firings and callbacks count as published messages. Usage comes out of your plan's included usage credit first.
