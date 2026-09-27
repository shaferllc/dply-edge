---
title: "Rate limits"
description: "Cap how many requests a single visitor IP can make to a path, and block or challenge anyone who goes over."
---

Rate limits count requests per visitor IP address on the paths you choose. A visitor who goes over the limit within the time window is stopped at the edge, before your app or origin does any work. Use rate limits to protect login endpoints, APIs and form handlers from brute force, scraping and spam.

Rate limits are about one client sending too much. To cap the total number of people using your app at once, use the [Waiting room](/docs/waiting-room).

> [!NOTE]
> Rate limits need dply-hosted delivery, which is the default. Apps delivered from your own Cloudflare account show a notice instead.

## Add rate limits

1. Open your app and choose **Rate limits**.
2. Turn **Enable rate limits** on.
3. For each rule, set:
   - **Path pattern**: `/api/*`, `/login`, or `/*` for every path.
   - **Max requests**: requests allowed per IP in the window.
   - **Window (seconds)**: the length of each counting window. The count starts again at zero when a new window begins.
   - **When exceeded**: **Block (429)** or **Challenge (bot check)**.
4. Choose **Add rule** for more rules, then **Save**.

Rules take effect within about a minute, with no redeploy. The form starts with a suggested rule, 120 requests per 60 seconds on `/*`, which does nothing until you enable rate limits and save.

### Limits on values

| Setting | Allowed range |
|---------|---------------|
| **Max requests** | 1 to 10,000 |
| **Window (seconds)** | 1 to 3,600 |

Values outside these ranges are clamped when the rules are published.

## Path patterns

| Pattern | Matches |
|---------|---------|
| `/login` | `/login` exactly |
| `/api/*` | `/api` and everything under `/api/` |
| `/*` | every path |

Every rule whose pattern matches a request counts it, not only the first. For example, with rules on `/*` and `/api/*`, a request to `/api/users` counts toward both, and the visitor is stopped by whichever limit they exceed first.

> [!TIP]
> Keep limits on `/*` generous. One page load can fetch dozens of scripts, styles and images. Put tight limits on specific endpoints such as `/login` or `/api/*`.

## What happens when a limit is hit

### Block (429)

The request gets a plain-text response:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 60
Content-Type: text/plain; charset=utf-8

Too Many Requests
```

`Retry-After` is the rule's window in seconds. Use Block for APIs and machine clients.

### Challenge (bot check)

The visitor sees a verification page on the same URL. After they pass the check, the page reloads as a `GET` with the verification token, and that request goes through. A `POST` that trips the limit isn't resent, so the visitor submits the form again. Use Challenge for pages real people use, such as login and sign-up, so a person who trips the limit can continue.

Challenge uses your [Bot protection](/docs/bot-protection) keys. Without them, or while bot protection is off, **Challenge** behaves like **Block**.

## How counting works

- Requests are counted per app, per visitor IP, per rule and per window. Traffic to one app never counts toward another app's limits.
- Windows are fixed, not sliding: a 60-second window resets on each whole minute, so a visitor can briefly send up to twice the limit across a window boundary.
- Counts are kept at each Cloudflare location, not globally. A visitor whose requests reach two data centers is counted separately in each, so treat limits as approximate.
- If a count can't be read or written, the request is allowed. Rate limiting never takes your app down.

## Seeing rate-limited requests

Open **Security** to see how many requests were rate-limited (HTTP 429) in the last 7 days, and the most recent ones.

## Related

- [Bot protection](/docs/bot-protection)
- [Waiting room](/docs/waiting-room)
- [Firewall](/docs/firewall)
- [Forms](/docs/forms)
