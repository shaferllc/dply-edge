---
title: "Container apps"
description: "Run Laravel, Symfony, Rails and Node servers as containers behind your app's hostname, billed per second while they run."
---

A container app runs your server process (PHP, Ruby or Node) in a container on Dply Edge, behind the same hostname, firewall and routing as any other app. Use **Container** delivery when your app needs a long-running server: a Laravel or Symfony app, a Rails app, or a Node HTTP server such as Express, Nest, Fastify or Koa. Containers sleep when idle and you pay only for the seconds they run.

> [!NOTE]
> Container apps are available on every plan, including the trial. The trial has a $5 spending limit; past it, container traffic stops until you choose a plan or select **End trial now** on the billing page.

## Create a container app

When you create an app, dply detects the repository's runtime. PHP and Ruby repositories get **Container** delivery preselected; a Node repository can also choose it.

During create you pick a starting plan for the container. Each plan fills in the settings below, and you can change any of them later.

| Plan | Size | Instances | Sleeps after | Scheduler | Migrations on boot |
|---|---|---|---|---|---|
| Flex (default) | 0.25 vCPU, 1 GiB | 1 | 5 minutes | Off | Off |
| Small | 0.5 vCPU, 4 GiB | 1 | 30 minutes | On | Off |
| Medium | 1 vCPU, 6 GiB | 2 | 1 hour | On | On |

## How a deploy builds your image

On every deploy dply clones the repository and uses your `Dockerfile` if there is one. Without one, it generates `Dockerfile.dply` for the detected stack:

| Stack | What the generated image does |
|---|---|
| PHP (Laravel, Symfony) | Picks the newest PHP (8.2–8.5) your `composer.json` allows, installs the extensions your dependencies require, runs `composer install`, and builds frontend assets when `package.json` has a `build` script. Serves with php-fpm behind nginx. If `laravel/octane` is installed it runs Octane instead (RoadRunner when `spiral/roadrunner` is present, otherwise Swoole). |
| Rails | Ruby slim image, `bundle install`, `assets:precompile`, then Puma. |
| Node | Installs with your lockfile's package manager, runs `npm run build` when present, then `npm start` (or the pnpm/yarn equivalent). |

Generated images listen on port `8080`. A Node server must listen on `process.env.PORT`. With your own `Dockerfile`, dply sends traffic to the first `EXPOSE` port.

For a Laravel app, dply adds the `dply/laravel` package to the image when the app uses the scheduler, a database, queue workers or an attached resource that needs it. If you ship your own `Dockerfile`, add it yourself:

```bash
composer require dply/laravel
```

## Environment and first-deploy defaults

Every production variable under **Environment** is passed into the container's process environment before the app starts. Read them with your framework's normal environment API.

On the first deploy dply fills in what the framework needs to boot, only where you have not set a value, and saves them under **Environment** so you can change them:

| Stack | Defaults |
|---|---|
| Laravel | A generated `APP_KEY`, `APP_ENV=production`, `APP_URL` and `ASSET_URL` set to the app's URL, `LOG_CHANNEL=stderr`, `SESSION_DRIVER=cookie` |
| Rails | A generated `SECRET_KEY_BASE`, `RAILS_ENV=production`, `RAILS_LOG_TO_STDOUT=1`, `RAILS_SERVE_STATIC_FILES=1` |
| Node | `NODE_ENV=production` |

> [!WARNING]
> A Laravel app with no database of its own boots on SQLite at `/tmp/database.sqlite`. Container disk is wiped whenever an instance starts, and instances do not share a disk, so anything written there can disappear. Attach a database from **Add resource** before you store real data. See [Postgres, MySQL & MongoDB](/docs/resources/databases).

Use a database or Valkey store for cache and sessions, and object storage for uploads.

## Choose a size

Open your app's **Overview**, select the **App** card, then choose a **Size**. The change applies on the next deploy.

<!-- generated: php artisan dply:billing:price-table sizes --product=app -->
| Size | Memory | Per second awake | Per hour awake |
| --- | --- | --- | --- |
| 0.25 vCPU | 1 GB | $0.0000101 | $0.0364 |
| 0.5 vCPU | 4 GB | $0.0000267 | $0.0962 |
| 1 vCPU | 6 GB | $0.0000466 | $0.168 |
| 2 vCPU | 8 GB | $0.0000795 | $0.286 |
| 4 vCPU | 12 GB | $0.000145 | $0.521 |

Below the ladder, **1/16 vCPU** (256 MB, non-PHP apps only) is $0.00000242 per second awake ($0.0087 an hour).

Prices are per instance with every vCPU busy the whole time. You pay less in practice: CPU is billed only while it works, and a sleeping instance costs nothing. The **Container** section lists the same sizes by their key (`basic` is 0.25 vCPU, `standard-1` 0.5, `standard-2` 1, `standard-3` 2, `standard-4` 4). **1/16 vCPU** is available only to non-PHP apps.

> [!IMPORTANT]
> PHP apps need at least **0.25 vCPU** (1 GiB). On **1/16 vCPU** a PHP process runs out of memory and every request fails. Octane apps (Swoole, RoadRunner) need at least **0.5 vCPU**. dply does not stop you from picking a smaller size.

### Custom size

Choose **Custom** on the **App** card to set vCPU, memory and disk yourself, then **Save custom size**:

- vCPU: 1 to 4.
- Memory: at least 3 GiB per vCPU, at most 12 GiB.
- Disk: at most 2 GB per GiB of memory, and at most 20 GB.

