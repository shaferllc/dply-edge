---
title: "Delete an Edge site"
slug: edge-danger
category: "Edge"
order: 160
description: "Explains the Danger zone for permanently deleting an Edge site, what teardown removes, deletion from workspace or fleet, permissions, and billing effects."
group: edge
---

# Delete an Edge site

The **Danger zone** permanently removes an Edge site and all associated infrastructure.

## What deletion does

- Stops live traffic after teardown completes
- Removes deployments and CDN/storage artifacts
- Detaches custom domain routing
- Deletes preview child sites tied to this parent

**This cannot be undone.**

## Delete from workspace

1. Open the site → **Danger zone**.
2. Click **Delete Edge site**.
3. Read the confirmation modal — it names the site and explains teardown scope.
4. Click **Delete Edge site** again to queue the job.

A toast confirms the teardown job was queued. The site may remain visible briefly while jobs run.

## Delete from fleet index

From **Infrastructure → Edge**, use **Delete** on a row to:

- **Delete now**
- **Delete in 30 minutes**
- **Schedule** a future deletion time

Confirm in the modal. Scheduled deletes can be cancelled before they run if your UI exposes cancel (check the fleet row actions).

## Permissions

Only members with permission to delete the site see the delete button. Deployers or read-only roles may not have access.

## Billing after delete

The site stops counting toward the plan once teardown completes and it leaves the active inventory. Past invoices are unchanged.

## Before you delete

- Export anything you need from **Build & deploy logs**
- Note custom DNS records to remove at your DNS provider
- Inform teammates — production and preview URLs will stop working
