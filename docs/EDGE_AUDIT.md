---
title: "Edge audit log"
slug: edge-audit
category: "Edge"
order: 121
description: "Who changed what on this Edge site — settings, bindings, firewall, and more."
group: edge
---

# Edge audit log

**Audit log** lists control-plane changes for this Edge site: who changed settings, when, and what moved (firewall, bindings, alerts, members, and similar).

Entries are written when operators save workspace actions that call `audit_log` for the site.

## How to use it

1. Open **Audit log** in the site sidebar. The summary line reads "N changes in the last 7 days, all by <name>" (or "by N people"), then the most recent change and how long ago it was.
2. Scan the last 100 events, grouped by day (**Today**, **Yesterday**, then the date, in the org's time zone). Each event is one sentence, worded as on the org **Activity** page.
3. Click an event to open its details: raw action code, who (name and email), when (full date, relative, ISO), IP, a **What changed** before/after table, and the full recorded before/after values.
4. Use **Download CSV** / **Download JSON** at the bottom for the full retained history (Team plan).

The page is read-only.

## Tips

- This is the **dply control plane** audit trail, not Cloudflare’s account audit log.
- Deploy history itself lives under **Deploys** / **Build & deploy logs**.

## Related sections

- **Deploys** — release history
- **Members** — who can change the site
- **Danger zone** — teardown and irreversible actions
