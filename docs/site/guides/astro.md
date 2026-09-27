---
title: "Deploy an Astro site"
description: "Deploy an Astro site to dply as a static build served from the edge, or with server rendering on Workers through the Cloudflare adapter."
---

Astro builds static files by default, and that is how dply deploys it: the build runs once, the output is stored at the edge, and every page is served without a server. If your site uses on-demand rendering, dply can run it on Workers with Astro's Cloudflare adapter.

## Static site

### What dply detects

dply recognizes Astro from `astro` in `package.json` and pre-fills:

| Setting | Value |
|---|---|
| **Mode** | **Static / SSG** |
| **Build** | `npm run build` |
| **Output** | `dist` |

The install step follows your lockfile: `pnpm install` for `pnpm-lock.yaml`, `yarn install` for `yarn.lock`, `bun install` for `bun.lock`, `npm ci` for `package-lock.json`. `npm run` in the build command is translated to your package manager.

The Node version comes from `engines.node`, `.nvmrc`, `.node-version` or the `packageManager` field. Node 18, 20, 22 and 24 are available; 22 is the default.

### Deploy it

1. Choose **New app**.
2. Connect your source control account and select the repository and branch.
3. On **Step 3 of 3 · Create your application**, check the **Deploy summary**: **Static / SSG**, build `npm run build`, output `dist`.
4. Choose **Deploy**.

To change the build command, output directory or repository root later, open **Build**, change **Build command** or **Output directory**, choose **Save**, then redeploy.

> [!IMPORTANT]
> A static build must leave an `index.html` at the root of the output directory. If `dist` has no `index.html`, the deploy fails with "Build output is missing index.html at the root of: dist".

### Client-side routing

If the site is a single-page app that handles its own routes, turn on **SPA fallback** under **Build**, **Advanced**, so unknown paths serve `index.html`.

### Redirects and headers

Add redirects and headers under **Routing**, or in a `dply.yaml` at the repository root:

```yaml
redirects:
  - from: /blog/*
    to: /posts/:splat
    status: 301

headers:
  - for: /_astro/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"
```

See [Configuration files](/docs/configuration-files) and [Routing, redirects & headers](/docs/routing).

## On-demand rendering with Worker SSR

For pages rendered per request, dply runs your Astro server on Workers.

> [!NOTE]
> Worker SSR needs a paid plan. It has no per-site fee; it bills for Workers CPU time and delivery usage like any other app. See [Plans & pricing](/docs/pricing).

1. Add the Cloudflare adapter:

   ```bash
   npx astro add cloudflare
   ```

   This installs `@astrojs/cloudflare` and sets it as the adapter in `astro.config.mjs`.

2. The create page does not offer a mode picker. Open it with the mode in the address:

   ```http
   /projects/create?runtime_mode=ssr
   ```

3. Select the repository, check that the **Deploy summary** shows **Worker SSR**, and choose **Deploy**.

dply runs your build command after installing dependencies, then uploads `dist/_worker.js/` as the site's Worker and `dist` as its static files.

If `@astrojs/cloudflare` is missing from `package.json`, the build stops with "Astro (via @astrojs/cloudflare) needs `@astrojs/cloudflare` in package.json before SSR builds work". The Worker bundle must stay under 9 MB.

See [Server rendering (SSR)](/docs/server-rendering).

## Environment variables

Set variables under **Environment**. They are passed to the build, so `PUBLIC_*` values Astro inlines are fixed at build time: change one, then redeploy. On Worker SSR sites they are also bound on the Worker. See [Environment variables](/docs/environment-variables).

## Next steps

- [Preview deployments](/docs/preview-deployments)
- [Domains](/docs/domains)
- [Caching](/docs/caching)
