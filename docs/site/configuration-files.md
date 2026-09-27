---
title: "Configuration files"
description: "The files dply reads from your repository on each build: dply.yaml, wrangler.toml, dply-contract.yaml, and edge middleware."
---

You can keep most of an app's configuration in your repository, next to the code it configures. On every build, dply reads a `dply.yaml` for build settings, routing rules, and other app features, and a `wrangler.toml` (or `wrangler.json`) for Cloudflare bindings. Both are optional: an app with neither builds with the settings on its **Build** page.

## Where dply looks

dply reads configuration files from the app's build folder: the top of the repository, or the **Repository root** folder when one is set. In a monorepo, put the files next to the package's `package.json`, not at the top of the repository. See [Monorepos](/docs/monorepos).

| File | Read as |
|------|---------|
| `dply.yaml`, `dply.yml`, or `dply.json` | App configuration. The first one found is used. |
| `wrangler.jsonc`, `wrangler.json`, or `wrangler.toml` | Bindings only. The first one that parses is used. |
| `dply-contract.yaml` or `dply-contract.yml` | Requirements for promoting a preview. |
| `middleware.ts` (and variants) | [Edge middleware](/docs/edge-middleware). |

## `dply.yaml`

```yaml
build:
  command: pnpm run build
  output: dist

redirects:
  - from: /blog/*
    to: /articles/:splat
    status: 301

headers:
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"

env:
  public:
    APP_ENV: "production"
  secret:
    - "STRIPE_SECRET"

previews:
  exclude_branches:
    - "renovate/lock-file-maintenance"
```

Every section is optional. dply validates the file before building:

- **Errors stop the build.** A YAML or JSON syntax error, a file larger than 64 KB, or a file that is empty or holds only comments fails the build with `dply config lint failed: …`.
- **Warnings do not.** A malformed rule, such as a redirect without `to`, is dropped and listed in the build log as `[dply.yaml] …`. Warnings from the latest deploy also appear on **Build** under **Advanced**.
- **Unknown keys are ignored** without a warning. Check spelling if a setting seems to have no effect.

Settings in `dply.yaml` apply on each deploy that includes them. To change them, commit the change and deploy.

### Top-level keys

| Key | What it configures | Details |
|-----|--------------------|---------|
| `build` | `command`, `output`, `root`, `env_files` | [Below](/docs/configuration-files) |
| `env` | `public` values and `secret` names | [Environment variables](/docs/environment-variables) |
| `redirects`, `rewrites`, `headers` | Routing rules | [Routing, redirects & headers](/docs/routing) |
| `bindings` | KV, R2, D1, and queue bindings for your Worker | [Below](/docs/configuration-files) |
| `previews` | Which pull requests get previews | [Preview deployments](/docs/preview-deployments) |
| `comment_widget` | `enabled` | [Preview deployments](/docs/preview-deployments) |
| `origin` | Hybrid origin `url`, `routes`, `failover_html` | [Static & hybrid sites](/docs/static-and-hybrid) |
| `images` | `allowed_hosts` | [Images](/docs/resources/images) |
| `domains` | Custom domains to attach | [Domains](/docs/domains) |
| `crons` | Scheduled handlers (up to 5) | [Scheduled tasks](/docs/scheduled-tasks) |
| `firewall` | Country allow or block list | [Firewall](/docs/firewall) |
| `alerts` | LCP, error rate, and 5xx thresholds | [Alerts](/docs/alerts) |
| `error_pages`, `maintenance` | Custom 404 and 500 pages, maintenance page | [Error pages](/docs/error-pages) |
| `tags`, `snippets` | Analytics tags and HTML snippets | [Snippets & tags](/docs/snippets) |
| `forms` | Form handling | [Forms](/docs/forms) |

Credentials never belong in the file. dply ignores, with a warning, a preview protection `password`, an image `signing_secret`, and origin credentials, and asks you to set them in the dashboard.

### `build`

```yaml
build:
  command: npm run build
  output: dist
  env_files:
    - .env.production
```

| Key | Effect |
|-----|--------|
| `command` | Replaces the **Build command** from the dashboard for this deploy. |
| `output` | Replaces the **Output directory**. |
| `env_files` | Loads `.env`-style files, relative to the build folder, into the build's environment. Variables set in the dashboard win. A missing file is skipped with a log line. |
| `root` | A subfolder to build in. See the warning below. |

When `dply.yaml` sets build keys, **Build** shows **Managed by dply.yaml** under **Advanced**, and the file's values are used on each deploy even if you change the dashboard fields.

> [!WARNING]
> `build.root` changes where dply detects your package manager and Node version and where it looks for the output directory, but your build command still runs from the build folder. Use the **Repository root** setting instead. See [Monorepos](/docs/monorepos).

> [!NOTE]
> `build.node` is accepted but has no effect. Set the Node version with `engines.node`, `.nvmrc`, or `.node-version`. See [Builds](/docs/builds).

### Download your current configuration

To start a `dply.yaml` from settings you made in the dashboard, open **Environment**, expand **From dply.yaml**, and choose **Generate dply.yaml**. The file includes routing rules, previews, environment declarations, error pages, and other app features from the latest deploy. It does not include `build` or `bindings`.

