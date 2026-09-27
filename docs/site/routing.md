---
title: "Routing, redirects & headers"
description: "Add redirects, rewrites and response headers to your app, from the dashboard or from dply.yaml, without rebuilding."
---

Routing rules run at the edge, before a request reaches your files or your app. Use them to move old URLs with redirects, to serve a different path or proxy to another origin with rewrites, and to add response headers, such as security or cache headers, by path.

You can define rules in two places:

- **Dashboard.** Open your app, choose **Routing**, then the **Redirects**, **Rewrites** or **Headers** tab. Changes apply within about a minute, with no rebuild.
- **`dply.yaml`.** Commit rules in your repository. They apply on the next deploy, and the dashboard shows them as read-only rows under **From dply.yaml**.

When both exist, repository rules come first and dashboard rules follow.

## Path patterns

All three rule types use the same patterns:

| Pattern | Matches |
|---------|---------|
| `/about` | `/about` exactly |
| `/blog/*` | `/blog/` and everything under it |
| `/*` | every path |

`*` is only allowed at the end of a pattern. The part of the path it matched is available in the destination as `:splat`:

```text
/docs/*   →   /help/:splat
/docs/getting-started   →   /help/getting-started
```

> [!NOTE]
> Rules are matched after directory URLs resolve to their index file. A request for `/` is matched as `/index.html`, and a request for `/blog/` as `/blog/index.html`. To match a directory URL, use a wildcard (`/blog/*`) or the index path (`/blog/index.html`).
>
> On apps with SPA fallback on, a route that has no file of its own (such as `/pricing` in a single-page app) is served from `/index.html`, and header rules see it as `/index.html`. Redirects and rewrites still see the original path.

## Redirects

A redirect sends the visitor's browser to a new URL.

1. Open **Routing**, then **Redirects**.
2. Enter **From** (it must start with `/`) and **To** (a path or a full URL).
3. Pick a **Status**: `301`, `302`, `307` or `308`.
4. Choose **Add**.

Redirects run before anything else in routing. The first matching rule wins. Redirect responses are sent with `Cache-Control: no-cache`, so a changed rule takes effect for returning visitors.

### Import redirects in bulk

Under **Import in bulk**, paste many rules at once, one per line, in either format:

```text
/old-page,/new-page,301
example.com/blog/,https://example.com/news/,301
```

```text
/docs/*  /help/:splat  301
```

The first format is a CSV of `source_url,target_url,status_code`. The second is a Netlify `_redirects` block. The host in a source URL is dropped, because rules are always scoped to this app. dply skips rules whose **From** already exists and tells you how many it imported and skipped.

## Rewrites

A rewrite serves different content without changing the URL in the browser.

- **To a path** (`/app/*` → `/index.html`): dply serves that file instead.
- **To a full URL** (`/api/*` → `https://api.example.com/:splat`): dply proxies the request to that origin and returns its response.

Add one under **Routing** → **Rewrites** with **From** and **To**, then choose **Add**. The first matching rewrite wins.

> [!NOTE]
> Rewrites apply to static and hybrid apps. For SSR and container apps, requests go straight to your app after redirects, and your framework's own routing handles rewrites.

## Headers

Header rules add or replace response headers on matching paths.

1. Open **Routing**, then **Headers**.
2. Enter a **Path pattern**, such as `/assets/*`.
3. Enter **Headers (one per line, Name: value)**:

   ```text
   Cache-Control: public, max-age=31536000, immutable
   X-Robots-Tag: noindex
   ```

4. Choose **Add rule**.

Every matching header rule applies, in order. A later rule overwrites a header set by an earlier one. Header rules apply to responses from every app type, including SSR and container apps.

### Default security headers

Static files are served with these headers unless a header rule overrides them:

```http
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
Referrer-Policy: strict-origin-when-cross-origin
X-XSS-Protection: 0
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

For SSR and container apps, your app sets its own headers.

## Templates

Each tab has a **Templates** list under **Advanced** that adds a set of rules in one click. You can then edit or remove the rules it added.

| Template | Adds |
|----------|------|
| **Security headers** | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Strict-Transport-Security` on `/*` |
| **Long-cache static assets** | `Cache-Control: public, max-age=31536000, immutable` on `/assets/*` |
| **Proxy /api/* to an upstream** | A rewrite from `/api/*` to `https://api.example.com/:splat`. Edit the destination. |
| **Blog URL migration (301)** | Redirects `/old-page` → `/new-page` and `/blog/*` → `/news/:splat` |

## Rules in `dply.yaml`

```yaml
redirects:
  - from: /old-page
    to: /new-page
    status: 301
  - from: /blog/*
    to: /news/:splat
    status: 308

rewrites:
  - from: /api/*
    to: https://api.example.com/:splat
  - from: /app/*
    to: /index.html

headers:
  - for: /*
    values:
      Strict-Transport-Security: "max-age=31536000; includeSubDomains"
  - for: /assets/*
    values:
      Cache-Control: "public, max-age=31536000, immutable"
```

Repository redirects accept status `301`, `302`, `303`, `307` or `308`. Any other status becomes `301`, with a warning in the deploy log. Validate the file locally with `dply edge lint`. Choose **Generate dply.yaml** under **Advanced** to download your current dashboard rules as a starting file.

## Order of evaluation

For each request, dply applies:

1. Maintenance mode ([Error pages](/docs/error-pages)), then the [Firewall](/docs/firewall).
2. [Waiting room](/docs/waiting-room), [Forms](/docs/forms) and [Rate limits](/docs/rate-limits).
3. [Access control](/docs/access-control), if protection is on.
4. [Edge middleware](/docs/edge-middleware), if your app has it.
5. Redirects.
6. Rewrites (static and hybrid apps).
7. Your files, your hybrid origin, or your SSR or container app.
8. Header rules, on the response.

## Related

- [Domains](/docs/domains)
- [Caching](/docs/caching)
- [Configuration files](/docs/configuration-files)
- [Static & hybrid sites](/docs/static-and-hybrid)
