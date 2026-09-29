---
title: "Edge middleware"
description: "Run your own code on the edge before a static or hybrid app serves a request: rewrite, redirect, authenticate, or answer directly."
---

Edge middleware is a small Worker you write that runs on every request to a static or hybrid app, before dply looks up a file or calls your origin. Use it for logic a static site cannot express in routing rules: checking a cookie or token, choosing a variant, answering an API call directly, or setting headers per request. It can also run [scheduled tasks](/docs/scheduled-tasks).

Middleware applies to **Static** and **Hybrid** apps. Worker SSR apps use their framework's own middleware, and container apps handle requests in the container.

## Add middleware

Commit one of these files. dply uses the first it finds:

- `src/middleware.ts`, `src/middleware.tsx`, `src/middleware.js`, `src/middleware.mjs`
- `middleware.ts`, `middleware.tsx`, `middleware.js`, `middleware.mjs` at the repository (or app) root

On each deploy, dply bundles the file and its imports into one module with esbuild (ES2022, browser and worker conditions) and uploads it with the deploy. The build log shows `[middleware] detected …` and the bundle size.

The bundle can be at most 2 MB. Imports must be bundleable or built into the Workers runtime; Node-only packages will not work.

## Write middleware

Middleware default-exports an object with a `fetch` handler. It receives the visitor's request and your environment variables on `env`:

```ts
// src/middleware.ts
export default {
  async fetch(request: Request, env: Record<string, string>) {
    const url = new URL(request.url);

    if (url.pathname.startsWith("/account") && !request.headers.get("cookie")?.includes("session=")) {
      return Response.redirect(new URL("/login/", url).toString(), 302);
    }

    // Let dply keep handling the request.
    return new Response(null, {
      status: 204,
      headers: { "X-Dply-Middleware": "continue" },
    });
  },
};
```

What your handler returns decides what happens next:

| Your response | Result |
|---|---|
| Status `204` with `X-Dply-Middleware: continue` | dply continues with the original request: redirects, your files, the hybrid origin, SPA fallback. |
| Anything else | Sent to the visitor as-is, with your [header rules](/docs/routing) applied. dply adds its default security headers where you did not set them, and `X-Dply-Middleware: handled`. |

To pass the request through, you must return exactly that `204` response; returning the result of `fetch(request)` answers the visitor directly.

The request your middleware receives carries two extra headers: `X-Dply-Deployment-Id` (the deploy serving it) and `X-Dply-Site-Id`.

## Where middleware runs in a request

Middleware runs after the firewall, rate limits, bot protection, waiting room and access gate, and before redirects and file lookup. See the full order in [Static & hybrid sites](/docs/static-and-hybrid#how-a-request-is-handled).

## Environment variables

Every production variable under **Environment** is available on `env` (for example `env.API_KEY`). Changes apply on the next deploy.

## When middleware fails

> [!WARNING]
> Middleware fails open. If the bundle fails to build, the deploy goes live without middleware and only the build log says so. If your handler throws at runtime, the request continues as if middleware had passed it through. Do not use middleware as the only protection for private content; use [Access control](/docs/access-control) for that.

## Scheduled handlers

Export a `scheduled` handler next to `fetch` to run code on a cron schedule. Schedules are set on **Overview** (**Scheduled tasks**) or in `dply.yaml`. See [Scheduled tasks](/docs/scheduled-tasks).

## Deploys and rollbacks

Each deploy uploads its own middleware, so a rollback or preview promotion brings the matching middleware with it. Removing the file removes middleware on the next deploy.

## Related

- [Static & hybrid sites](/docs/static-and-hybrid)
- [Routing, redirects & headers](/docs/routing)
- [Scheduled tasks](/docs/scheduled-tasks)
- [Access control](/docs/access-control)
