---
title: "Edge bindings"
slug: edge-bindings
category: "Edge"
order: 112
description: "Attach KV, R2, D1, and Queues to your Edge Worker as env.NAME bindings."
group: edge
---

# Edge bindings

**Bindings** attach Cloudflare resources to your Edge Worker so middleware or SSR code can use `env.NAME` (KV, R2, D1, Queues).

They only apply when the site has a Worker (middleware script and/or SSR). Pure static sites serve from object storage with no Worker `env`.

## Dashboard vs repo

| Source | Editable here? | Notes |
|--------|----------------|-------|
| **Dashboard** | Yes | Stored on the site; applied on the next deploy |
| **Repo (`wrangler.toml`)** | Read-only | Repo wins on name collision |

## How to use it

1. Choose a **name** (JS identifier, e.g. `JOBS`, `SESSIONS`).
2. Pick a **type** (KV, R2, D1, Queue).
3. Attach an existing resource id/name, or create a new one.
4. Redeploy so the Worker upload includes the binding.

Reserved names (`HOST_MAP`, `ASSETS`, `SITE_ID`, …) are blocked — the platform already injects them.

## Tips

- **Jobs** can manage queue bindings in a modal without leaving that page.
- Creating resources needs platform Edge credentials on the control plane.
- Detach removes the binding from the site; the underlying resource stays.

## Related sections

- **Jobs** — Projects queues this app runs or sends to, and queue bindings for Worker code
- **Environment** — plaintext env vars (not Cloudflare bindings)
- **Build** / **Delivery** — where Worker context is configured
