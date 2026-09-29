---
title: "Edge error pages"
slug: edge-error-pages
category: "Edge"
order: 119
description: "Brand 404 and 500 HTML and flip maintenance mode (503) at the Edge without a redeploy."
group: edge
---

# Edge error pages

**Error pages** let you brand **404** and **500** responses and take the site offline with **maintenance mode (503)** — all at the Edge, without touching your repo.

## What you can set

| Control | Purpose |
|---------|---------|
| **Maintenance mode** | Visitors get 503 until you turn it off (checkbox saves on click) |
| **404 page** | HTML when a path isn’t found (blank = built-in default) |
| **500 page** | HTML for unexpected errors (blank = built-in default) |
| **Blocked-country page** | HTML for the 403 the Firewall returns to a blocked country (blank = plain text). Dashboard only — no dply.yaml key |
| **Maintenance page** | HTML for the 503 (blank = built-in default) |

## How to use it

Each page is a row labelled **Custom**, **From dply.yaml** or **Built-in**.

1. Click a page. The modal has starter buttons (Minimal / Friendly / Enterprise) for that page, the HTML editor, and a live sandboxed preview (scripts don't run).
2. Edit the HTML, or choose **Use the built-in page** to go back to the default.
3. **Save** republishes delivery immediately — the new HTML is live on the next request, no rebuild or redeploy. **Cancel** discards unsaved edits.
4. Turn on **Maintenance mode** for a hard stop during incidents or cutovers; it takes effect right away.

## `dply.yaml`

```yaml
error_pages:
  html_404: |
    <!doctype html><html lang="en"><head><meta charset="utf-8"><title>404</title></head>
    <body><h1>Page not found</h1></body></html>
  html_500_path: "public/500.html"

maintenance:
  enabled: false
  html_path: "public/maintenance.html"
```

Path fields are inlined at build time into the deploy snapshot. Dashboard values override matching fields when both are set.

## Tips

- Keep error HTML self-contained (inline CSS). External assets may fail if the site is broken.
- Repo `dply.yaml` can declare error pages; dashboard values are the operator override.
- For launch queues (not full downtime), prefer **Waiting room** over maintenance.

## Related sections

- **Waiting room** — soft queue during spikes
- **Delivery** — host map / cache tools
- **Build** — SPA fallback and static routing that affect what becomes a 404
