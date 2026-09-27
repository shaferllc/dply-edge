---
title: "Edge network"
description: "How requests reach your dply app through Cloudflare's global network, where content is stored and cached, and what HTTPS and DDoS protection you get."
---

Every dply app is served from Cloudflare's global network. A visitor's request is answered by the Cloudflare data center closest to them. dply's routing, security rules and caching run there before anything reaches your app. This page explains that path, and what is stored and cached where.

## How a request is served

1. **DNS and TLS.** Your default hostname, or your [custom domain](/docs/domains), resolves to Cloudflare. The HTTPS connection ends at the nearest Cloudflare location, using a certificate dply manages.
2. **Edge rules.** dply applies maintenance mode, the [firewall](/docs/firewall), the [waiting room](/docs/waiting-room), [forms](/docs/forms), [rate limits](/docs/rate-limits), [access control](/docs/access-control) and [routing](/docs/routing), in that order.
3. **Content.** Depending on the app type:
   - **Static files** are read from the deploy's storage and cached at that location.
   - **Hybrid apps** serve static files the same way, and proxy origin routes to your origin.
   - **SSR apps** run your server code on Cloudflare's network, in the same request. See [Server rendering (SSR)](/docs/server-rendering).
   - **Container apps** forward the request to your app's container. See [Container apps](/docs/containers).
4. **Response.** Header rules, and HTML additions such as [snippets and tags](/docs/snippets), are applied, and the response is sent.

## Where your content lives

| What | Where | Notes |
|------|-------|-------|
| Build output (static files) | Cloudflare R2 object storage, one folder per deploy | Earlier deploys are kept, so [rollbacks](/docs/deployments) are instant. |
| Static files, cached | Each Cloudflare location that served them | Filled on first request, per location. |
| Edge cache (app responses) | Cloudflare's global key-value store | Configured on the **Cache** page. See [Caching](/docs/caching). |
| Routing and security settings | Cloudflare's global key-value store | Changes reach every location within about a minute. |
| SSR code | Cloudflare Workers | Runs in the location that received the request. |
| Container apps | Cloudflare Containers | Placed by region. See [Data regions](/docs/data-regions). |
| Databases and Valkey from dply | dply's database cluster on DigitalOcean, New York | See [Data regions](/docs/data-regions). |

## HTTPS

- The default `on-dply.live` hostname is served over HTTPS from the first deploy.
- Custom domains get a certificate once DNS is verified. dply issues and renews it automatically. See [Domains](/docs/domains#https-certificates).
- You don't upload or manage certificates, and there's no per-app setting for TLS versions or ciphers.

To send HSTS, add a `Strict-Transport-Security` header rule in [Routing](/docs/routing#headers). The **Security headers** template adds `max-age=31536000; includeSubDomains`.

## DDoS protection

Because every request passes through Cloudflare, your app gets Cloudflare's network-level DDoS mitigation automatically. It's always on and there's nothing to configure. Your app's **Overview** shows it under **Edge network** as **DDoS protection: Active**.

For abuse aimed at your app itself, such as scraping, credential stuffing or form spam, use:

- [Rate limits](/docs/rate-limits) to cap requests per visitor IP.
- [Bot protection](/docs/bot-protection) to add a challenge to forms.
- The [Firewall](/docs/firewall) to allow or block countries.
- The [Waiting room](/docs/waiting-room) to queue visitors during launches.

## Response headers you'll see

| Header | Meaning |
|--------|---------|
| `X-Dply-Deployment-Id` | The deploy that served a static response. |
| `X-Dply-Edge-Cache` | `HIT` or `STALE` when the response came from the edge cache. |
| `X-Dply-Repo-Redirect` | `1` on a redirect produced by a routing rule. |
| `Age` | Seconds since an edge-cached response was stored. |

## Related

- [Caching](/docs/caching)
- [Domains](/docs/domains)
- [How dply works](/docs/how-dply-works)
- [Platform security & isolation](/docs/platform-security)
