---
title: "Edge site members"
slug: edge-members
category: "Edge"
order: 120
description: "Grant site-scoped roles on an Edge site without making someone an org admin."
group: edge
---

# Edge site members

**Members** grants people access to **this Edge site** with a site-scoped role. Org owners and admins already have full access and do not need to be listed here.

## What the page shows

The page opens with a sentence counting who can reach this site and what they can do. Below it, **everyone in the organization** is listed as a sentence ("Mia can configure and deploy") with the reason: their org role, or their app role on this site. This is the same resolution as `SitePolicy`:

| Who | Can do on this site |
|---|---|
| Org owner / admin | Everything (not editable here) |
| App **Admin** | Configure, deploy, manage members |
| App **Deployer** | Deploy (not settings or environment variables) |
| App **Viewer** | Look only |
| Org member, no app role | Configure and deploy |
| Org deployer, no app role | Deploy |
| Org viewer | Look only (can't get an app role) |

## How to use it

1. **Give someone access** — pick an org member or deployer, choose Viewer, Deployer or Admin, **Save**.
2. Click a member or deployer to change their app role, or choose **Their org role** / **Remove app role** to drop it.

Only users who can **manage members** (org owners/admins and app Admins) can make changes. Org-level roles are never changed here.

## Tips

- Prefer site members over sharing org-admin for contractors who only touch one Edge app.
- App Deployers can deploy but not change settings, environment variables or notification subscriptions.

## Related sections

- Organization **Members** — org-wide roles
- **Danger zone** — teardown and ownership-sensitive actions
