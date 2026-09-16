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
| **Inject** | Before `</head>` or before `</body>` |
| **Path** | Pattern (`/*` for all pages, or `/blog/*`) |
| **HTML** | Markup to inject (keep small and trusted) |

## How to use it

1. Enable snippets (or click an **Example** — that enables it for you).
2. Choose head vs body, set a path, paste HTML — or start from an example chip.
3. Replace placeholders (`G-XXXXXXXXXX`, `your-domain.com`, etc.) before Save.
4. **Save** — delivery republishes; visitors see the inject on the next request.

## Example starters

| Example | Inject | What it does |
|---------|--------|----------------|
| Meta | `</head>` | Description + Open Graph tags |
| Noindex | `</head>` | `noindex, nofollow` for staging-like hosts |
| Banner | `</body>` | Simple announcement bar |
| JSON-LD | `</head>` | Organization structured data |

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

Dashboard **Save** overrides the repo for the whole `snippets` section.

## Tips

- Prefer **Tags** for analytics, pixels and third-party scripts (it writes the vendor setup code and handles consent); use Snippets for other inline markup.
- Empty HTML rows are ignored on Save.
- Narrow paths keep marketing scripts off app routes.
- Only inject HTML you trust — this runs in every matching visitor’s page.

## Related sections

- **Tags** — analytics, pixels and third-party scripts by vendor ID, with consent gating
- **Build** — for HTML that should ship with the app repo instead
