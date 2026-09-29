---
title: "Deploy a Rails app"
description: "Take a Rails app from a Git repository to a running Puma container on dply, with a database, cache, jobs and migrations."
---

This guide deploys a Rails app end to end. dply detects Rails from the `Gemfile`, builds a container image that runs Puma, and connects the database and cache you attach. It covers environment, migrations, background jobs and logs.

> [!NOTE]
> Rails apps run as container apps, which are available on every plan, including the trial. See [Plans & pricing](/docs/pricing).

## Before you start

- Commit `Gemfile.lock`. The image runs `bundle install` without the `development` and `test` groups.
- Pin Ruby in `.ruby-version` (for example `3.3.5`). dply uses its major and minor.
- Make sure `config/database.yml` reads `DATABASE_URL` in production, which the Rails default does.

## Create the app

1. Choose **New app**.
2. **Step 1 of 3 · Connect your source control**: pick an account or paste a repository.
3. **Step 2 of 3 · Select a repository**: choose the repository and branch or tag.
4. **Step 3 of 3 · Create your application**: dply finds `gem "rails"` in the `Gemfile` and sets **Mode** to **Container**. Name the app and choose **Deploy**.

After the image is rolled out, dply requests the app's URL and fails the deploy if it answers with a 5xx or does not answer.

## What dply builds

Without a `Dockerfile` in the repository, dply generates one:

- **Ruby**: the minor from `.ruby-version`, or 3.3 when there is none. Available versions are 3.2, 3.3 and 3.4.
- **Native gems**: the image includes a build toolchain and `libpq`, so gems such as `pg` and `nokogiri` compile.
- **Assets**: when `package.json` has a `build` script, a Node stage runs it and copies `public/`. Rails apps then run `rails assets:precompile` with a dummy secret.
- **Server**: `bundle exec puma` on port 8080. Non-Rails Rack apps run `rackup`.
- **Environment baked in**: `RAILS_ENV=production`, `RACK_ENV=production`, `RAILS_LOG_TO_STDOUT=1`, `RAILS_SERVE_STATIC_FILES=1`.

A `Dockerfile` at the repository root replaces the generated image; traffic goes to its first `EXPOSE` port.

> [!WARNING]
> A failing `assets:precompile` does not fail the build. If pages load without styles, open the build log under **Deploys** and look for the precompile output.

## Environment variables

On the first deploy dply saves a generated `SECRET_KEY_BASE` and sets `RAILS_ENV`, `RAILS_LOG_TO_STDOUT` and `RAILS_SERVE_STATIC_FILES`, where you have not set them. Change them and add your own under **Environment**. See [Environment variables](/docs/environment-variables).

If you use encrypted credentials, add `RAILS_MASTER_KEY` under **Environment**.

## Add a database

1. On the app's **Overview**, choose **Add resource**, then **Database**.
2. Pick **Postgres** or **MySQL** and a size.
3. Redeploy. The next deploy sets `DATABASE_URL` (plus `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`). Connections require TLS.

> [!WARNING]
> A Rails app with no database attached uses whatever `config/database.yml` says. If that is SQLite in `storage/`, the file lives inside the container and is lost when the container sleeps or is replaced. Attach a database before you store real data.

### Run migrations

In **Overview** → **App** card → **Sleep, scaling, region**, turn on **Run migrations when a container starts**. Each container start then runs `bundle exec rails db:prepare`, which creates the database if needed and runs pending migrations. You can also run **Migrate** from the database's **Settings**.

## Add Valkey

Choose **Add resource**, then **dply Valkey**, and redeploy. The app receives `REDIS_URL` and the matching `REDIS_HOST`, `REDIS_PORT`, `REDIS_USERNAME` and `REDIS_PASSWORD`. Point `config.cache_store` or Sidekiq at `REDIS_URL`. See [Valkey (Redis)](/docs/resources/valkey).

## Background jobs

Rails apps can run jobs with a Queue resource and the `dply-rails` gem:

1. Add the gem and set the adapter:

   ```ruby
   # Gemfile
   gem "dply-rails"
   ```

   ```ruby
   # config/environments/production.rb
   config.active_job.queue_adapter = :dply
   ```

2. Choose **Add resource**, then **Queue**, and redeploy.

`perform_later` sends each job to the queue, and dply delivers it back to the app, which runs it. Nothing runs while the queue is empty. See [Queues](/docs/resources/queues).

> [!NOTE]
> **Queue workers** (always-on `queue:work` instances) are built for Laravel. For Sidekiq or Solid Queue, run the worker from your own `Dockerfile` in a separate app, or use the Queue resource above.

## Scheduled work

Add cron schedules on **Overview** → **Add resource** → **Scheduled task**; each runs its handler as a rake task. **Run now** runs a listed task once in the live app (rake tasks print no output). A container app can have up to 50. See [Scheduled tasks](/docs/scheduled-tasks).

## Logs

- Build and image output: **Deploys**, or **Build & deploy logs**.
- Puma and Rails logs: **Build & deploy logs**, then **Everything it printed in the last 15 minutes**.

## Next steps

- [Container apps](/docs/containers)
- [Scaling & sleep](/docs/scaling-and-sleep)
- [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime)
