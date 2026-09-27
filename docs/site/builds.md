---
title: "Builds"
description: "How dply clones, installs, and builds your repository, and the settings, limits, and caches that apply to every build."
---

Every deploy starts with a build. dply clones your repository at the commit being deployed, installs dependencies, runs your build command in an isolated container, and uploads the output directory to the edge. This page covers the build settings, how dply picks a Node version and package manager, the build cache, and the limits your plan sets on build time and concurrency.

Container apps build differently: dply builds a Docker image from your repository instead of running a Node build. See [Container apps](/docs/containers).

## Build settings

In your app, open **Build**. The top of the page shows the connected repository and branch, then the two settings most apps need:

| Setting | What it does | Default |
|---------|--------------|---------|
| **Build command** | The shell command that produces your site. Required, up to 500 characters. | `npm ci && npm run build` |
| **Output directory** | The folder, relative to the repository root, that dply uploads after the build. Required. | `dist` |
| **Deploy on push** | Shown next to the production branch. See [Deploy triggers & hooks](/docs/deploy-triggers). | On |

Choose **Save**. Settings apply to the next deploy, so redeploy afterwards: open **Deploys** and choose **Redeploy now**.

Open **Advanced** for the rest:

| Setting | What it does |
|---------|--------------|
| **Repository root** | A subdirectory to build from, such as `apps/web`. See [Monorepos](/docs/monorepos). |
| **SPA fallback** | Serves `index.html` for unknown paths so client-side routers work. Not shown for server-rendered or container apps. Saving a change to this setting updates the live deployment immediately. |
| **Show deploy id in the site footer** | Prints the live deployment id at the bottom of HTML pages. |
| **Releases to keep** | How many past deployments keep their files for rollback, from 1 to 50. The default is 10. Older deployments stay in the history but lose their files. |

A `dply.yaml` file in your repository can override the build command, output directory, and build root on every deploy. When it does, the **Advanced** panel shows **Managed by dply.yaml** and any parse warnings. See [Configuration files](/docs/configuration-files).

> [!NOTE]
> The repository and production branch are fixed when you create the app. To build from a different repository or branch, create a new app.

## What a build does

Each build runs these steps, and the live log on **Deploys** shows each one as it happens:

