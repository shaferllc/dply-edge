---
title: "Container apps (PHP, Rails, Node)"
slug: edge-containers
category: "Edge"
order: 118
description: "Run Laravel, Symfony, Rails and Node servers (Express, Nest, Fastify) on Cloudflare Containers behind your Edge site, with queues, per-second billing and an external database."
group: edge
---

# Container apps

Edge runs **PHP (Laravel, Symfony), Ruby (Rails, Sinatra) and Node HTTP servers
(Express, Nest, Fastify, Koa)** as
[Cloudflare Containers](https://developers.cloudflare.com/containers/). Pick
**Container** as the delivery mode when you create the site — dply preselects it
for PHP and Ruby repositories. Available on **Pro and Team**.

## How it runs

Every deploy:

1. Clones the repo and uses its `Dockerfile`, or generates `Dockerfile.dply`:
   - **PHP** — FrankenPHP, Composer install, Vite/npm assets when `package.json` exists.
   - **Rails** — Ruby slim image, `bundle install`, `assets:precompile`, Puma.
   - **Node** — your lockfile's installer, `npm run build` if present, `npm start`.
     Your server must listen on `process.env.PORT` (8080).
2. Builds the image and deploys it with a small Worker in front
   (`wrangler deploy`), rolled out immediately.

Requests to your hostname go through the Edge Worker to that Worker, which
starts a container (or reuses a warm one) and forwards the request. Containers
sleep after 10 idle minutes; the next request cold-starts one in a few seconds.

Generated images listen on **8080**. With your own Dockerfile, dply uses the
first `EXPOSE` port.

## Database

Containers have an ephemeral disk, so use a hosted database. Set it in
**Environment**:

- Laravel: `DB_URL=postgres://user:pass@host:5432/db` (or `DB_CONNECTION` + `DB_*`)
- Rails: `DATABASE_URL=postgres://…`

Migrations run on boot (`php artisan migrate --force --isolated` /
`rails db:prepare`). Set `DPLY_MIGRATE_ON_BOOT=0` to turn that off.

Use a Redis or database store for cache and sessions, and R2 (S3-compatible)
for uploads.

## Queues

Create a queue binding under **Jobs** (default `JOBS`), then install the driver:

| Stack | Install | Configure |
|---|---|---|
| Laravel | `composer require dply/laravel` | `QUEUE_CONNECTION=dply` |
| Rails | `gem "dply-rails"` | `config.active_job.queue_adapter = :dply` |

Jobs are sent to Cloudflare Queues through your site's Worker. The Worker
consumes each batch and POSTs it to `/_dply/queue` in your app, so there is
no worker process to run. Jobs that throw are retried. `DPLY_APP_URL` and
`DPLY_QUEUE_TOKEN` are injected for you.

## Scheduled tasks

- **Laravel scheduler:** turn on *Run the Laravel scheduler every minute* under
  **Container**. A Cron Trigger calls `schedule:run` through `dply/laravel`.
- **Anything else:** add a cron under **Crons**. The handler is an artisan
  command (Laravel, e.g. `reports:send --daily`) or a rake task (Rails, e.g.
  `reports:daily`).

Cloudflare allows 5 schedules per site.

## Environment

Every production variable from **Environment** is passed to the container as a
Worker secret, then copied into the container process environment before the
app starts. The app reads the name with its normal environment API. A container
cannot fetch these values over HTTP.

On the first deploy, dply fills in what the framework needs to boot, but only
where you haven't set a value. The values are saved under **Environment**, so
you can change them:

- **Laravel**: a generated `APP_KEY`, `APP_ENV=production`, `APP_DEBUG=false`,
  `APP_URL`, `LOG_CHANNEL=stderr`, `SESSION_DRIVER=cookie`. There is no shared
  disk between containers.
- **Rails**: a generated `SECRET_KEY_BASE`, `RAILS_ENV=production`, logs to
  stdout, and static files served.
- **Node**: `NODE_ENV=production`.

## Logs

The Container tab's **Logs** section shows the last 15 minutes of stdout/stderr
from your app and its Worker, read from Cloudflare Workers Logs (enabled for
every container deploy).

## Billing

Container sites count toward your plan's sites like any other site. Compute is
billed **per second** of vCPU, memory and disk the container runs, plus egress,
at Cloudflare's list price plus the usage markup. Containers sleep when idle,
and the meter stops when they do.

| Plan | Compute included each month |
|---|---|
| Pro | $5 |
| Team | $20 |

Usage is collected hourly from Cloudflare (`dply:edge:collect-container-usage`)
and appears as **Container compute** on the billing page.

**Previews** of a container site run their own container, so their compute is
billed the same way. They never consume the site's queues or run its crons,
and promoting one rebuilds its commit on production.

## Limits

- Container instance type and max instances are platform defaults
  (`basic`: ¼ vCPU, 1 GiB; up to 5 instances).
- Container disk is wiped on every start.
- Job payloads up to 128 KB; delays up to 12 hours.
