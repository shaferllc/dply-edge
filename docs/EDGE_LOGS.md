---
title: "Edge build & deploy logs"
slug: edge-logs
category: "Edge"
order: 150
description: "Shows CI output from clone-and-build jobs with live polling, failure diagnosis, and retention guidance, distinct from visitor HTTP logs."
group: edge
---

# Edge build & deploy logs

**Build & deploy logs** shows CI output from clone-and-build jobs — not visitor HTTP logs. For the live request tail, open **Traffic & analytics**.

## Layout

The page opens with a sentence about the latest deploy (and the one before it when it failed). Container apps also get the count of errors their app logged in the last 15 minutes, loaded after the page paints via `wire:init="loadAppLogs"` (`EdgeContainerDeployer::appLogLines`, shared with the Container tab).

- **What your app is printing** (containers only): an errors row and an everything row, each opening the `app-logs` dialog (Everything / Errors, find, Refresh).
- **Recent deploys**: one row per deploy (last 10). A row opens the `deploy-log` dialog: failure reason and lint callout, find, **Jump to error**, **Download** (client-side), with a 5-second poll while the deploy is building.

## Log content

Typical log sections:

- Repository clone
- Dependency install (`npm ci`, `pnpm install`, etc.)
- **Build command** output
- Publish/upload to edge storage
- Failure reason summary when the job exits non-zero

## Live polling

While a deploy status is **building**, logs refresh automatically so you can watch progress without reloading.

## Failed builds

When a deploy fails:

1. Scroll to the end of the log for the error message.
2. Fix the repository, **Environment** vars, or **Build** settings (command/output dir).
3. **Redeploy** from the Deploys section.

Common failures: wrong output directory, missing build script, Node version mismatch.

## Hybrid publish

Hybrid deploys log static asset publish separately from origin health. Origin errors appear at runtime, not always in the static build log.

## Build vs visitor logs

| Log type | Where |
|----------|--------|
| Build & deploy | This section |
| CDN / HTTP access sample | Traffic & analytics |
| Origin application logs | Linked Cloud app or external origin |

## Retention

Log retention follows platform policy. Download or copy important failure excerpts before retrying if you need a permanent record.
