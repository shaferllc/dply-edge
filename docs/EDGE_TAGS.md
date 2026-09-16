---
title: "Edge tags"
slug: edge-tags
category: "Edge"
order: 116
description: "Load analytics, pixels and third-party scripts from the Edge by ID, with page triggers, consent gating and a track() event API."
group: edge
---

# Edge tags

**Tags** load third-party tools (analytics, ads, chat) from the Edge. Pick a tool, paste its ID, and Edge injects the loader *and* the vendor setup code. No git deploy, no copy-pasted snippets.

Requires **Dply-hosted Edge delivery**.

## Tools

| Tool | ID | Default purpose |
|------|----|-----------------|
| Google Analytics (GA4) | Measurement ID `G-XXXXXXXXXX` | analytics |
| Google Tag Manager | Container ID `GTM-XXXXXXX` | analytics |
| Meta Pixel | Pixel ID (digits) | marketing |
| Microsoft Clarity | Project ID | analytics |
| Hotjar | Site ID (digits) | analytics |
| Plausible | Domain, e.g. `example.com` | analytics |
| Custom script | Any `https://` URL | analytics |

IDs are checked against each vendor's format on Save and on deploy. An invalid ID is rejected, never published.

Each tool also has:

| Field | Purpose |
|-------|---------|
| **Fire on path** | Page trigger: `/*` (all pages), `/checkout/*`, or an exact path |
| **Consent purpose** | `necessary`, `analytics` or `marketing` (see below) |
| **Async** | Custom scripts only |

## Consent

With **Require consent** on, tools whose purpose isn't `necessary` are held back until the visitor consents. Wire your banner to:

```js
window.__dplyTags.grant();                 // everything
window.__dplyTags.grant(['analytics']);    // only analytics tools
window.__dplyTags.revoke();                // forget the choice
window.__dplyTags.consent;                 // true once anything is granted
window.__dplyTags.purposes;                // e.g. ['analytics']
```

Held tools load the moment their purpose is granted. The choice persists in `localStorage` key `dply_tag_consent`, stored as `'1'` for everything or a JSON list of purposes, so it applies on later page loads without calling `grant()` again. `revoke()` stops tools on later pages, but it can't unload scripts that already ran.

## Events

```js
window.__dplyTags.track('signup', { plan: 'pro' });
```

`track()` forwards the event to every loaded tool: `gtag('event')`, the GTM `dataLayer`, `fbq('trackCustom')`, `plausible()`, `clarity('event')` and `hj('event')`.

## `dply.yaml`

```yaml
tags:
  enabled: true
  consent_required: true
  tools:
    - vendor: ga4
      id: G-XXXXXXXXXX
    - vendor: meta
      id: "123456789012345"
      purpose: marketing
      path: /checkout/*
    - name: Chat widget
      src: "https://widget.example.com/chat.js"   # no vendor = custom
```

Dashboard **Save** replaces the repo's whole `tags` section. Older configs that list only `name` / `src` / `async` still work as custom scripts.

## Not included

Tools run in the visitor's browser. Unlike Cloudflare Zaraz, vendor requests are not proxied server-side through the Edge.

## Related sections

- **Snippets**: inline HTML inject
