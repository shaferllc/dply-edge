---
title: "Browser rendering"
description: "Give your app a headless browser to fetch rendered pages, take screenshots, and make PDFs."
---

Browser rendering gives your app its own headless Chrome. Use it to take screenshots of pages, turn pages into PDFs, or fetch the HTML of a page after its JavaScript has run. Each app gets its own browser address, so no other app can use it.

> [!NOTE]
> Available on paid plans. Not included in the trial.

## Turn on the browser

1. In your app, open **Resources**.
2. Choose **Add a browser**, then **Turn on** on the **Browser** sheet.
3. Choose **Deploy** on the sheet, or deploy the app yourself.

The browser works after the next deploy. The **Browser** row on the app card shows **On**.

## Use it

### Container apps

Post `{"url": "https://example.com"}` to one of three paths on the app's browser host, shown on the **How it works** tab:

| Path | Returns |
|---|---|
| `/content` | The page's HTML after it has rendered |
| `/screenshot` | A PNG of the page |
| `/pdf` | A PDF of the page |

```php
$png = Http::post('http://dply.<app>.internal/screenshot', [
    'url' => 'https://example.com',
])->body();

Storage::put('screenshots/example.png', $png);
```

```js
const res = await fetch('http://dply.<app>.internal/pdf', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ url: 'https://example.com' }),
});
const pdf = Buffer.from(await res.arrayBuffer());
```

```bash
curl -X POST http://dply.<app>.internal/screenshot \
  -H 'content-type: application/json' \
  -d '{"url":"https://example.com"}'
```

Only this app can reach its browser host.

### SSR and hybrid apps (Workers)

Your code reads the browser as `env.BROWSER`. Use it with `@cloudflare/puppeteer`:

```ts
import puppeteer from '@cloudflare/puppeteer';

export default {
  async fetch(request, env) {
    const browser = await puppeteer.launch(env.BROWSER);
    const page = await browser.newPage();
    await page.goto('https://example.com');
    const png = await page.screenshot();
    await browser.close();
    return new Response(png, { headers: { 'content-type': 'image/png' } });
  },
};
```

The name `BROWSER` is reserved for this binding.

## Try it from the dashboard

The **Demo** section on the browser sheet opens a public **Page address** from the dashboard. Choose **Show page**, **Show picture**, or **Show PDF** to see the same three results. It does not call your app.

## Pricing

dply does not meter or bill browser rendering per organization today. It is included with paid plans.

On a trial, or if the organization leaves its paid plan, the browser is left out of the app's next deploy.

## Remove the browser

Open the browser sheet and choose **Remove**, then **Remove browser**. After the next deploy the app can no longer open pages, take screenshots, or make PDFs.

## Related

- [Workers AI](/docs/resources/ai)
- [Container apps](/docs/containers)
- [Server rendering (SSR)](/docs/server-rendering)
