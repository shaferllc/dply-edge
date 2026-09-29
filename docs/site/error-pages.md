---
title: "Error pages"
description: "Brand your app's 404 and 500 pages and take it offline with maintenance mode, all served at the edge without a rebuild."
---

Error pages let you replace dply's default 404 and 500 responses with your own HTML, and turn on maintenance mode, which answers every request with a 503 page until you turn it off. Everything is served from the edge, so changes apply on the next request with no rebuild or redeploy.

## Edit error pages

1. Open your app and choose **Error pages**. Each page (404, 500 and maintenance) is listed with its source: **Custom**, **From dply.yaml** or **Built-in**.
2. Click a page. Paste full HTML, or start from **Minimal**, **Friendly** or **Enterprise**, which fill just that page. A live preview shows the result beside the editor; scripts don't run in the preview.
3. Choose **Save**. The new page is live on the next request, with no redeploy.

To go back to dply's default, open the page and choose **Use the built-in page**. **Cancel** discards unsaved edits.

Each page can be up to 200,000 characters.

> [!TIP]
> Keep error pages self-contained, with inline CSS and no external scripts or fonts. They're shown when something is wrong, and links back into your app may be what's failing.

## When each page is shown

### 404 page

Shown with status `404` when a request doesn't match a file in your deploy, for static and hybrid apps.

> [!NOTE]
> With SPA fallback on (the default for new apps), a missing path serves your `index.html` with status `200` instead, so your front-end router can handle it, and the 404 page isn't shown. Turn SPA fallback off in **Build** if you want real 404s. For SSR and container apps, your app returns its own 404s.

Without a custom 404 page, dply returns a plain-text `Object not found.`

### Blocked-country page

Shown with status `403` when your [Firewall](/docs/firewall) turns a visitor away by country. Without one, visitors get the plain-text `Forbidden — content is not available in this region (XX).` This page is set in the dashboard only; `dply.yaml` has no key for it.

### 500 page

- **SSR and container apps**: when your app returns a `5xx` HTML response, dply replaces the body with your 500 page (or dply's default error page) and keeps the status code. JSON and other non-HTML error responses pass through unchanged.
- **All apps**: shown if dply's edge itself hits an unexpected error while handling the request. Without a custom 500 page, visitors see dply's default error page. The error details are logged for dply, never shown to visitors.

To see your app's own error page while debugging, have your app send the response header `x-dply-app-debug: 1`. dply then passes the response through unchanged and removes the header.

For a hybrid app's origin, the **Failover HTML** under **Delivery** is shown instead when the origin is down or times out. See [Static & hybrid sites](/docs/static-and-hybrid).

### Maintenance mode

Turn on **Maintenance mode** to answer every request with your maintenance page (or dply's default "We'll be right back." page):

```http
HTTP/1.1 503 Service Unavailable
Retry-After: 120
Cache-Control: no-store, max-age=0
```

Maintenance mode runs before every other rule, including the [firewall](/docs/firewall) and [access control](/docs/access-control). The checkbox takes effect right away. Turn it off to bring the app back.

> [!NOTE]
> If your organization is paused for billing, dply shows a billing notice in place of your app in the same way. Your own maintenance setting is left unchanged. See [Paused accounts](/docs/paused-accounts).

## Error pages in `dply.yaml`

Declare pages inline or as files in your repository:

```yaml
error_pages:
  html_404_path: public/404.html
  html_500_path: public/500.html

maintenance:
  enabled: false
  html_path: public/maintenance.html
```

Use `html_404`, `html_500` and `maintenance.html` for inline HTML instead of paths. Paths are relative to the repository root and are read at build time.

When both are set:

- For the 404, 500 and maintenance HTML, the dashboard value is used.
- Maintenance mode is on if **either** the dashboard or the repository turns it on. If `maintenance.enabled: true` is in your repository, you have to change the file and redeploy to turn maintenance off.

The **Advanced** section of the **Error pages** page shows what your repository declares.

## Related

- [Routing, redirects & headers](/docs/routing)
- [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime)
- [Waiting room](/docs/waiting-room)
