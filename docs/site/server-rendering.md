---
title: "Server rendering (SSR)"
description: "Render Next.js, Astro, SvelteKit and Remix pages per request on the edge network with Worker SSR."
---

**Worker SSR** runs your framework's server code on the edge network, in a Worker close to each visitor, instead of shipping only prebuilt files. Each request renders on demand; static assets from the same build are served alongside. Use it for a JavaScript framework app that needs server rendering, API routes or server actions, and that builds for Cloudflare Workers.

For a PHP, Ruby or Node server process, use [Container apps](/docs/containers). To keep an existing backend and serve the rest statically, use a [Hybrid](/docs/static-and-hybrid) app.

> [!NOTE]
> Worker SSR needs a paid plan, including the trial. It has no per-site fee: a Worker SSR app bills for its Workers CPU time and delivery usage like any other app. See [Plans & pricing](/docs/pricing).

## Supported frameworks

dply detects the framework from `package.json`. Frameworks other than Next.js need their Cloudflare adapter installed, or the build fails with a message naming the missing package.

| Framework | Detected by | Adapter you install | Build command | Worker output |
|---|---|---|---|---|
| Next.js | `next` | none | dply runs `npx @opennextjs/cloudflare build` | `.open-next/worker.js` |
| SvelteKit | `@sveltejs/kit` | `@sveltejs/adapter-cloudflare` | your build command | `.svelte-kit/cloudflare/_worker.js/` |
| Astro | `astro` | `@astrojs/cloudflare` | your build command | `dist/_worker.js/` |
| Remix | `@remix-run/cloudflare` | `@remix-run/cloudflare` | your build command | `build/server/index.js` |
| Keel | `@shaferllc/keel` | none | dply runs `wrangler deploy --dry-run` | `.dply-keel-bundle/worker.js` |

For Next.js and Keel, dply ignores the app's build command and runs its own. For the others it runs your build command (by default `npm run build`) after installing dependencies, and expects the Worker output at the path above. Remix's older `_worker.js` template is not detected.

## Create a Worker SSR app

When dply detects a supported framework, choose **Worker SSR** as the delivery mode on create. The first build of a Next.js app can take several minutes.

## How requests are handled

Each deploy uploads its own Worker script and its static assets. Requests to your hostnames pass through the firewall, bot protection, rate limits, access gate and your [redirects](/docs/routing), then go to that deploy's Worker, which renders the page or serves an asset.

[Edge middleware](/docs/edge-middleware) from `src/middleware.ts` is not used on Worker SSR apps; your framework's own middleware (for example Next.js `middleware.ts`) runs inside the Worker instead.

Each request to the Worker carries `X-Dply-Deployment-Id` and `X-Dply-Site-Id` headers.

## Environment variables

Every production variable under **Environment** is available to your server code on the Worker's `env` object (for example `env.API_KEY`), as a secret. Frameworks that read `process.env` get the same values where their Cloudflare adapter provides it. Changes apply on the next deploy.

The Worker runs with Cloudflare's `nodejs_compat` flag and a compatibility date of `2024-11-01`.

## Limits

- The Worker bundle, including every module, can be at most 9 MB. A larger bundle fails the build with the size and module count.
- Your code runs under Cloudflare's Workers runtime limits for CPU time and memory per request. dply does not change them.
- A Worker runs no long-lived process. For background work use [Queues](/docs/resources/queues) or [Workflows](/docs/resources/workflows).

## Caching

dply does not add `Cache-Control` to rendered responses; your framework's headers pass through. To cache rendered pages on the edge, turn caching on in the **Cache** section. See [Caching](/docs/caching).

## Rollbacks, promotions and previews

Every deploy keeps its own Worker script, so a [rollback](/docs/deployments) or a [preview](/docs/preview-deployments) promotion switches traffic to an existing script without rebuilding. Old scripts are removed when their deploy ages out of the app's retained releases.

## Scheduled work

A schedule on a Worker SSR app calls the `scheduled` export of your Worker entry. Framework builds do not export one by default. See [Scheduled tasks](/docs/scheduled-tasks).

## When the Worker is unreachable

If the Worker for a deploy cannot be reached, visitors get `503` with `Retry-After: 30` and the text "Service temporarily unavailable". Redeploy the app; if it persists, contact [support](/docs/support).

## Related

- [Frameworks & runtimes](/docs/frameworks)
- [Deploy a Next.js app](/docs/guides/nextjs)
- [Deploy an Astro site](/docs/guides/astro)
- [Environment variables](/docs/environment-variables)
