---
title: "Waiting room"
description: "Queue visitors at the edge during launches and traffic spikes, so your app only admits as many people as it can handle."
---

A waiting room holds extra visitors in a queue when too many arrive at once, and lets them in at a steady rate. People wait on the URL they opened. There's no separate queue domain and no redirect. Use it for launches, ticket drops and sales, where a spike could overwhelm your app or its database.

> [!NOTE]
> The waiting room needs dply-hosted delivery, which is the default.

## Set up a waiting room

1. Open your app and choose **Waiting room**. The page opens with a sentence that sums up the room, for example "Up to 200 people can use /checkout/* at once. Past that, visitors wait in line and 20 more get in each minute."
2. Click a setting to change it, then choose **Save** in the dialog:
   - **Only these pages can have a line** (protected paths, one per line), such as `/checkout/*`. Leave it empty to protect the whole app (`/*`).
   - **Up to N people are let in at the same time** (max active visitors).
   - **N more people get in each minute** (let in per minute). While you edit this or the max, the dialog estimates how long the last person in a rush would wait.
   - **An admitted visitor keeps their spot for N minutes** (session length).
3. Tick **Waiting room is on**. It saves as soon as you tick it.

Changes go live on the next request. New apps start with 200 active visitors, 20 per minute, 30-minute sessions and `/*`. Choose **See what visitors see when it's full** to preview the line page.

| Setting | Allowed range |
|---------|---------------|
| **Max active visitors** | 1 to 100,000 |
| **Let in per minute** | 1 to 10,000 |
| **Session length (minutes)** | 1 to 1,440 |

## What visitors experience

1. **Arrive.** A visitor requests a protected path.
2. **Admit or wait.** If there's room under both the max active visitors and the let-in-per-minute rate, they're let through and get a session cookie. Otherwise they see a **You're in line** page (HTTP 503 with `Retry-After`), which refreshes itself every few seconds.
3. **Enter.** Once admitted, the visitor uses the app normally until the session ends. After that they may queue again on their next protected request.

The waiting page is served by dply and can't be branded yet:

> **You're in line**
> This site is at capacity. We'll refresh automatically.

## Choosing values

- Protect only the paths that are expensive or limited, such as `/checkout/*` or `/tickets/*`. Leave marketing and content pages out so people can keep reading.
- Set **let in per minute** to what your app can absorb: a surge of new sessions is usually harder on an app than steady browsing.
- Start with a low **Max active visitors** and raise it once you've seen the queue drain cleanly.

## How it works and its limits

- Admitted visitors carry a `dply_wr` cookie (`HttpOnly`, `Secure`, `SameSite=Lax`) for the session length. Requests with the cookie skip the queue.
- Each admission counts toward **Max active visitors** for one session length, whether or not the visitor is still browsing. There's no sign-out: a visitor who leaves early keeps their place until the session ends.
- Counts are kept per app, so one app's traffic never fills another app's room.
- Counts are kept per Cloudflare location, not globally, so capacity is approximate when visitors arrive through many data centers.
- The session cookie is set on the response to the admitted request, for every kind of app: static, SSR and container.
- Paths use the same patterns as [rate limits](/docs/rate-limits#path-patterns): `/checkout` exactly, `/checkout/*` for everything under it, `/*` for every path.

> [!WARNING]
> Test the waiting room on a separate staging app before a launch. Because counts are per location and approximate, check that the capacity you set behaves the way you expect under real traffic.

## Related

- [Rate limits](/docs/rate-limits)
- [Scaling & sleep](/docs/scaling-and-sleep)
- [Error pages](/docs/error-pages): maintenance mode for a full stop
