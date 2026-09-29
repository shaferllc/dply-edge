---
title: "Deploy a Laravel app"
description: "Take a Laravel app from a Git repository to a running container on dply, with a database, cache, queues, scheduler and migrations."
---

This guide deploys a Laravel app end to end: dply detects it from `composer.json`, builds a container image, runs it on the edge, and wires in the resources the app needs. It covers the database, Valkey (Redis), queues, the scheduler, migrations and logs. It assumes a standard Laravel 11 or 12 app in a GitHub, GitLab or Bitbucket repository.

> [!NOTE]
> Laravel apps run as container apps, which are available on every plan, including the trial. See [Plans & pricing](/docs/pricing).

## Before you start

- Commit `composer.lock`. dply installs dependencies with `composer install --no-dev` from it.
- If the app builds front-end assets with Vite, keep the `build` script in `package.json`. dply runs it in a separate Node stage and copies `public/` into the image.
- Pin PHP in `composer.json` if you care which minor you get (see [PHP version](#php-version)).

## Create the app

1. Choose **New app** (or **Deploy an app** on an empty dashboard).
2. **Step 1 of 3 · Connect your source control**: pick a connected GitHub, GitLab or Bitbucket account, or paste a repository under **Or paste a repository**.
3. **Step 2 of 3 · Select a repository**: choose the repository and the branch or tag to deploy.
4. **Step 3 of 3 · Create your application**: dply reads `composer.json`, finds `laravel/framework`, and sets **Mode** to **Container** in the **Deploy summary**. Give the app a name and choose **Deploy**.

If your organization has no plan yet, **Deploy** sends you to Stripe Checkout to start the trial, then back to this page with your choices kept.

The first deploy builds the image, rolls it out, and checks that the app's URL answers with a status below 500. You land on the app's **Overview** while it builds; follow progress under **Deploys**.

## What dply builds

When the repository has no `Dockerfile`, dply generates one (`Dockerfile.dply`) for you:

- **Base image**: an Alpine PHP image with `pdo_pgsql`, `pdo_mysql`, `redis`, `intl`, `zip`, `bcmath`, `pcntl` and `opcache`. Any other `ext-*` your `composer.json` or locked packages require is installed on top.
- **Web server**: nginx in front of PHP-FPM, listening on port 8080. If `laravel/octane` is installed, the app runs `php artisan octane:start` instead: RoadRunner when a `spiral/roadrunner` package is present, otherwise Swoole.
- **Assets**: when `package.json` has a `build` (or `production`) script, a Node stage runs it and the built `public/` directory is copied into the image.
- **Inertia SSR**: when the build produces `bootstrap/ssr`, the image also starts `php artisan inertia:start-ssr`.

If your repository has its own `Dockerfile` at the root, dply uses it instead and routes traffic to the first `EXPOSE` port (8080 when there is none).

> [!IMPORTANT]
> With your own `Dockerfile`, the dply-specific boot steps (migrations on start, queue worker mode, SQLite restore) are not added. Use the generated image unless you need something it cannot do.

### PHP version

dply picks the PHP version from `composer.json`:

1. `config.platform.php`, if set.
2. Otherwise the newest version your `require.php` constraint allows, up to PHP 8.4. An explicit `^8.5` or `8.5.*` gets 8.5.

Available versions are 8.2, 8.3, 8.4 and 8.5.

## Environment variables

On the first deploy dply adds the variables a Laravel app needs to boot, only where you have not set them:

| Variable | Value |
|---|---|
| `APP_KEY` | A generated key |
| `APP_ENV` | `production` |
| `APP_URL`, `ASSET_URL` | The app's live URL |
| `LOG_CHANNEL` | `stderr` |
| `SESSION_DRIVER` | `cookie` |

They are saved on the app, so they stay stable across deploys and you can change them under **Environment**. Add your own variables there too; they reach both the build and the running app. See [Environment variables](/docs/environment-variables).

> [!WARNING]
> `APP_URL` and `ASSET_URL` are saved once, with the address the app had on its first deploy. After you add a custom domain, update both under **Environment** and redeploy, or Laravel keeps generating links to the old address.

Sessions default to the `cookie` driver because containers share no disk. If you move sessions to Redis or the database, set `SESSION_DRIVER` yourself.

## Add a database

Without a database, the app boots on SQLite at `/tmp/database.sqlite`. dply migrates it on every start and, where available, saves the file while the app runs and restores it when the app wakes. A SQLite app is served by one instance.

For anything beyond a prototype, attach a managed database:

1. On the app's **Overview**, choose **Add resource**, then **Database**.
2. Pick **Postgres** or **MySQL** (or **MongoDB**) and a size, and confirm.
3. Redeploy. The next deploy sets:

| Variable | Postgres | MySQL |
|---|---|---|
| `DB_CONNECTION` | `pgsql` | `mysql` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | set | set |
| `DB_SSLMODE` | `require` | — |
| `DATABASE_URL` | `postgresql://…?sslmode=require` | `mysql://…?ssl-mode=REQUIRED` |

MongoDB sets `MONGODB_URI` instead. A value you save under **Environment** always wins over the injected one. Databases are billed by usage; see [Postgres, MySQL & MongoDB](/docs/resources/databases).

### Run migrations

There is no separate release step: containers start fresh each time. To migrate, turn on **Run migrations when a container starts** in **Overview** → **App** card → **Sleep, scaling, region** (also in the database's settings). Each start then runs `php artisan migrate --force --isolated`.

You can also run **Migrate**, **Seed** and roll back from the database's **Settings** without redeploying.

> [!TIP]
> Migrating on start repeats on every wake from sleep and costs a second framework boot. For large apps, leave it off and run **Migrate** from the database settings after a deploy that changes the schema.

## Add Valkey for cache and queues

1. Choose **Add resource**, then **dply Valkey**.
2. Start a new instance or paste the address of an existing Redis.
3. Redeploy.

The next deploy sets `REDIS_URL`, `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME` and `REDIS_PASSWORD`, and for Laravel also `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis` and `REDIS_PERSISTENT=true`. See [Valkey (Redis)](/docs/resources/valkey).

## Run queued jobs

You have two ways to process jobs.

**Queue workers** run `php artisan queue:work` as always-on instances of your app's image, pulling from Valkey or your database:

1. Choose **Add resource**, then **Queue workers**.
2. Set the connection, queue names, instance count and, on Pro and Team, autoscaling.

Starter allows 1 worker instance with no autoscaling; Pro allows 5 instances and 2 extra worker groups; Team allows 10 and 4. Workers never sleep, so they bill compute all month. See [Queue workers](/docs/queue-workers).

**A Queue resource** pushes each job to the app over HTTP instead, so nothing runs while the queue is empty:

1. Choose **Add resource**, then **Queue**.
2. Redeploy. dply sets `DPLY_QUEUE` and `QUEUE_CONNECTION=dply`.

`dispatch()` then sends jobs to the queue and the app runs them as they arrive. See [Queues](/docs/resources/queues).

Both paths rely on the `dply/laravel` package. dply adds it to the generated image when the app uses a database, queue, key-value store, bucket, queue workers or the scheduler, unless you already require it.

## Run the scheduler

In **Overview** → **App** card → **Sleep, scaling, region**, turn on **Run the Laravel scheduler every minute**, then deploy. dply calls `schedule:run` every minute. When queue workers are running, the first worker runs the scheduler instead. To run a single artisan command on its own schedule, add it under **Crons**; **Run now** there runs it once in the live app and shows the output. See [Scheduled tasks](/docs/scheduled-tasks).

## Broadcasting

For Laravel Echo and Reverb, add a Realtime resource. dply runs the WebSocket server; you do not run `reverb:start`. See [Laravel broadcasting with Realtime](/docs/guides/laravel-broadcasting).

## Size, scaling and sleep

On the **App** card on **Overview** you set the size, **Instances**, **Always awake** and **Sleeps after** (5 minutes by default). An idle app sleeps and the next request wakes it, which adds a cold start to that request. Set **Min instances** above 0 to keep one warm. See [Scaling & sleep](/docs/scaling-and-sleep).

## Logs

- Build output: **Deploys**, then the deployment, or **Build & deploy logs**.
- Application logs: **Build & deploy logs**, then **Everything it printed in the last 15 minutes**. Laravel logs to `stderr`, so exceptions appear here.
- Queue worker output: **Worker logs** in the queue workers panel.

## Custom domain

Add the domain under **Routing**, **Domains**, and follow [Domains](/docs/domains). Then update `APP_URL` and `ASSET_URL` as described above.

## Next steps

- [Container apps](/docs/containers)
- [Queue workers](/docs/queue-workers)
- [Troubleshooting builds](/docs/guides/troubleshooting-builds)
- [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime)
