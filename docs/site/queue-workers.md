---
title: "Queue workers"
description: "Run always-on or autoscaling php artisan queue:work processes next to a Laravel container app, with worker groups, failed jobs and logs."
---

Queue workers run `php artisan queue:work` for a Laravel [container app](/docs/containers). They are separate instances of your app's own image, so long jobs never slow down web requests and keep running while the web instances sleep. Workers pull jobs from your app's dply Valkey store or its dply Postgres or MySQL database, the same way `queue:work` does on a server.

> [!NOTE]
> Available on every plan, including the trial. Workers do not run without a plan.

If you would rather not run workers at all, a [Queues](/docs/resources/queues) resource pushes each batch of jobs into your app over HTTP and scales to zero with it. Use workers when jobs are long, need steady throughput, or rely on the Redis or database queue drivers.

## Requirements

- The app uses **Container** delivery and is a Laravel app.
- The app has a queue store both it and the workers can reach: [dply Valkey](/docs/resources/valkey), or a dply [Postgres or MySQL database](/docs/resources/databases). SQLite lives inside one container and cannot be shared.

If a requirement is missing, the **Queue workers** sheet says which one.

## Add queue workers

1. Open your app's **Overview** and select **Add resource**.
2. Choose **Queue workers**.
3. Adjust the settings below, then select **Redeploy** in the banner to start them.

The first time, **Processes** is set from the app's memory: one per 96 MB after a 192 MB reserve (1 to 8; 8 on 1 GB).

When workers are running, dply sets `QUEUE_CONNECTION` to the connection they pull from, unless you set it yourself under **Environment**, so the jobs your app dispatches reach the workers.

## Settings

| Setting | Range | Default | What it does |
|---|---|---|---|
| **Instances** | 1–10 (0–10 with autoscaling, labeled **Always on**) | 1 | Worker instances that always run. |
| **Processes** | 1–8 per instance | one per 96 MB of memory (8 on 1 GB) | `queue:work` loops in each instance. Jobs mostly wait on a database or an API, so one instance runs several. |
| **Connection** | **Automatic**, `redis`, `database` | **Automatic** | Automatic uses Redis when the app has dply Valkey it can reach, otherwise its database. |
| **Queues** | comma-separated | `default` | Passed as `--queue`, in priority order, for example `high,default`. |

Under **Worker options**:

| Option | Range | Default | `queue:work` flag |
|---|---|---|---|
| **Timeout (s)** | 1–3600 | 60 | `--timeout` |
| **Tries** | 1–25 | 3 | `--tries` |
| **Sleep when empty (s)** | 1–60 | 3 | `--sleep` |
| **Memory (MB)** | 64–2048 | 128 | `--memory` |
| **Restart after (s)** | 60–86400 | 3600 | `--max-time` |

The sheet shows the exact command your workers run.

Workers use the app's instance size (see [Container apps](/docs/containers#choose-a-size)). There is no separate worker size. Each instance is named `worker-0`, `worker-1`, … and runs your image with `DPLY_ROLE=worker`. On stop, a worker lets its current job finish. A worker that exits right after starting is restarted with a back-off.

> [!NOTE]
> With **Connection** set to `database` on a dply database that can sleep, the workers check it every **Sleep when empty** seconds, so the database never sleeps while they run. The sheet offers **Queue on dply Valkey instead**.

## Autoscaling

Turn on **Autoscale** to add workers when jobs back up:

- **Always on**: instances that always run. `0` lets the workers scale to zero: one starts when jobs arrive and stops five minutes after the queue empties.
- **Up to**: the most instances that can run.
- **Add one at**: jobs waiting per process before another instance starts (1–1000, default 10).
- **Or when a job waits**: also add an instance when the oldest ready job has waited this many seconds (0–3600, default 60; `0` turns it off).

dply reads the backlog about every 10 seconds, straight from the queue store, so checking never wakes your web instances. It scales up at once and scales down after five quiet minutes. While delayed jobs exist, at least one worker stays. A dply store that is asleep holds no new jobs, so it is not woken to be checked.

The cost line under the settings shows the monthly range, from the always-on count to the maximum. Extra instances are billed only while they run.

## Worker groups

A group is another set of workers for other queues, sized and scaled on its own, so a flood on one queue cannot hold up another. Under **Groups**, select **Add a group**, then set its **Queues**, **Instances**, **Processes**, **Autoscale** and **up to**. Its instances are named `worker-{group}-N`. A group shares the main group's connection and **Worker options**.

## Plan limits

Limits apply per app, across the main group and every extra group.

<!-- generated: php artisan dply:billing:price-table limits -->
|  | Starter | Pro | Team |
| --- | --- | --- | --- |
| Sites | Unlimited | Unlimited | Unlimited |
| Concurrent builds | 1 | 2 | 5 |
| Build timeout | 20 min | 45 min | 60 min |
| Custom domains (per organization) | 3 | 20 | 100 |
| Container app instances | 1 per app | Autoscaling | Autoscaling |
| Queue workers per app | 1 | 5, autoscaling | 10, autoscaling |
| SQL databases (D1) | 2 | 10 | 50 |
| Queues | 2 | 10 | 50 |
| Realtime connections per app | 200 | 1,000 | 5,000 |
| Audit log | No | No | Yes |

If your settings ask for more than your plan allows, the sheet says so and only what fits is deployed: extra groups are dropped and instances are trimmed, main group first.

Workers are billed as [container compute](/docs/containers#how-compute-is-billed) on the app's size, less your plan's included usage credit.

## Check, pause and test

In the **Queue workers** sheet:

- **Check workers** shows the jobs waiting on each queue and the failed-job count, and lists every worker with its state (**Running**, **Stopping**, **Stopped**, **Exited**, **Idle (scaled down)**, **Paused**), where it is running and its round trip to the database and Redis.
- **Start stopped workers** starts any worker that should be running and is not.
- **Send test job** queues `php artisan inspire` on the first queue.
- **Pause** stops every worker, letting running jobs finish. Jobs wait on the queue until you **Resume**. Deploys and autoscaling leave paused workers stopped. Pausing also pauses a scheduler running in `worker-0`.
- **Remove** takes the workers away on the next deploy.

## Failed jobs

Select **Failed jobs** in the sheet to read your app's own failed-job store: each job's class, queue, connection, when it failed, attempts, error and stack trace. **Retry** puts a job back on its queue; **Delete** removes it. **Retry all** and **Delete all** act on every failed job.

## Logs

Select **Logs** in the sheet for the last hour of worker output, newest first: each job as it runs and finishes (`RUNNING`, `DONE`, `FAIL`), and each worker starting, restarting and stopping. Lines arrive about a minute after they happen. The **Container** section's **Logs** panel also has a **Queue workers** filter.

## Alerts

Two notification events cover workers. Route them under [Notification channels](/docs/notifications):

- **Queue jobs failing**: jobs logged `FAIL` in the last few minutes.
- **Queue workers keep exiting**: workers exited right after starting three or more times.

Each is sent at most once every 30 minutes per app.

## The scheduler in a worker

With the Laravel scheduler on and at least one always-on worker, the scheduler runs as `php artisan schedule:work` in `worker-0`, so the web instances can sleep. See [Scheduled tasks](/docs/scheduled-tasks).

## Related

- [Container apps](/docs/containers)
- [Queues](/docs/resources/queues)
- [Valkey (Redis)](/docs/resources/valkey)
- [Scheduled tasks](/docs/scheduled-tasks)