For example, 1 vCPU with 3 GiB and 6 GB of disk costs up to $0.121 an hour ($88.05 a month always on).

### Right-sizing suggestions and memory crashes

dply samples each awake app's peak memory every hour. After six samples in a week on the same size, the **App** card suggests the smallest size that leaves 30% headroom over the highest peak, and shows how much it saves per hour. It never suggests a larger size.

When a deploy's health check or logs show the process was killed for memory, dply raises the app one size and redeploys. It does this at most once per size, and never for a custom size.

> [!WARNING]
> An automatic size increase raises the app's hourly cost. You are notified that the app was unhealthy after deploy; check the size on the **App** card afterwards.

## Instances

**Instances** on the **App** card is the most instances that run at once (1 to 20; the card offers 1, 2, 3, 4, 5, 8, 10 and 20). The first start runs only this many. A gradual deploy can briefly run one extra, so the new version starts before the old one stops.

Requests are spread across instances as they fill up, and a cookie keeps a visitor on the same instance. See [Scaling & sleep](/docs/scaling-and-sleep) for how many requests an instance takes, minimum instances, scaling windows and sleep.

## Regions and jurisdictions

Open **Overview**, select the **App** card, then **Sleep, region, scheduler**:

- **Run only in**: **Anywhere (fastest)**, **EU only** or **US FedRAMP only**.
- **Regions**: tick one or more placement regions (ENAM, WNAM, EEUR, WEUR, APAC, SAM, ME, OC, AFR). EU only allows EEUR and WEUR; US FedRAMP only allows ENAM and WNAM. Leave all unticked to use every region inside the choice above.

If your app uses a dply database or dply Valkey and you leave regions unticked, it runs in the region next to that data. See [Data regions](/docs/data-regions).

## Rollouts

A deploy replaces running instances according to the rollout settings in **Sleep, region, scheduler**:

| Mode | What happens |
|---|---|
| **Gradual** (default) | Instances move to the new image in steps. One extra instance is reserved so the new image starts before an old one stops. |
| **Immediate** | Every instance moves to the new image in one step. |
| **None** | The deploy updates the routing Worker only. Running instances keep the current image until you pick Gradual or Immediate. |

- **Steps**: comma-separated percentages of instances on the new image, increasing, ending at `100`, at most 10 steps. Leave blank for the default: `100` with one instance, otherwise `10, 100`.
- **Wait before replacing (seconds)**: 0 to 3600. `0` replaces an instance as soon as the rollout reaches it; a higher number leaves it alone until it has been running that long.

A replaced instance is asked to stop and has 15 minutes to exit. You cannot start a new deploy while a rollout is still in progress.

After each deploy dply requests the app's URL. If the app does not answer, or answers with an error that looks like a platform failure, the deploy is marked failed and you are notified. An HTTP 500 from your own code is treated as your app's response.

## Migrations on boot

Turn on **Run migrations when a container starts** in **Sleep, region, scheduler** to run migrations as each container starts:

- Laravel: `php artisan migrate --force --isolated`
- Rails: `bundle exec rails db:prepare`

It is off by default. Migrations run on every start, including every wake from sleep, so they add a second framework boot to each cold start. It applies to generated Dockerfiles only. The equivalent environment variable is `DPLY_MIGRATE_ON_BOOT=1`.

## Cold starts

When a request reaches a sleeping app, dply starts an instance and waits up to 45 seconds for its port to open. If the container is not running or crashes before answering, the request is retried up to two more times. Requests with a body over 1 MB are streamed and not retried. Expect a cold start to take a few seconds; keep instances awake with **Min instances** if that matters (see [Scaling & sleep](/docs/scaling-and-sleep)).

## WebSockets

An app that serves its own WebSockets (Reverb, Action Cable, socket.io) works on the same hostname. The upgrade headers reach your app unchanged, and the `101` response passes through without header rules or caching.

- A socket goes to the instance the visitor's page load was sent to.
- Each open socket counts as one in-flight request against the instance's capacity.
- An instance with any open socket never sleeps, so one idle browser tab keeps it (and its billing) running. Close idle sockets if scale-to-zero matters.
- A rollout replaces instances, so clients should reconnect after a deploy.

For a managed relay instead of your own socket server, see [Realtime (WebSockets)](/docs/resources/realtime).

## Logs

The **Container** section's **Logs** panel shows the last 15 minutes of stdout and stderr from your app, its queue workers and the routing Worker. Select **Load last 15 minutes**, then filter by source. Log lines appear after the deploy that enables them.

## How compute is billed

Container compute is metered per second: vCPU while it works, memory and disk while an instance runs, plus container egress per GB. Usage is collected hourly and appears as **Apps and workers (compute)** on the billing page, for web instances, queue workers and preview containers alike. It bills at the rates above, less your plan's included usage credit. See [Plans & pricing](/docs/pricing).

The **App** card's **Cost estimate** shows the running rate per second, minute, hour and day for your size and instance count, and what a given number of **Hours awake each day** costs. It is an estimate that assumes every vCPU is busy.

**Previews** of a container app run their own container and are billed the same way. They never run the app's crons or consume its queues.

## Next steps

- [Scaling & sleep](/docs/scaling-and-sleep)
- [Queue workers](/docs/queue-workers)
- [Scheduled tasks](/docs/scheduled-tasks)
- [Deploy a Laravel app](/docs/guides/laravel)
