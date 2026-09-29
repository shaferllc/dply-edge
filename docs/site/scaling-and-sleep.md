---
title: "Scaling & sleep"
description: "How container apps add instances under load, sleep when idle, stay warm on a schedule, and what keeps them awake."
---

A container app scales between a minimum and a maximum number of instances. When traffic stops, instances above the minimum go to sleep after an idle timeout and stop costing money; the next request wakes one. This page covers the settings that control that for [container apps](/docs/containers). Static, hybrid and Worker SSR apps run on the edge network and have nothing to scale or sleep.

> [!NOTE]
> Available on every plan, where container apps are available.

## Where the settings live

| Setting | Where |
|---|---|
| **Instances** (maximum) | **Overview** → **App** card. Saves as you change it; applies on the next deploy. |
| **Sleeps after** | **Overview** → **App** card, or **Sleep, scaling, region**. |
| **Always awake** (min instances), **Scaling windows** | **Overview** → **App** card → **Sleep, scaling, region**. |
| **Run jobs on their own instance**, **Keep the jobs instance awake**, **Worker mode** | **Overview** → **App** card → **Sleep, scaling, region**, under **Behaviour**. |

Everything saves as you change it and applies on the next deploy. (These used to be on a separate **Container** page; its old link now opens **Overview**.)

## How instances fill up

Each request goes to the first instance with room. When every running instance is at capacity, the next request starts another, up to your maximum. When the maximum is reached, requests are spread at random across the running instances.

An instance's capacity depends on the stack and size:

| Size | PHP requests at once (php-fpm) | Octane (Swoole, RoadRunner) | Node or Ruby requests at once |
|---|---|---|---|
| 1/16 vCPU | 1 | — | 50 |
| 0.25 vCPU, 1 GB | 12 | 8 | 50 |
| 0.5 vCPU, 4 GB | 16 | 16 | 50 |
| 1 vCPU, 3 GB | 32 | 30 | 50 |
| 2 vCPU, 6 GB | 64 | 62 | 50 |
| 4 vCPU, 12 GB | 128 | 126 | 50 |

A PHP instance runs one request per PHP worker process. The number of workers comes from memory: what is left after 192 MB for nginx, the PHP master and opcache, divided by 56 MB per php-fpm worker (96 MB per Octane worker, which keeps the app booted), at most 32 per vCPU (at least 12) and 128 in all. A request can still use up to 128 MB; if the instance runs out of memory, the next deploy moves it up a size. Inertia SSR takes two workers' memory. A custom size follows the same rule. Open WebSockets count as in-flight requests for as long as they are open.

**Keep a visitor on the same instance** (in **Sleep, scaling, region**, on by default) sets a `dply_instance` cookie so a visitor's session and sockets stay on one container. Turn it off to spread every request at random.

## Sleep

An instance that has had no request for the **Sleeps after** time goes to sleep and is not billed while asleep. Choose `5m`, `10m`, `30m`, `1h`, `6h` or `24h`. New apps default to `5m`.

A longer timeout means fewer cold starts and more billed time. The **Cost estimate** on the **App** card shows what your size costs at a given number of hours awake each day, and how much sleeping saves.

## Keep instances awake

Set **Always awake** in **Overview** → **App** card → **Sleep, scaling, region** to keep that many instances running at all times (0 to your maximum). Instances below the minimum never sleep, so those visitors never hit a cold start. `0` scales to zero when idle. dply checks every minute and starts any instance below the minimum, including one the platform restarted.

The **Cost estimate** on the **App** card shows what always-awake instances cost.

## Scaling windows

Scaling windows set a different minimum and maximum for certain times, such as business hours or a launch. In **Overview** → **App** card → **Sleep, scaling, region**, select **Add a window**, then set:

- **Days**: `Daily`, `Weekdays`, `Weekends`, a single weekday, or **One date**.
- **From** and **Until**: a window starts and ends on the same day. For an overnight window, add two.
- **Time zone**: any IANA time zone, such as `America/New_York`.
- **Always awake** and **Instances**: 0–20 and 1–20.

When windows overlap, a single date wins over a weekday, which wins over weekdays or weekends, which win over daily. Outside every window the **Always awake** count and the app's **Instances** apply. A deploy reserves capacity for the largest maximum any window can ask for.

## What keeps an app awake

An instance stays awake, and billed, while any of these is true:

- It has received a request within the sleep timeout.
- It holds an open WebSocket. The socket counts as activity on every message, so one idle browser tab keeps the instance awake.
- It is below **Min instances**, in the current scaling window or by default.
- It is the jobs instance with **Keep the jobs instance awake** on.

These wake an app and then let it sleep again after the timeout:

- A queue batch from a [Queues](/docs/resources/queues) resource. With **Run queued jobs and scheduled tasks on their own instance** on, the batch goes to a separate jobs instance (one extra instance on the bill while it runs) instead of a web instance.
- The Laravel scheduler, but only when one of your scheduled tasks is due. See [Scheduled tasks](/docs/scheduled-tasks).
- A cron from the **Crons** section.

[Queue workers](/docs/queue-workers) run as their own always-on instances and do not keep the web instances awake.

## When an app is paused

If your organization has no plan, or a trial reaches its spending limit, container traffic stops. Visitors get HTTP `503` with `Retry-After: 3600` and the text "This app is paused." WebSockets already open stay open until they close; new ones are refused. Scheduled tasks do not run, and queue batches are held and retried an hour later. Traffic resumes when you choose a plan. See [Paused accounts](/docs/paused-accounts).

## Related

- [Container apps](/docs/containers)
- [Queue workers](/docs/queue-workers)
- [Usage & metering](/docs/usage)
