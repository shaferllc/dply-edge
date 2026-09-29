---
title: "Edge routing"
slug: edge-routing
category: "Edge"
order: 110
description: "Domains, redirects, rewrites and header rules for an Edge site, from the dashboard or dply.yaml."
group: edge
---

# Edge routing

**Routing** has four tabs: **Domains** (see [Edge domains](EDGE_DOMAINS.md)), **Redirects**, **Rewrites** and **Headers**.

Each rules tab opens with one sentence that sums it up ("2 redirects send visitors to a new address for good") and lists every rule as a row in plain words ("/old-page moves permanently to /new-page"). Rules from `dply.yaml` are read-only rows marked with the file name. Dashboard rules open the rule dialog (`openRule` / `saveRule` / `removeOpenRule` in `Routing`), which edits in place. Changes are stored in `routing_overrides` and the host map is republished, so they apply without a deploy.

Other rows on the tabs:
- **Import many at once** (Redirects) opens a dialog that takes a bulk-redirects CSV or a `_redirects` block; duplicates are skipped.
- **Start from "…"** applies a template after a confirm: Security headers, Long-cache static assets, Proxy /api/*, Blog URL migration.
- **Keep these in dply.yaml** shows the matching YAML.

## Rule types

| Type | Typical use |
|------|-------------|
| **Redirects** | Permanent (301/308) or temporary (302/307) URL moves |
| **Rewrites** | Serve a different path internally, or proxy to a full URL |
| **Headers** | Security or cache headers on path patterns |

Repo rules come first, dashboard rules after. See the public [Routing](site/routing.md) page for matching and evaluation order.