## Bindings

Bindings give your Worker code access to Cloudflare resources through `env`, for example `env.SESSIONS.get(key)`. You can declare them in `dply.yaml`, in a `wrangler.toml` you already have, or on the app's **Resources** page. For the dashboard way, see [Resources overview](/docs/resources).

> [!NOTE]
> Repository bindings apply to apps that run a Worker: server-rendered and hybrid apps, and static sites with edge middleware. A static site with no middleware ignores them. Container apps use only queue bindings from the repository.

### In `dply.yaml`

```yaml
bindings:
  kv:
    SESSIONS: sessions
  r2:
    UPLOADS: uploads
  d1:
    MAIN_DB: main
  queues:
    JOBS: jobs
```

Binding names must be uppercase letters, digits, and underscores, starting with a letter.

### In `wrangler.toml`

dply reads these top-level tables and ignores everything else, including `[vars]` and `[env.*]` sections:

```toml
[[kv_namespaces]]
binding = "SESSIONS"
id = "sessions"

[[r2_buckets]]
binding = "UPLOADS"
bucket_name = "uploads"

[[d1_databases]]
binding = "MAIN_DB"
database_name = "main"

[[queues.producers]]
binding = "JOBS"
queue = "jobs"
```

| Binding | Value dply uses |
|---------|-----------------|
| KV | `id`, or `preview_id` when `id` is absent |
| R2 | `bucket_name` |
| D1 | `database_id`, or `database_name` when `database_id` is absent |
| Queue | `queue` |

In `wrangler.toml`, use the `[[table]]` form shown above. Inline arrays such as `kv_namespaces = [{ … }]` are not read.

### How names resolve

Every binding value is resolved inside your organization. Give each binding a short name, such as `sessions`, and dply connects it to your organization's resource of that name, creating it on first deploy if it does not exist yet. Two apps in the same organization that use the same name share the resource.

- Names may use letters, digits, and dashes, up to 40 characters. Underscores and dots are not allowed.
- Resources are created in your organization's [data region](/docs/data-regions).
- Creating resources follows your plan: a KV namespace needs a card on file, and D1 databases and queues count toward your plan's limits.
- There is currently no way to turn off automatic creation.

You can also use the id of a resource your organization already owns. An id that belongs to anything else, including a resource in your own Cloudflare account or another organization, is refused:

```bash
wrangler.toml binding MAIN_DB (3f1c…): it is not a resource this organization owns. Use a name such as "cache" instead of an id, and dply creates it in your organization.
```

> [!WARNING]
> If you bring a `wrangler.toml` from an existing Cloudflare project, its KV and D1 ids point at resources in your own Cloudflare account, and the deploy fails with the error above. Replace them with names. An R2 `bucket_name` or `queue` name is treated as a name, so dply creates a new, empty bucket or queue in your organization rather than connecting your existing one. Your data does not move with it.

A few apps deployed before dply separated each organization's resources use names that still point at the old shared resource. For those, the deploy fails with:

```bash
"sessions" is a resource created before dply kept each organization's resources separate. Ask support to move it into your organization; dply will not create an empty one in its place.
```

Contact [support](/docs/support) to have it moved.

Binding errors are reported when the deploy publishes, not in the build log. The deployment fails with the message as its reason, and the previous deployment keeps serving traffic.

### Precedence

When the same binding name is declared in more than one place:

1. `wrangler.toml` wins over `dply.yaml`.
2. Either repository file wins over the **Resources** page. The build log notes it: `wrangler.toml also declares SESSIONS. The repo's binding is used and the Resources page's SESSIONS is skipped.`

These names are reserved by the platform, and bindings that use them are dropped: `HOST_MAP`, `ASSETS`, `DEPLOYMENT_ID`, `SITE_ID`, `STORAGE_PREFIX`, `EDGE_CACHE`, `DISPATCHER`.

## `dply-contract.yaml`

A deploy contract lists the checks a preview must pass before it can be promoted to production, such as a successful build, resolved review comments, or a shadow replay against production traffic. See [Preview deployments](/docs/preview-deployments).

```yaml
promote:
  requires:
    - edge.preview.build
    - edge.preview.review
```

You can also put the same content under a `contract:` key in `dply.yaml`.

> [!NOTE]
> dply reads `dply-contract.yaml` only when the repository also has a `dply.yaml` (or `dply.yml`). Add an empty section, such as `previews: {}`, to `dply.yaml` if you have nothing else to put there.

## Edge middleware

dply bundles edge middleware from the first of these files it finds in the build folder: `src/middleware.ts`, `src/middleware.tsx`, `src/middleware.js`, `src/middleware.mjs`, `middleware.ts`, `middleware.tsx`, `middleware.js`, or `middleware.mjs`. See [Edge middleware](/docs/edge-middleware).

## Related

- [Builds](/docs/builds)
- [Environment variables](/docs/environment-variables)
- [Routing, redirects & headers](/docs/routing)
- [Resources overview](/docs/resources)
