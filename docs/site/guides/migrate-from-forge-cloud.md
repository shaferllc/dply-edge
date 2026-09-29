---
title: "Migrate from Forge or Laravel Cloud"
description: "Move a Laravel app from a Forge server or Laravel Cloud to dply: map each piece, move the database, and switch the domain."
---

A Laravel app on Forge runs on a server you manage; on Laravel Cloud it runs on managed compute. On dply it runs as a container on Cloudflare's edge that sleeps when idle, with managed databases, Valkey, queues and WebSockets attached as resources. This guide maps what you have today to dply, then walks through the move.

## How the pieces map

| Forge | Laravel Cloud | dply |
|---|---|---|
| Server + site | App cluster | A container app ([Deploy a Laravel app](/docs/guides/laravel)) |
| Deploy script | Build and deploy commands | Generated image; your own `Dockerfile` if needed |
| `php artisan migrate` in the deploy script | Deploy command | **Run migrations when a container starts**, or **Migrate** in the database settings |
| Daemons running `queue:work` / Horizon | Queue clusters | **Queue workers** ([Queue workers](/docs/queue-workers)) |
| Scheduler cron | Scheduler toggle | **Run the Laravel scheduler every minute** |
| MySQL / Postgres on the server | Cloud databases | **Database** resource: Postgres, MySQL or MongoDB |
| Redis on the server | Key-value store (Valkey) | **dply Valkey** resource |
| Reverb daemon | Reverb / WebSockets | **Realtime** resource ([Laravel broadcasting with Realtime](/docs/guides/laravel-broadcasting)) |
| S3 bucket | Object storage | **Object storage** resource |
| `.env` file | Environment variables | **Environment** tab |
| Server size | Compute size, hibernation | **Instance size**, **Sleep after idle**, **Min instances** |

## Differences to plan for

- **No persistent disk.** Containers start fresh. Files written to `storage/app` are lost on restart; move uploads to object storage. Sessions default to the `cookie` driver.
- **No SSH.** You cannot shell into a running container. Use the container logs and the database tools (**Migrate**, **Seed**, **Connect**) instead.
- **No release step.** There is no deploy script. Migrations run on container start when you turn that on, or on demand.
- **Sleep.** An idle app sleeps after 5 minutes by default and the next request waits for a cold start. Set **Min instances** to 1 to keep one warm.
- **Horizon** is detected but not run as a process. Use **Queue workers**, which run `queue:work`, against the same Redis.
- **Octane** is supported: with `laravel/octane` installed the image runs Swoole, or RoadRunner when a `spiral/roadrunner` package is present.

## 1. Deploy the app

1. Choose **New app**, connect your source control, select the repository and branch.
2. Check that the **Deploy summary** shows **Container**, then choose **Deploy**.

The first deploy boots on SQLite so you can see the app answer before attaching real data.

## 2. Copy your environment

Open **Environment** and add the variables from your Forge `.env` or Cloud environment. Leave out values dply sets from resources (`DB_*`, `DATABASE_URL`, `REDIS_*`, `REVERB_*`, `PUSHER_*`): they come from the resources you attach next. Keep your own `APP_KEY` so encrypted data and signed cookies stay valid.

See [Environment variables](/docs/environment-variables).

## 3. Attach resources

On the app's **Overview**, choose **Add resource** and add, as needed:

- **Database**: Postgres, MySQL or MongoDB.
- **dply Valkey**: cache, sessions and queues.
- **Queue workers**: set the connection and queue names you used on Forge or Cloud.
- **Realtime**: if the app broadcasts.
- **Object storage**: for uploads.

Then redeploy so the app receives the new variables.

## 4. Move the database

1. Put the old app into maintenance mode, or stop writes, so no data changes after the export.
2. Export it:

   ```bash
   pg_dump --no-owner --no-acl -Fc "$OLD_DATABASE_URL" > app.dump
   ```

   For MySQL, use `mysqldump --single-transaction`.

3. Open the database from the app's **Overview**. Under **Export and import**, choose **Import your own dump**, then **Get upload command**. Run the command where the file is (the link works for an hour), then load the uploaded file. Tables in the file are replaced; others are left alone.

For small databases you can connect with the **Connection URL** under **Connect** and restore with `pg_restore` or `mysql` directly.

See [Postgres, MySQL & MongoDB](/docs/resources/databases).

## 5. Test on the dply address

Open the app on its dply address and check logins, queued jobs, scheduled tasks and uploads. Application logs are on **Build & deploy logs** under **What your app is printing**.

## 6. Switch the domain

1. Add the domain under **Routing**, **Domains**.
2. Lower the TTL on the existing DNS record, then point it at the CNAME target dply shows.
3. Choose **Verify DNS** and wait for TLS to be active.
4. Update `APP_URL` and `ASSET_URL` under **Environment** to the domain, and redeploy.
5. Shut down the old server or Cloud environment once traffic has moved.

See [Domains](/docs/domains).

## Next steps

- [Container apps](/docs/containers)
- [Scaling & sleep](/docs/scaling-and-sleep)
- [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime)
