---
title: "Edge snippets"
slug: edge-snippets
category: "Edge"
order: 115
description: "Inject small HTML into matching pages at the Edge without rebuilding — banners, meta, and trusted markup."
group: edge
---

# Edge snippets

**Snippets** inject small HTML into matching pages at the Edge — banners, meta, or support widgets — without rebuilding or redeploying your app.

Requires **Dply-hosted Edge delivery**.

## What a snippet contains

| Field | Purpose |
|-------|---------|
| **Name** | Label in the dashboard |
| **Add it** | In the head (before `</head>`) or at the end of the body (before `</body>`) |
| **On pages matching** | Pattern (`/*` for all pages, or `/blog/*`) |
| **HTML** | Markup to inject (keep small and trusted) |

## How to use it

The page draws `<html>` → `<head>` → end of `<body>`, with each snippet in its slot and its path ("every page" or e.g. `/blog/*`).

1. Click **+ Add to head** or **+ Add to end of body** (or click an existing snippet to edit it).
2. Set a path and paste HTML — or start from an example (offered on new snippets).
3. Replace placeholders (`G-XXXXXXXXXX`, `your-domain.com`, etc.) before Save.
4. **Save** in the modal — delivery republishes; visitors see the inject on the next request. The first snippet saved turns snippets on.

The footer checkbox (**Snippets on · N added at the Edge, no redeploy**) turns them all off/on and saves on click.

## Example starters

| Example | Inject | What it does |
|---------|--------|----------------|
| Basic SEO meta | `</head>` | Description + Open Graph tags |
| Noindex | `</head>` | `noindex, nofollow` for staging-like hosts |
| Announcement banner | `</body>` | Simple announcement bar |
| JSON-LD Organization | `</head>` | Organization structured data |

## `dply.yaml`

```yaml
snippets:
  enabled: true
  items:
    - name: Meta
      phase: head   # head | body
      path: /*
      html: '<meta name="description" content="Acme — ship faster.">'
```

Saving snippets in the dashboard overrides the repo for the whole `snippets` section.

## Tips

- Prefer **Tags** for analytics, pixels and third-party scripts (it writes the vendor setup code and handles consent); use Snippets for other inline markup.
- A snippet needs HTML to save.
- Narrow paths keep marketing scripts off app routes.
- Only inject HTML you trust — this runs in every matching visitor’s page.

## Related sections

- **Tags** — analytics, pixels and third-party scripts by vendor ID, with consent gating
- **Build** — for HTML that should ship with the app repo instead
