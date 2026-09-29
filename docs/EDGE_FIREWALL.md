---
title: "Edge firewall"
slug: edge-firewall
category: "Edge"
order: 118
description: "Allow or block visitors by country at the Edge before your app or origin sees the request."
group: edge
---

# Edge firewall

**Firewall** (geo) allows or blocks visitors by country at the Edge — using the request’s country code — before your pages, forms, or origin see the traffic.

Blocked visitors get a plain **HTTP 403** on the same URL, or your custom blocked-country page from **Error pages**.

Requires **Dply-hosted Edge delivery** for Worker enforcement.

## Modes

| Mode | Behavior |
|------|----------|
| **Everyone** (`off`) | Allow all countries (default) |
| **Only these countries** (`allow`) | Hard allowlist — only listed countries enter; everyone else is 403’d |
| **Everyone except these countries** (`block`) | Deny listed countries; everyone else passes |

**Everyone except these** is usually safer for a geo fence. **Only these countries** can lock out most of the world if the list is incomplete.

The rule dialog refuses to save `allow` or `block` with an empty country list.

## What blocked visitors see

```
HTTP/1.1 403 Forbidden
Forbidden — content is not available in this region (XX).
```

Plain text from Edge (not your build). Custom branded block pages are not available yet.

## How to use it

1. Open **Firewall**. The page opens with a sentence describing the current rule, a clickable rule row, and a row saying whether dply.yaml also sets one.
2. Click the rule, pick a mode, and search/add countries (e.g. `US`, `DE`); remove a chip to drop one.
3. **Save** in the dialog — the rule applies on the next request. Cancel discards edits.

## Tips

- Country comes from Edge geo (ISO 3166-1 alpha-2). VPNs and privacy proxies can look like another country.
- Repo `dply.yaml` can declare countries too; the dashboard shows repo config alongside operator overrides when present.
- Combine with **Rate limits** and **Bot protection** for layered abuse control — geo is coarse, not a CAPTCHA.

## Related sections

- **Rate limits** — per-IP path caps
- **Bot protection** — challenge widgets
- **Routing** — path rules from `dply.yaml` (not country rules)