1. **Clone.** dply clones the repository at the commit being deployed, using your linked Git account for a private repository (the token never appears in the log). When a **Repository root** is set, only that folder and the workspace files it needs are checked out. See [Source control](/docs/source-control#private-repositories).
2. **Read configuration.** dply reads `dply.yaml` and `wrangler.toml` from the build directory. A `dply.yaml` that fails validation stops the build here.
3. **Restore the build cache.** See Build cache below.
4. **Install and build.** dply installs dependencies with the package manager your lockfile names, then runs your build command.
5. **Check the output.** The output directory must exist and, for static and hybrid sites, contain an `index.html` at its root. The total output must be under 500 MB.
6. **Publish.** dply uploads the output and switches traffic to the new deployment. See [Deployments](/docs/deployments).

## Node.js version

dply picks the Node.js version from your repository, checking these in order and using the first one it finds:

1. `engines.node` in `package.json`
2. `.nvmrc`
3. `.node-version`
4. `packageManager` in `package.json` (for example, `pnpm@11` implies Node 22)

Supported versions are Node 18, 20, 22, and 24. With no version hint, dply uses Node 22. A few rules decide the final version:

- An open-ended range such as `>=18` builds on Node 22, not 18. Many starter templates declare `>=18` while their dependencies need newer Node.
- An odd-numbered release such as 21 rounds up to the next supported version (22).
- A version below 18 builds on 18, and a version above 24 builds on 24.
- `lts/*` aliases in `.nvmrc` are ignored.

The build log shows which version was chosen and why:

```bash
[node] Detected Node 20 from .nvmrc ("20") — using node:20-bookworm
```

> [!NOTE]
> Only the four sources above set the Node version. A `node` key under `build:` in `dply.yaml`, or a `NODE_VERSION` variable, has no effect.

## Package managers

dply installs dependencies for you, based on the lockfile in the build directory:

| Lockfile | Install command |
|----------|-----------------|
| `pnpm-lock.yaml` | `pnpm install --frozen-lockfile`, through Corepack, with a pnpm version matched to the Node version |
| `yarn.lock` | `yarn install --frozen-lockfile`, through Corepack (the `packageManager` version when set, otherwise Yarn 4) |
| `bun.lock` or `bun.lockb` | `bun install --frozen-lockfile` |
| `package-lock.json` | `npm ci` |
| `package.json` only | `npm install` |

Because dply runs the install step itself, it adjusts your build command in three ways:

- A leading install step, such as `npm ci &&` or `pnpm install &&`, is removed so dependencies are not installed twice.
- `npm run` and `npm exec` are rewritten to the detected package manager, so `npm run build` runs as `pnpm run build` in a pnpm repository.
- `--if-present` is added to each `run` script.

> [!IMPORTANT]
> Because of `--if-present`, a missing `build` script does not fail the install step. The build fails later with `Build output directory not found` or `Build output is missing index.html`. If you see either error, check that the script named in your build command exists in `package.json`.

## Environment variables during the build

The build container receives your production environment variables, the secrets linked to the app, any `env.public` values and `build.env_files` from `dply.yaml`, and variables from attached resources such as Realtime. Frameworks that inline variables at build time, like Vite's `VITE_*`, read them here. See [Environment variables](/docs/environment-variables).

## Build cache

dply caches dependencies and framework caches between builds of the same app:

- `node_modules` and `node_modules/.cache`
- `.next/cache`, `.nuxt`, `.astro`, `.svelte-kit`
- `dist/.cache`

The cache key combines the Node version, the repository root, and your lockfile. When the lockfile changes, dply restores the newest cache built on the same Node version, and your package manager reconciles the difference. Each app keeps up to 500 MB of cache, and the oldest entries are removed first. The cache is saved after the deployment is published, so it does not slow the deploy down.

Separately, downloaded npm, pnpm, and Yarn packages are cached per organization, and each repository's Git history is kept between builds, so repeat clones fetch only new commits.

The cache never blocks a deploy. If a restore or save fails, the log records it and the build continues cold.

> [!NOTE]
> There is no control to clear the build cache. A lockfile change starts a fresh cache key. To force a cold install, change the lockfile.

## Limits

Build concurrency and timeout depend on your organization's plan; build time itself has no allowance — it bills per second from the first second, less your plan's included usage credit:

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

Build time bills at $0.006 per minute, billed per second, less your plan's included usage credit. See [Plans & pricing](/docs/pricing).

The free trial runs on the Pro limits, within the trial's $5 usage cap. See [Free trial](/docs/free-trial).

How these work:

- **Build time** is counted, per second, from the start of the clone to the end of the build. Time spent waiting in the queue and publishing is not counted. Failed builds count too.
- **Concurrency** is per organization. When every slot is busy, the next build waits and its log shows `Queued — waiting for an earlier build in this organization to finish.`
- **Timeout** stops a build that runs longer than your plan allows. The deploy fails and the live site keeps serving the previous deployment.
- **Superseded builds.** Starting a new deploy of an app cancels any build of that app that is still running. When you push several commits quickly, only the newest one finishes.

Each build container also has fixed resources: 4 GB of memory, 2 CPUs, and a limit of 2,048 processes.

## Build isolation

Your build runs code from your repository and your dependencies, so dply isolates it from the platform and from other customers:

- Each build runs in its own container, as an unprivileged user, with all Linux capabilities dropped and privilege escalation blocked.
- Builds run on a dedicated network, and cloud metadata hostnames resolve to nowhere.
- Package download caches are separate for each organization, and the build cache is separate for each app, so one customer's build cannot change what another customer's build installs.
- The build receives only your app's own environment variables and linked secrets.

For more on how dply separates customers, see [Platform security & isolation](/docs/platform-security).

## Troubleshooting

| Log message | What to check |
|-------------|---------------|
| `Build output directory not found: dist` | The **Output directory** does not match what your framework writes, or the build script did not run. |
| `Build output is missing index.html at the root of: dist` | Point **Output directory** at the folder that contains `index.html`, or use server rendering for apps without one. |
| `Build artifacts exceed maximum allowed size.` | The output is over 500 MB. Move large media to [object storage](/docs/resources/object-storage). |
| `dply config lint failed: …` | Fix the listed errors in `dply.yaml`. See [Configuration files](/docs/configuration-files). |
| `Repository root "apps/web" was not found in the checkout.` | Correct **Repository root** under **Advanced**. |

For more, see [Troubleshooting builds](/docs/guides/troubleshooting-builds).

## Next steps

- [Configuration files](/docs/configuration-files)
- [Deployments](/docs/deployments)
- [Monorepos](/docs/monorepos)
- [Usage & metering](/docs/usage)
