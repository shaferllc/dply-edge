---
title: "Logs"
description: "Read build and deploy output, request logs, container logs, and queue worker logs for an app."
---

dply keeps several kinds of logs for each app. Build and deploy logs tell you why a deploy failed. Request logs show what visitors asked for and how your app answered. Container and queue worker logs show what your own code printed while it ran. This page covers where each one lives and how long it is kept.

| Log | What it contains | Where to find it |
|-----|------------------|------------------|
| Build & deploy | Clone, install, build, and publish output for each deploy | **Build & deploy logs** |
| Requests | One row per visitor request: method, path, status, timing, cache, country | **Traffic & analytics** → **Live requests**, `dply logs`, the API |
| Container | `stdout` and `stderr` from a container app, its queue workers and the router in front | **Build & deploy logs** → **What your app is printing** |
| Queue workers | Job and worker lifecycle output | **Overview** → **Queue workers** → **Logs** |

## Build & deploy logs

In your app, open **Build & deploy logs** in the **Ship** group of the sidebar. The page opens with one sentence about your latest deploys, for example "The last deploy, 3f2a91c, built in 1m 42s and went live 2 hours ago." It says so when that deploy or the one before it failed. For container apps, it also counts the errors your app logged in the last 15 minutes.

Under **Recent deploys**, each deploy is a row, such as "3f2a91c is live · Fix header spacing" or "8bd04e2 failed", with the first line of the failure reason. Click a row to open its build log in a dialog. **See every deploy** opens the **Deploys** page.

A log usually covers:

- Cloning the repository
- Installing dependencies (for example `npm ci` or `pnpm install`)
- Your build command
- Publishing the output to the edge

In the dialog, type in **Find in log** to show only matching lines. **Download** saves the log as a text file. While a deploy is building, the page and the open dialog refresh every five seconds so you can watch it progress.

### When a build fails

A failed deploy's dialog shows its failure reason above the log, and **Jump to error** scrolls to the first failing line. If the failure came from checking your `dply.yaml`, dply points at the problem in the file.

1. Read the error in the log.
2. Fix the cause in your repository, in **Environment**, or in **Build** (for example the build command or output directory).
3. Deploy again from **Deploys**.

See [Troubleshooting builds](/docs/guides/troubleshooting-builds) for common failures.

### What your app is printing

Container apps also show **What your app is printing** on this page. One row counts the errors in the last 15 minutes and quotes the most recent one. Another opens everything the app, its queue workers and the router in front printed in that time. In the dialog, switch between **Everything** and **Errors**, filter by source (**App**, **Queue workers** or **Routing**) and by worker, search, or choose **Refresh**.

> [!NOTE]
> Build logs show build output only. Requests visitors made are in request logs, under **Traffic & analytics**.

## Request logs

Request logs record each request that reaches your app's hostnames: time, method, path, status code, response time, bytes served, cache status, and visitor country.

To read them:

- **Dashboard**: open **Traffic & analytics** and scroll to **Live requests**. Filter by method, status, or path, and choose **CSV** to download the log. See [Traffic & analytics](/docs/traffic).
- **CLI**: run `dply logs` in a linked project to follow requests in your terminal. See [CLI](/docs/cli).
- **API**: `GET /api/v1/edge/sites/{site}/logs` returns requests newer than a `since` timestamp, up to 500 per call. See [API reference](/docs/api/reference).

> [!WARNING]
> Request logs are kept for a short window and are not an archive. Download the CSV if you need to keep a record of an incident.

## Container logs

For a container app, open **Build & deploy logs** and use the rows under **What your app is printing** (see above).

Your container must write to `stdout` or `stderr`. Logs appear after the deploy that enables them. See [Container apps](/docs/containers).

## Queue worker logs

For an app with queue workers, open **Overview**, select **Queue workers**, and choose **Logs**. The panel shows the last hour of worker output, newest first: each job as it runs and finishes, and each worker starting, restarting, and stopping. Output reaches the panel about a minute after it happens. Choose **Refresh** to reload.

See [Queue workers](/docs/queue-workers).

## Logs and the activity log

Logs describe your app's behavior. The record of who changed what in dply (settings, domains, members, and so on) is separate. See [Activity log](/docs/activity-log).

## Related

- [Traffic & analytics](/docs/traffic)
- [Deployments](/docs/deployments)
- [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime)
- [Alerts](/docs/alerts)
