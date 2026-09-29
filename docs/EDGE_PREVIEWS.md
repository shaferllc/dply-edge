---
title: "Edge previews"
slug: edge-previews
category: "Edge"
order: 120
description: "Manage PR and branch previews on a production Edge site, including promote to production, traffic splitting, protection, and the comment widget."
group: edge
---

# Edge previews

The **Previews** section on a production Edge site is where you manage PR/branch previews: the list, **promote to production**, **split traffic**, preview protection, and the comment widget.

Preview **child** sites (opened from the list) have a smaller sidebar — they do not show this full Previews admin panel.

## Preview list

Active previews for this production site appear in a table with branch, commit, status, and URL.

For each live preview you can:

- **Open** — visit the preview hostname
- **Promote to production** (in the preview's dialog) — copy this preview’s build into production (see below)
- **Split** — send a percentage of **production** traffic to this preview (see below)
- **Tear down** — delete the preview site and its routing (does not merge or close the Git PR)

Org-wide preview inventory also lives under **Edge fleet → Previews**.

## Promote to production

**Promote to production** copies the preview’s **live deployment artifacts** into a fresh production prefix and flips the host map so your **production hostname** serves that build.

- The preview **keeps running** — promote does not delete or consume it
- Production deploy history records the promoted commit/branch
- Requires a **live** preview deploy with artifacts still in storage (redeploy the preview if artifacts were pruned)
- Confirm in the modal before dply runs the copy

Use promote when review is done and you want production on exactly what the preview showed — without waiting for a new build from `main`.

To send only part of production traffic first, use **Split** instead (or before promote).

## Split traffic (canary)

**Split** routes **1–99%** of visitors on your **production URL** to a live preview’s artifacts. The rest stay on the current production deploy.

- Enter a percentage and click **Apply** (or **Update** if a split is already active for that preview)
- Click **Off** to return production to 100% on the current live deploy
- Assignment is **sticky** via cookie so the same visitor consistently hits preview or production
- Only **one** active split per production site at a time
- Takes effect immediately after the host map republishes — no full redeploy required

Split is for canary-style validation on real production traffic. When you are satisfied, **Promote to production** moves 100% to the preview build.

## Production vs preview

| | Production site | Preview child |
|---|-----------------|---------------|
| Branch | Production branch | PR/feature branch |
| Billing | Counts toward live site fee | Free |
| Sidebar | Full workspace | Overview, Deploys, Domains, Build, Logs, Danger |
| Promote / split | Configure here on the parent | Target of promote/split actions |

## Preview protection

On the **parent** site → **Previews** → **Preview protection**:

- **Off** — anyone with a preview or alias URL can view the deploy
- **Shared password** — visitors enter one site-wide password at the edge
- **People signed in to dply** — visitors sign in; optionally restrict to an email allow list

Production URLs and custom domains stay public; protection applies to preview and deploy-alias hostnames only.

## Comment widget

Enable **Preview comment widget** on the parent site under **Previews** to collect feedback tied to preview URLs (see **Preview comments**).

## Inherited settings

Preview children inherit build configuration from the parent. **Deploy triggers** and **Environment** are hidden or reduced on preview workspaces.

## Clean up

Remove unused previews from this list or the fleet tab. Failed preview builds appear under **Failed** on the fleet index.
