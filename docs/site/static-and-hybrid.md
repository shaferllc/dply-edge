---
title: "Static & hybrid sites"
description: "How dply serves prebuilt files from the edge, caches hashed assets, keeps old tabs working across deploys, and proxies dynamic routes to your own origin."
---

A **Static** app is a set of prebuilt files, such as the output of Astro, Vite, Eleventy or Hugo, served from the edge network close to each visitor. A **Hybrid** app is a static app with some paths, such as `/api/*`, forwarded to a server you run elsewhere. Choose Static for sites with no server code, and Hybrid when you already have a backend and want dply to serve everything else.

For apps that render pages per request, see [Server rendering (SSR)](/docs/server-rendering) or [Container apps](/docs/containers).

## How a deploy is served

Each deploy uploads your build output to its own storage location and then switches the app's hostnames to it in one step. Nothing is overwritten in place, so a [rollback](/docs/deployments) switches back to an earlier deploy without rebuilding.

## How a request is handled

For each request, dply works through these steps in order and stops at the first one that answers:

1. Firewall, rate limits, bot protection, waiting room and forms.
2. A split-traffic variant is picked, if you are splitting traffic with a preview.
3. The access gate, if the hostname is protected.
4. Your [edge middleware](/docs/edge-middleware), if the app has one.
5. Redirects from your [routing rules](/docs/routing).
6. The file for the path, from the current deploy.
7. For a hashed asset that is missing, the same file from a recent earlier deploy (skew protection, below).
8. For a Hybrid app, a path that matches one of its **Proxy routes** goes to your origin.
9. With **SPA fallback** on, `index.html`.
10. Otherwise, your custom 404 page from [Error pages](/docs/error-pages), or a plain 404.

A path ending in `/` serves that directory's `index.html`.

> [!IMPORTANT]
> A path without a trailing slash is looked up as-is. `/about` does not serve `about/index.html` or `about.html`. Link to `/about/`, configure your framework to emit trailing slashes, or add a redirect under [Routing, redirects & headers](/docs/routing).

## Caching headers

dply sets `Cache-Control` on every file from your build:

| File | `Cache-Control` |
|---|---|
| `index.html` in any directory | `public, max-age=0, must-revalidate` |
| Hashed assets | `public, max-age=31536000, immutable` |
| Everything else, including other `.html` files | `public, max-age=3600` |

A file counts as hashed when its name carries a content hash, such as `app.3f9a2c1d.js` or Vite's `index-BXa3Kq9z.js`, or when it sits under `_next/static/`, `_astro/` or `_app/immutable/`. Name your assets with a content hash so browsers keep them for a year and fetch new ones only when they change.

Each response also carries `X-Dply-Deployment-Id` with the deploy that served it, and these security headers: `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `X-XSS-Protection: 0` and `Permissions-Policy: camera=(), microphone=(), geolocation=()`. Headers you set in [routing rules](/docs/routing) are applied after these and override them.

### ETags

Every file response has a weak `ETag`. When a browser revalidates with `If-None-Match` and the file has not changed, dply answers `304 Not Modified` with no body. The ETag also changes when something dply injects into your HTML changes, such as analytics or snippets, so browsers never keep a stale copy of a page.

## Skew protection

A visitor who loaded a page before a deploy may then request JavaScript chunks that the new deploy no longer has. To keep those tabs working, when a request for an asset (any file with an extension other than `.html`) is missing from the current deploy, dply looks for it in the five most recent earlier deploys and serves the newest match. HTML pages are never served from an earlier deploy. Earlier deploys stay available for as long as dply keeps their files.

## Single-page apps

For a client-side router, turn on **SPA fallback** in the **Build** section. Any path with no matching file then serves `index.html` with status 200, after Hybrid proxy routes have had their chance.

## Hybrid: proxy routes to your origin

A Hybrid app forwards matching paths to an origin URL you provide. Static files stay on the edge.

Choose **Hybrid** when you create the app and enter the **Origin URL**, or convert an existing static app from the **Delivery** section with **Convert to hybrid**. Then, under **Hybrid origin**:

- **Origin URL**: the base URL of your server.
- **Proxy routes**: one pattern per line. The default is `/api/*` and `/_next/data/*`. A trailing `*` matches any path below it.
- **Origin auth secret**: sent as the `X-Dply-Origin-Auth` header on every proxied request. Have your origin reject requests without it, so traffic cannot bypass the edge by going to the origin URL directly. **Rotate origin secret** issues a new one.
- **Origin access token**: a **Client ID** and **Client Secret** to send when the origin sits behind an access gateway, such as a tunnel hostname.
- **Failover HTML**: the page shown when the origin cannot be reached. Blank uses the built-in page.
- **Healthcheck path**: the path **Test origin** requests.

A proxy route is checked only when no file in your build matches the path, so a file in your build always wins over the origin.

### Retries and failover

If the origin cannot be reached, dply retries the request once, whatever its method. If the origin answers with a 5xx, dply retries once for `GET`, `HEAD` and `OPTIONS` only. If the final answer is still a 5xx or no answer, the visitor gets your **Failover HTML** (or the built-in page) with status `503` and `Retry-After: 30`.

> [!NOTE]
> This applies to every 5xx, including an error page your own code returns. A `POST` that your origin answers with `500` reaches the visitor as the failover page, not your error response.

### Caching origin responses

When edge caching is available, dply caches a proxied response on the edge when all of these hold:

- The request is a `GET` and the response is `200`.
- The response has `Cache-Control` with `s-maxage`, or `public` with `max-age`, and not `private` or `no-store`.
- The response has no `Set-Cookie` and is at most 8 MB.

The cache lifetime is the `s-maxage` (or `max-age`), clamped to between 5 seconds and 24 hours. With `stale-while-revalidate`, a stale response is served at once while a fresh one is fetched in the background. See [Caching](/docs/caching) for the cache controls in the workspace.

### Streaming and WebSockets

Responses stream through without being buffered, so Server-Sent Events, chunked responses, large downloads and `Range` requests work unchanged. WebSocket upgrades are forwarded to the origin, which must answer `101`; the `X-Dply-Origin-Auth` header is sent on the upgrade request. Streams and WebSockets are not retried, and there is no failover page for them.

Workers cannot hold a plain HTTP long-poll open indefinitely; use Server-Sent Events or WebSockets for server push. HTTP/2 server push and response trailers are not supported.

## Related

- [Routing, redirects & headers](/docs/routing)
- [Caching](/docs/caching)
- [Edge middleware](/docs/edge-middleware)
- [Deployments](/docs/deployments)
