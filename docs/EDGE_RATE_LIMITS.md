---
title: "Edge rate limits"
slug: edge-rate-limits
category: "Edge"
order: 113
description: "Cap requests per IP on a path pattern. Block with HTTP 429 or challenge when bot protection is enabled."
group: edge
---

# Edge rate limits

**Rate limits** count requests **per visitor IP** on matching paths. When someone exceeds the limit in the time window, Edge stops them before your site or origin does the work.

Useful against scrapers, credential stuffing, and runaway clients.

Requires **Dply-hosted Edge delivery**.

## Rate limits vs waiting room

| | **Rate limits** | **Waiting room** |
|--|-----------------|------------------|
| Goal | Stop abusive volume from one IP | Cap total concurrent humans |
| Unit | Requests / IP / window | Active sessions site-wide |
| When exceeded | **429** or bot **Challenge** | “You’re in line” queue page |

Use **Waiting room** for launches; use **Rate limits** for abuse and API protection.

## What happens when a limit is hit

### Block (429)

Edge returns plain HTTP **429 Too Many Requests** with a `Retry-After` header. Best for APIs, bots, and automated clients.

### Challenge

Edge serves a bot-check page on the **same URL**. Passing the check lets that request through.

**Challenge** needs **Bot protection** (site + secret keys) enabled. Without keys, Challenge behaves like Block.

## What a rule contains

| Field | Purpose |
|-------|---------|
| **On** (path) | e.g. `/*`, `/api/*`, `/login` — must start with `/` (or be `*`) |
| **Allow** | Requests per IP in the window (1–10,000) |
| **Requests every (seconds)** | How long the counter covers (1–3,600) |
| **Then** | **Block (HTTP 429)** or **Ask to prove they're human** (bot challenge) |

First matching path rule applies for that request.

Example: `60` requests / `60` seconds on `/api/*` ≈ one request per second average per IP.

## How to set it up

1. Open **Rate limits** and choose **Add a rule** — start from a preset (Login `/login` 5/min challenge, API `/api/*` 60/min, Forms `/contact` 10/min, Whole site `/*` 600/min) or set path, allowance, window and action. The dialog reads the rule back ("about one request a second per visitor…").
2. **Save** in the dialog; the first rule turns rate limits on. Rules show as sentences; click one to edit or remove it. **Rate limits are on** saves on click.
3. Prefer specific paths (`/api/login`, form endpoints) over site-wide `/*` when possible — a tight `/*` limit also throttles CSS/JS for real browsers.
4. Every change republishes delivery and applies on the next request.

## Tips

- Protect login and form endpoints tightly; leave static asset paths open.
- Stack with **Bot protection** and **Forms** for layered defense.
- After enabling, watch **Traffic & analytics** / live requests for unexpected 429s.

## Related sections

- **Bot protection** — required for Challenge action
- **Waiting room** — concurrent visitor queue for launches
- **Forms** — common path to rate-limit
- **Traffic & analytics** — see if legitimate traffic is being cut off
