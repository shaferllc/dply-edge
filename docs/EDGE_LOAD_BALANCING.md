---
title: "Edge load balancing"
slug: edge-load-balancing
category: "Edge"
order: 117
description: "Spread a hybrid site's origin traffic across several servers, with health checks and automatic failover."
group: edge
---

# Edge load balancing

**Load balancing** puts a health-checked pool of origin servers behind your hybrid site. Edge keeps serving static files from its own storage. Requests on your origin routes (say `/api/*`) go to whichever servers are healthy, and a server that fails its checks stops receiving traffic until it recovers.

Requirements:

- Dply-hosted Edge delivery
- A **hybrid** site with origin routes (set under **Delivery**)
- A paid, monthly subscription

## Pricing

**$8 per endpoint per month**, billed on your subscription. Adding or removing an endpoint changes your next invoice immediately, prorated. Every configured endpoint counts while the load balancer is active, including ones with weight 0 or traffic turned off. Nothing is billed while it's still being set up or if Cloudflare setup fails.

## Endpoints

| Field | Purpose |
|-------|---------|
| **Name** | Label (letters, digits, `-`, `_`) |
| **Address** | Public IP or hostname. No `https://` and no path. |
| **Port** | Optional. Defaults to 443. |
| **Weight** | 0–1: share of traffic under weighted steering |
| **Host header** | Optional. The `Host` your server expects. Without it, servers see the load balancer hostname. |
| **Receives traffic** | Turn off to drain a server without removing it |

## Health checks

Each endpoint gets an HTTPS `GET` on the **health check path** every 60 seconds from multiple locations. A response that doesn't match **Expected status** (default `2xx`) marks it unhealthy. **Check health** shows the current state per endpoint.

## Traffic steering

| Policy | Behaviour |
|--------|-----------|
| Random (weighted) | Spread by weight |
| Least outstanding requests | Prefer the server with the fewest in-flight requests |
| Least connections | Prefer the server with the fewest open connections |
| Hash | Same client IP → same server (sticky) |

## Turning it off

Switch it off and Save. The load balancer is removed, billing for its endpoints stops, and origin routes go back to the single origin URL under **Delivery**.
