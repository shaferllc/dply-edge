---
title: "Local development"
description: "Run your app on your machine, keep its configuration in step with dply, and use the dply CLI to deploy, preview and inspect it from your terminal."
---

dply doesn't replace your local tooling. You develop with your framework's own dev server, then use the dply CLI to deploy, open previews, manage environment variables and read logs without leaving the terminal. This page covers that workflow and how to reproduce your app's resources locally.

## Run your app locally

Use the command your framework already provides, for example `npm run dev`, `php artisan serve` or `bin/rails server`. dply builds from your repository with the same build command and output directory you see in **Build**, so a build that works locally with those settings usually works on dply.

To check the production build before you push, run the build command yourself and look at the output directory:

```bash
npm run build
ls dist
```

## Install the CLI

The CLI needs Node.js 18 or later and npm. Install it and sign in with one command:

```bash
curl -fsSL https://dply.io/cli/install.sh | bash -s -- --login
```

`--login` opens your browser when the install finishes. Confirm the code shown in your terminal, choose your organization and the permissions to grant, and choose **Approve**.

Check that it worked:

```bash
dply whoami
dply sites
```

To update the CLI later, run `dply update`. You can see and revoke CLI sessions under **Profile**, then **CLI**. See [CLI](/docs/cli) for every command.

## Link a folder to an app

Create the app in the dashboard first; the CLI can't create apps. Then, from your repository:

```bash
dply link
```

`dply link` shows a picker and writes `.dply/site.json`. Commands you run in that folder then act on that app. To target a different app for one command, pass `--site <id>` or set `DPLY_EDGE_SITE`.

## Deploy and preview from the terminal

| Task | Command |
|---|---|
| Deploy the linked app and wait until it's live | `dply deploy --wait` |
| Deploy a specific branch or commit | `dply edge deploy --branch <name>` or `dply edge deploy --commit <sha>` |
| Create a preview of a branch | `dply edge previews create --branch <name> --wait` |
| Promote a preview to production | `dply edge promote <preview-id>` |
| Roll back | `dply edge rollback <deployment-id>` |
| See the current deployment | `dply edge status` |
| Open the live URL | `dply edge open` |

The CLI deploys a commit that is already pushed to your repository. It doesn't upload files from your machine.

A preview gets its own URL and doesn't touch production. See [Preview deployments](/docs/preview-deployments).

## Environment variables

Keep local values in your framework's usual `.env` file. dply stores the app's production values separately, encrypted.

The API and CLI never return values, only keys. To start a local `.env` with the right keys, pull them and fill in the values yourself:

```bash
dply edge env pull > .env.example
```

This writes each key as `KEY=` with an empty value.

To set values from the terminal:

```bash
dply edge env set APP_DEBUG=false MAIL_MAILER=ses
dply edge env rm OLD_KEY
```

> [!WARNING]
> `dply edge env push --file <path>` replaces the app's entire production environment with the contents of the file. Any key that isn't in the file is deleted. Use `dply edge env set` to change individual keys.

New values apply on the next deploy. See [Environment variables](/docs/environment-variables).

## Resources on your machine

Resources run in dply's infrastructure and are connected to your deployed app on each deploy. The usual setup is to run a local equivalent while you develop.

| Resource | Locally |
|---|---|
| Postgres, MySQL, MongoDB | A local database server, or a container |
| Valkey (Redis) | A local Redis or Valkey server |
| Key-value, object storage, Edge SQL, queues | For Worker SSR apps, `wrangler dev` (below). For container apps, a local equivalent such as a filesystem disk, SQLite or a sync queue. |
| Realtime | Your framework's local WebSocket server, such as Laravel Reverb |

Point your local `.env` at the local services. On deploy, dply sets the connection variables for the real resources.

### Worker SSR apps with Wrangler

If your Worker SSR app has a `wrangler.jsonc`, `wrangler.json` or `wrangler.toml`, dply reads the bindings it declares at the top level (key-value namespaces, R2 buckets, D1 databases and queues) on every deploy, and creates any that don't exist yet. Run the same file locally with Wrangler, which simulates those bindings on your machine:

```bash
npx wrangler dev
```

> [!NOTE]
> dply uses only top-level bindings. Environment-specific sections such as `[env.production]` are ignored. When a binding in your Wrangler file has the same name as one on the app's **Overview**, the Wrangler file wins.

### Query and test resources from the CLI

| Task | Command |
|---|---|
| List Edge SQL databases | `dply db list` |
| Run SQL against one | `dply db query <database> "select count(*) from users"` |
| List queues | `dply queues list` |
| Send a test message | `dply queues send <queue> '{"hello":"world"}'` |

## Check your configuration before you push

If your repository has a `dply.yaml`, validate it locally:

```bash
dply edge lint
```

Pass `--path` to lint a file somewhere else. See [Configuration files](/docs/configuration-files).

## Watch the deployed app

```bash
dply edge logs
```

This follows the app's request logs. Add `--once` to print the latest entries and exit. See [Logs](/docs/logs).

## Related

- [CLI](/docs/cli)
- [Environment variables](/docs/environment-variables)
- [Preview deployments](/docs/preview-deployments)
- [Configuration files](/docs/configuration-files)
