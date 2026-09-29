---
title: "Edge deploys"
slug: edge-deploys
category: "Edge"
order: 50
description: "How to ship new Edge versions, view deploy history, deploy a specific ref, roll back, rebuild, and handle hybrid and preview deploys."
group: edge
---

# Edge deploys

The **Deploys** section is where you ship new versions, inspect history, and recover from bad releases.

## Deploy history table

Each row shows:

- **Status** — success, building, failed, rolled back
- **Ref** — commit SHA, branch, or tag deployed
- **Started / finished** timestamps
- **Actions** — view logs, rollback, rebuild (where available)

Building deploys refresh automatically while you stay on this page.

## Redeploy

Click **Deploy** (sidebar) or **Redeploy the latest …** on Deploys to rebuild and publish the **current production branch** at its latest commit. Use this after changing build settings without a new Git push.

## Deploy a specific ref

Use **Deploy a specific commit, branch or tag** (a dialog on Deploys) to deploy a particular:

- **Branch**
- **Commit**
- **Tag**

Browse refs from the connected repository, select one, and confirm deploy. Useful for hotfix tags or testing a branch on production (with care).

## Roll back

For a previous successful deploy, choose **Roll back** to make that artifact live again without re-running the build. Rollback updates edge routing to the selected deployment’s published assets.

## Rebuild

**Rebuild** re-runs the build pipeline for an existing deploy record (same ref). Use when a transient CI failure occurred or build settings were fixed.

## Hybrid sites

Hybrid deploys publish static assets to Edge. SSR requests continue to route to the configured **origin** in **Delivery**. Check origin health if pages render but dynamic routes fail.

## Failed deploys

Open **Build & deploy logs** (or expand a failed row) for stderr/stdout from the build. Fix the repository or build settings, then redeploy.

## Preview deployments

PR/branch previews appear as separate sites in the fleet **Previews** tab. Parent site **Previews** section lists and manages them.
