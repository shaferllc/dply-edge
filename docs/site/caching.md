---
title: "Caching"
description: "How dply caches static files and app responses at the edge, how to configure cache modes and TTLs, and how to purge."
---

dply caches in two layers. **Static files** from your build are cached automatically with headers set by file type, and each deploy is immutable, so you never need to purge them. **App responses**, from SSR, container and hybrid apps, can be stored in the **edge cache**, which you configure and purge on the **Cache** page.

## Static files

Everything your build outputs (HTML, JavaScript, CSS, images, fonts) is stored with the deploy and served from Cloudflare's network. dply sets `Cache-Control` by path:

| File | `Cache-Control` |
|------|-----------------|
| `index.html` and any `…/index.html` | `public, max-age=0, must-revalidate` |
| Content-hashed assets | `public, max-age=31536000, immutable` |
| Everything else | `public, max-age=3600` |

A file counts as content-hashed when its name carries a build hash, such as `app.3f9a1c2e.js` or Vite's `index-BXa3Kq9z.js`, or when it lives in a framework build folder whose files are all hashed: `_next/static/`, `_astro/` or `_app/immutable/`. Browsers keep these for a year and never re-request them.

To change the defaults for a path, add a header rule in [Routing](/docs/routing#headers). Header rules replace dply's `Cache-Control`.

### ETags and revalidation

Static responses carry a weak `ETag`. When a browser revalidates HTML with `If-None-Match`, dply answers `304 Not Modified` if nothing has changed. The ETag also changes when dply changes what it adds to your HTML, such as [snippets or tags](/docs/snippets), so visitors get the updated page.

### Deploys and old assets

Each deploy is stored separately, so a new deploy never overwrites the files of the previous one. You don't need to purge anything after a deploy. HTML revalidates on every visit, and hashed assets get new names.

For browser tabs still showing a page from an earlier deploy, dply also serves non-HTML files (scripts, styles, images) from the five most recent earlier deploys when the current deploy doesn't have them. Old tabs keep loading their JavaScript chunks instead of failing.

## The edge cache

The edge cache stores responses from your app: SSR renders, container app responses, and hybrid origin routes. The next matching request is then served without waiting on your app. Configure it in your app under **Cache**.

A response is stored only when all of these are true:

- The request is a `GET`.
- The response status is `200`.
- The response has no `Set-Cookie` header.
- The response has a cache lifetime: `s-maxage`, or `public` with `max-age`. Responses marked `private` or `no-store` are never stored.
- The body is 8 MB or smaller.

Cached responses carry `X-Dply-Edge-Cache: HIT` (or `STALE`) and an `Age` header.

### Cache options

The **Cache** page opens with a sentence that says what the edge keeps, for how long, how long browsers keep it, and how many copies are stored right now. Under **How it’s set**, each setting is a row. Click one to change it in a dialog, then choose **Save**. Changes apply on the next request.

**What the edge keeps**:

| Option | Behavior |
|--------|----------|
| **Off** | Nothing from SSR or container apps is stored. |
| **Static assets** | Stores what your app marks cacheable, and also gives file-like paths without their own lifetime (such as `/logo.png` or `/app.js`) the lifetimes below. |
| **Follow cache headers** | Stores only what your app marks cacheable with `Cache-Control`. |
| **All public pages** | Stores what your app marks cacheable, and also gives every other `GET` 200 without its own lifetime, HTML included, the lifetimes below. |

Pages that set a session cookie are never stored.

> [!NOTE]
> Until you save a cache setting for the first time, the edge cache is off for SSR and container apps. The page says so in amber. Open any row and choose **Save** once to turn it on.

**How long the edge keeps a copy**: used when your response doesn't set its own lifetime. Options run from 1 minute to 1 year. The edge cache keeps entries for at most 1 day, so longer values act as 1 day, and the page shows 1 day.

**How long browsers keep a copy**: the `max-age` sent to browsers for responses dply stores with the lifetime above. **Revalidate each visit** sends `max-age=0`. Browser copies can't be purged, so keep this short for anything that changes without a new file name.

**Query strings**: **Ignore them** stores `/page?a=1` and `/page?a=2` as one copy. **Include them in the key** stores them separately. Query parameters are sorted, so their order doesn't matter.

### Setting cache lifetimes from your app

Your app controls caching with standard headers:

```http
Cache-Control: public, s-maxage=300, stale-while-revalidate=60
```

- `s-maxage` sets how long the edge cache keeps the response. `max-age` is used when `public` is present and there's no `s-maxage`.
- `stale-while-revalidate=N` serves a stale copy for up to `N` more seconds while dply refreshes it in the background. This applies to hybrid origin routes.
- Lifetimes under 5 seconds aren't stored. Lifetimes over 1 day are capped at 1 day.

### Hybrid origin routes

For hybrid apps, responses from origin routes (for example `/api/*`) are stored whenever the origin marks them cacheable, whatever **What the edge keeps** is set to. See [Static & hybrid sites](/docs/static-and-hybrid).

## Purge the edge cache

On the **Cache** page you can drop stored copies so the next request fetches fresh from your app. Purging affects only the edge cache. It doesn't clear visitors' browser caches, and static files never need it.

- **See the stored copies** opens what's currently cached, with each entry's expiry. Choose **Purge** on a row to drop it.
- **Purge one path or a cache tag** opens a dialog. **One path** drops the stored copy for a URL path, such as `/pricing`; with query strings included in the key, it drops only the copy with no query string. **A cache tag** drops the latest copy stored under a tag (see below).
- **Clear everything the edge stored** drops every stored copy for the app, after you confirm.

Purges are recorded in the [activity log](/docs/activity-log).

### Cache tags

Tag responses from your app with `Cache-Tag` (or `X-Dply-Cache-Tag`), with comma-separated names made of letters, digits, `.`, `-` and `_`:

```http
Cache-Tag: product-42, catalog
```

Then purge by tag from the **Cache** page, the CLI or the API:

```bash
dply edge purge --tag catalog
```

```http
POST /api/v1/edge/sites/{site}/cache/purge
Content-Type: application/json

{"tag": "catalog"}
```

The API also accepts `{"paths": ["/pricing", "/about"]}` (up to 100 paths). See the [HTTP API](/docs/api).

> [!NOTE]
> A tag points at the most recently stored response with that tag. Purging a tag drops that response. Older responses with the same tag stay until their TTL expires. For a full reset, use **Clear everything the edge stored**.

## Related

- [Edge network](/docs/edge-network)
- [Routing, redirects & headers](/docs/routing)
- [Server rendering (SSR)](/docs/server-rendering)
- [Container apps](/docs/containers)
