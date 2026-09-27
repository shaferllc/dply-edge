---
title: "Frameworks & runtimes"
description: "The frameworks and language runtimes dply detects, the versions it builds with, and how to override what it detects."
---

When you create an app, dply inspects your repository, works out which framework it uses, and fills in the build command, output directory and runtime mode. This page lists everything dply recognizes, the runtime versions it uses, and how to change what it chose.

## How detection works

After you pick a repository and branch on the **Create an app** page, dply reads the files at the repository root (or at the package you chose in a monorepo) and shows the result, for example `astro · Site`.

dply checks for these files, and when several match, the more specific one wins:

1. `composer.json`: a PHP app.
2. `Gemfile`: a Ruby app, unless it names `jekyll` or `github-pages` (then it's a Jekyll site).
3. `package.json`: a Node.js app. The dependencies decide the framework.
4. A static site generator config file, or an `index.html` at the root: a static site.

PHP and Ruby apps often ship a `package.json` for their frontend assets. Because their own manifest takes precedence when it names a framework, a Laravel app with Vite is detected as Laravel, not as a Vite site. dply builds its frontend assets as part of the container image.

The same rules apply whether dply reads your files through the GitHub API or clones the repository (GitLab, Bitbucket, or anything the API path can't read), so a project is always detected the same way.

From the framework, dply recommends **Site** or **App** on the create page:

- A PHP, Ruby or Node HTTP server is an **App** that runs as a **Container**.
- A Next.js app that renders on the server (no `output: 'export'`) or a Keel app is an **App** that runs as **Worker SSR**.
- Other JavaScript frameworks that render on the server are recommended as **Hybrid** (under **Advanced**), because running them as Worker SSR needs a Cloudflare adapter in your project. Choose **App** if yours has one.
- Anything else that builds files is a **Site** and runs as **Static**.

## Container apps

These run on Cloudflare Containers. If your repository has a `Dockerfile` at its root, dply builds that and routes traffic to the port in its `EXPOSE` line (8080 when there isn't one). Otherwise dply generates a Dockerfile for the stack below.

Container apps are available on every plan, including the trial. See [Container apps](/docs/containers).

| Framework | Detected from | Runtime | How it serves |
|---|---|---|---|
| Laravel | `laravel/framework` in `composer.json` | PHP | php-fpm behind nginx |
| Laravel with Octane | `laravel/octane` in `composer.json` | PHP | Swoole, or RoadRunner when a `spiral/roadrunner` package is required |
| Symfony | `symfony/framework-bundle` in `composer.json` | PHP | php-fpm behind nginx |
| Other PHP | `composer.json` | PHP | php-fpm behind nginx |
| Rails | `rails` in `Gemfile` | Ruby | Puma |
| Sinatra and other Rack apps | `Gemfile` | Ruby | `rackup` |
| NestJS | `@nestjs/core` in `package.json` | Node.js | `npm start` |
| Express, Fastify, Koa | `express`, `fastify` or `koa` in `package.json` | Node.js | `npm start` |

Node container apps install with `pnpm`, `yarn` or `npm` depending on which lockfile the repository has, and start with the matching `start` command. The app must listen on the `PORT` environment variable, which is set to `8080`.

### PHP versions

| | |
|---|---|
| Supported | 8.2, 8.3, 8.4, 8.5 |
| Chosen from | `config.platform.php` in `composer.json`, then `require.php` |
| Open-ended constraints | Get the newest supported version up to 8.4. `^8.2` builds with 8.4. |
| Newer versions | Name them explicitly. `^8.5` or `8.5.*` builds with 8.5. |

PHP extensions your `composer.json` or `composer.lock` require are installed into the image before `composer install` runs.

### Ruby versions

dply reads the major and minor version from `.ruby-version`, and uses 3.3 when the file is missing. Ruby 3.2, 3.3 and 3.4 have prebuilt base images, so they build fastest.

### Node.js versions

| | |
|---|---|
| Supported | 18, 20, 22, 24 |
| Default | 22 |
| Chosen from | `engines.node` in `package.json`, then `.nvmrc`, then `.node-version`, then `packageManager` |

Any other version is moved to a supported one: below 18 builds with 18, above 24 with 24, and a version in between with the next supported one up. The same rule picks the Node.js version for static, hybrid and Worker SSR builds.

## JavaScript frameworks

These build in a Node.js image and serve from the edge.

| Framework | Detected from | Build command without a `build` script | Output directory | Default mode |
|---|---|---|---|---|
| Next.js | `next` | `npm run build` | `out` | Site with `output: 'export'`, otherwise App (Worker SSR) |
| Nuxt | `nuxt` | `npm run generate` (always) | `.output/public` | Site |
| Astro | `astro` | `npm run build` | `dist` | Static |
| SvelteKit | `@sveltejs/kit` | `npm run build` | `build` | Hybrid |
| Remix | `remix` or any `@remix-run/*` package | `npm run build` | `build/client` | Hybrid |
| Hono | `hono` | `npm run build` | `dist` | Hybrid |
| Keel | `@shaferllc/keel` | `npm run css:build --if-present` | `public` | Hybrid |
| Vite | `vite` | `npm run build` | `dist` | Static |
| Gatsby | `gatsby` | `npm run build` | `public` | Static |
| Eleventy | `@11ty/eleventy` | `npx @11ty/eleventy` | `_site` | Static |
| VitePress | `vitepress` | `npm run docs:build` | `docs/.vitepress/dist` | Static |
| Docusaurus | `@docusaurus/core` | `npm run build` | `build` | Static |
| Other Node.js project with a `build` script | `package.json` | `npm run build` | `dist` | Static |

When the project's `package.json` defines a `build` script, dply runs `npm run build` instead of the command in the table. Nuxt is the exception: dply always builds it with `npm run generate` (or `npx nuxi generate` when there's no `generate` script), because a plain `nuxt build` makes a server build with no complete static output.

A Next.js project is a static site only when `next.config.js`, `next.config.mjs`, `next.config.ts` or `next.config.cjs` sets `output: 'export'` (or the build script runs `next export`). Otherwise it renders on the server.

To check or change the build command and output directory before you deploy, open **Advanced** on the create page.

> [!IMPORTANT]
> **Hybrid** needs an **Origin URL**: a server you already run that handles your app's server routes. dply serves the static files and proxies the rest. See [Static & hybrid sites](/docs/static-and-hybrid).

### Worker SSR

Worker SSR runs your app's server code as a Cloudflare Worker, with no origin to run. It's what **App** means for a server-rendered JavaScript framework. Next.js and Keel need nothing extra; the others need the framework's Cloudflare adapter in your project. It needs a paid plan.

| Framework | Adapter | What dply runs |
|---|---|---|
| Next.js | None needed | `npx @opennextjs/cloudflare build`, in place of your build command |
| SvelteKit | `@sveltejs/adapter-cloudflare` | Your build command |
| Astro | `@astrojs/cloudflare` | Your build command |
| Remix | `@remix-run/cloudflare` | Your build command |
| Keel | None needed | A Wrangler dry-run bundle of your Worker |

Worker SSR sites have no per-site fee; they bill for their Workers CPU time and delivery usage like any other app. See [Server rendering (SSR)](/docs/server-rendering) and [Plans & pricing](/docs/pricing).

## Static sites

| Site | Detected from | Build command | Output directory |
|---|---|---|---|
| Plain HTML | `index.html` at the repository root | None | `.` (the repository root) |

Hugo and Jekyll sites are detected (from `hugo.toml` or a `config.toml` with Hugo keys, and from `_config.yml` or a Jekyll `Gemfile`), but dply can't build them yet: builds run in a Node.js image without Hugo or Ruby. The create page says so and doesn't let you deploy. Commit the built site (`public` or `_site`) to a repository and deploy that as a plain HTML site, or use a Node.js generator such as Eleventy or Astro.

## Not supported

dply stops you on the create page when it detects one of these, and shows why.

| Stack | Detected from |
|---|---|
| Python: Django, Flask, FastAPI and others | `pyproject.toml`, `requirements.txt`, `Pipfile` or `setup.py` |
| Go | `go.mod` |
| WordPress | `wp-config.php`, or `roots/wordpress` or `johnpbloch/wordpress` in `composer.json` |

A repository that looks like a framework's own source or a monorepo root, rather than a single app, is also stopped. Pick the app's package directory instead.

## Override the detected settings

### In the dashboard

In your app, open **Build**.

| Setting | What it does |
|---|---|
| **Build command** | The command that builds the app |
| **Output directory** | The directory the build writes files to, relative to the repository root |
| **Deploy on push** | Deploy every push to the production branch |
| **Advanced** → **Repository root** | A monorepo subdirectory to build from. Auto-deploy only runs for changes under it. |
| **Advanced** → **SPA fallback** | Serve `index.html` for unknown paths after a 404 |

Choose **Save**, then redeploy. Changes apply to the next deploy.

### In your repository

A `dply.yaml`, `dply.yml` or `dply.json` file at the repository root overrides the dashboard's build settings on every deploy:

```yaml
build:
  command: npm run build
  output: dist
  root: apps/web
```

When the file sets build keys, **Build** shows it under **Repo config**. See [Configuration files](/docs/configuration-files) for every key.

### Container apps

Commit a `Dockerfile` at the repository root to control the image completely. dply builds it as it is.

## Related

- [Builds](/docs/builds)
- [Monorepos](/docs/monorepos)
- [Container apps](/docs/containers)
- [Troubleshooting builds](/docs/guides/troubleshooting-builds)
