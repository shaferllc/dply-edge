---
title: "Firewall"
description: "Allow or block visitors by country at the edge, before they reach your pages, forms or app."
---

The firewall allows or blocks visitors by country. It runs at the edge, before your pages, forms, origin or app do any work, and blocked visitors get an HTTP 403. Use it to keep an app to the markets you serve, or to shut out countries you see abuse from.

## Modes

| Mode | Behavior |
|------|----------|
| **Off** | No country checks. The default. |
| **Allow listed only** | Only the listed countries get in. Every other country gets a 403. |
| **Block listed** | The listed countries get a 403. Everyone else passes. |

> [!WARNING]
> **Allow listed only** blocks every country you didn't list, including visitors whose location can't be determined. Add every market you serve, and your own, before you save.

A mode with an empty country list doesn't enforce anything.

## Set up the firewall

1. Open your app and choose **Firewall**.
2. Pick a **Mode**.
3. Under **Countries**, search by country name or ISO code (for example `US` or `DE`) and add each one. Use the arrow keys and **Enter** to add, and **Backspace** to remove the last one.
4. Choose **Save**.

The new rules reach Cloudflare's network within about a minute. You don't need to redeploy.

## What blocked visitors see

A blocked visitor gets a plain-text 403 on the URL they requested:

```http
HTTP/1.1 403 Forbidden
Content-Type: text/plain; charset=utf-8
Cache-Control: no-store, max-age=0

Forbidden — content is not available in this region (XX).
```

`XX` is the visitor's two-letter country code, or `unknown`. You can't customize this page yet.

## How the country is determined

dply uses the country Cloudflare assigns to each request (ISO 3166-1 alpha-2). VPNs, proxies and privacy relays make visitors appear to come from the country of their exit node. Traffic from the Tor network is reported as `T1`, which isn't a country you can list, so **Allow listed only** blocks it.

Geo rules are coarse. For abuse that doesn't follow country lines, combine them with [Rate limits](/docs/rate-limits) and [Bot protection](/docs/bot-protection).

## Firewall rules in `dply.yaml`

You can commit rules in your repository:

```yaml
firewall:
  country_mode: block   # off, allow or block
  countries:
    - KP
    - RU
```

On deploy, repository and dashboard rules merge:

- **Countries** from both lists are combined. A country listed in the repository can't be removed from the dashboard.
- **Mode**: a dashboard mode of **Allow listed only** or **Block listed** takes precedence. If the dashboard is **Off**, the repository's mode applies.

The **Advanced** section of the **Firewall** page shows the repository's rules.

## Seeing blocked requests

Open **Security** to see how many requests the firewall blocked (HTTP 403) in the last 7 days, with the most recent paths and countries.

## Related

- [Rate limits](/docs/rate-limits)
- [Bot protection](/docs/bot-protection)
- [Access control](/docs/access-control)
- [Logs](/docs/logs)
