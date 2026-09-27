---
title: "Deploy a Next.js app"
description: "Deploy a Next.js app to dply as a static export, a hybrid site in front of your own server, or Worker SSR through OpenNext."
---

dply runs Next.js three ways. Pick the one that matches how your app renders: a static export served entirely from the edge, a hybrid site that serves assets from the edge and sends server routes to a Next server you already run, or Worker SSR, where dply builds the app with OpenNext and runs it on Cloudflare Workers.

| Mode | Use it when | Needs |
|---|---|---|
| **Static** | Every page can be prerendered (`output: 'export'`) | Nothing extra |
| **Hybrid** | You already run `next start` somewhere and want the edge in front | The URL of that server |
| **Worker SSR** | You want server rendering, API routes and middleware on the edge | The OpenNext Cloudflare adapter; a paid plan |

> [!NOTE]
> Worker SSR has no per-site fee; it bills for Workers CPU time and delivery usage like any other app. See [Plans & pricing](/docs/pricing).

## How dply detects Next.js

dply recognizes Next.js from `next` in `package.json` and pre-fills:

- **Build**: `npm run build` when the repository has a `build` script.
- **Output**: `out`, the folder `next build` writes for a static export.
- **Mode**: **Static** in most cases. When detection finds a `next start` script (repositories dply clones rather than reads through the GitHub API), it suggests **Hybrid** instead.

The install step follows your lockfile (`pnpm-lock.yaml`, `yarn.lock`, `bun.lock`, `package-lock.json`). The Node version comes from `engines.node`, `.nvmrc`, `.node-version` or the `packageManager` field; Node 18, 20, 22 and 24 are available, and 22 is the default.

## Static export

1. In `next.config.js`, export the app:

   ```js
   /** @type {import('next').NextConfig} */
   const nextConfig = {
     output: 'export',
     images: { unoptimized: true },
   };

   module.exports = nextConfig;
   ```

2. Choose **New app**, pick the repository and branch, and check that the **Deploy summary** shows **Static / SSG** with output `out`.
3. Choose **Deploy**.

The build must leave an `index.html` at the root of `out`. Routes that need a server (API routes, `getServerSideProps`, middleware that rewrites per request) do not work in a static export.

## Hybrid: the edge in front of your Next server

Use hybrid when the app already runs on a server you control.

1. Create the app as above. When the **Deploy summary** shows **Hybrid**, the create page asks for an **Origin URL**: the address your Next server answers on, for example `https://next-origin.example.com`.
2. Choose **Deploy**.

dply serves built static files from the edge and proxies matching paths to the origin. Change the origin and the proxied routes later on the **Delivery** tab under **Hybrid origin**. If the origin does not answer, visitors see a 503 page; you can replace it with **Failover HTML**.

To turn an existing static site into a hybrid one, open **Delivery** and choose **Convert to hybrid**. The default proxied routes are `/api/*` and `/_next/data/*`.

See [Static & hybrid sites](/docs/static-and-hybrid).

## Worker SSR with OpenNext

In Worker SSR mode dply ignores your build command and runs:

```bash
npx --yes @opennextjs/cloudflare@latest build
```

It then uploads `.open-next/worker.js` as the app's Worker and `.open-next/assets` as its static files. Each deployment gets its own Worker, so rollbacks and preview promotions do not rebuild.

1. Set up OpenNext's Cloudflare adapter in the repository following the OpenNext documentation, and confirm `npx @opennextjs/cloudflare build` succeeds locally.
2. The create page does not offer a mode picker. To create a Worker SSR app, open the create page with the mode in the address, then continue as usual:

   ```http
   /projects/create?runtime_mode=ssr
   ```

3. Check that the **Deploy summary** shows **Worker SSR**, then choose **Deploy**.

Your environment variables reach the build and are bound on the Worker as secrets. Read them from `process.env` as usual.

> [!IMPORTANT]
> The Worker bundle must stay under 9 MB. Large server dependencies can push an OpenNext build past it; the build then fails with "SSR worker bundle … exceeds the per-script size limit".

See [Server rendering (SSR)](/docs/server-rendering).

## Environment variables

Set variables under **Environment**. Production variables are passed to the build, so `NEXT_PUBLIC_*` values are inlined into the client bundle at build time: change one, then redeploy. See [Environment variables](/docs/environment-variables).

## Previews and domains

Every branch or pull request can get a preview deployment; see [Preview deployments](/docs/preview-deployments). Add a custom domain under **Routing**, **Domains**; see [Domains](/docs/domains).

## Next steps

- [Routing, redirects & headers](/docs/routing)
- [Caching](/docs/caching)
- [Troubleshooting builds](/docs/guides/troubleshooting-builds)
