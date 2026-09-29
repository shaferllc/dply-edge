---
title: "Edge deploy triggers"
slug: edge-deploy-triggers
category: "Edge"
order: 90
description: "How to start Edge deploys without the dashboard using per-site deploy hook URLs and GitHub auto-deploy webhooks for push and pull-request events."
group: edge
---

# Edge deploy triggers

The **Deploy triggers** section controls **what starts a deploy** without opening the dashboard: inbound webhooks and GitHub push/PR events.

The page opens with a sentence built from the webhook, **Deploy on push** (Build), `EdgePreviewPolicy::for()` and the hooks, then rows: GitHub, deploy on push (links to Build), pull request previews (links to Previews), each deploy hook, the manual webhook and notifications. Build settings still owns the **Deploy on push** checkbox.

## Deploy hooks

**Deploy hooks** are per-site URLs you POST to trigger a redeploy — useful for CMS publish flows (Sanity, Contentful, Strapi, etc.).

1. Choose **Create a deploy hook**, enter a name and choose **Create** (`openNewHook` → `mintEdgeDeployHook`).
2. The same dialog shows the full URL once, with a `curl` command to try it.
3. Configure your external system to POST to that URL on content changes.

Each hook is a row ("“Sanity publish” last fired 2 days ago"); clicking it opens a dialog with **Revoke** (`openHook` / `revokeOpenHook`).

## GitHub auto-deploy

When your repo is on GitHub:

1. Click the GitHub row, pick an account in **Through this GitHub account** (OAuth-connected under Profile → Source control).
2. Choose **Connect** to register push and pull-request webhooks (**Disconnect** removes it).
3. Ensure **Deploy on push** is enabled under **Build** for production branch deploys.

Pull requests get a GitHub Check Run and a summary comment (updated in place on each push) with the preview URL when the deploy lands.

If automatic registration fails, manual webhook instructions appear in this panel.

## Preview workspaces

Deploy hooks are available on production sites. GitHub auto-deploy blocks may be simplified on preview child sites.

## Related sections

- **Build** — **Deploy on push** toggle and production branch
- **Deploys** — history, rollback, manual redeploy
- **Previews** — PR/branch preview list and protection
