---
title: "Images"
description: "Resize and convert images from your app's code with the Images resource, or serve resized images from signed URLs with Delivery image optimization."
---

dply has two ways to resize and reformat images. They are separate features:

- **The Images resource** lets your app's server code send an image and get back its details or a resized, converted copy. Use it when your app processes uploads, such as making thumbnails or converting to WebP before storing them.
- **Image optimization** (under **Delivery**) serves resized copies of images that already live at a public URL, through signed `/_dply/image` URLs on your site's own domain. Use it for `<img>` tags on static, SSR, and hybrid sites.

## The Images resource

> [!NOTE]
> Available on any paid plan. Not included in the trial.

### Turn it on

1. Open your app. On **Overview**, choose **Add resource**, then **Images**.
2. There is nothing to name or create. The resource is added right away and its sheet opens, with the address, the two calls, and code you can copy.
3. Redeploy the app.

An app has at most one Images resource, so **Images** no longer shows under **Add resource** once it is added. Select the **Images** box on **Overview** to open its sheet again.

During the trial, **Images** is greyed out with "Needs a paid plan. Not included in the trial."

### What it does

| Option | Values |
| --- | --- |
| `width`, `height` | Pixels, up to 8000 |
| `fit` | `scale-down`, `contain`, `cover`, `crop`, `pad` |
| `format` | `jpeg`, `png`, `webp`, `avif`, `gif`. A call with no format returns `webp`. |
| `quality` | 1 to 100 |

An image can be up to 20 MB.

### Container apps

POST the image bytes to the app's private Images host. Select the **Images** box on **Overview** to see the exact host, and choose **Copy** next to it.

- `POST http://{host}/info` returns the image's format, width, height, and file size as JSON.
- `POST http://{host}/?width=800&format=webp` returns the new image.

```php
use Illuminate\Support\Facades\Http;

$host = 'http://dply.my-app.images.internal';
$bytes = file_get_contents($path);

$info = Http::withBody($bytes, 'application/octet-stream')->post("{$host}/info")->json();

$webp = Http::withBody($bytes, 'application/octet-stream')
    ->post("{$host}/?width=800&height=600&fit=cover&format=webp&quality=80")
    ->body();
```

```js
import { readFile, writeFile } from 'node:fs/promises';

const host = 'http://dply.my-app.images.internal';
const bytes = await readFile('photo.jpg');

const info = await (await fetch(`${host}/info`, { method: 'POST', body: bytes })).json();

const res = await fetch(`${host}/?width=800&format=webp&quality=80`, { method: 'POST', body: bytes });
await writeFile('photo.webp', Buffer.from(await res.arrayBuffer()));
```

### Try it on your app

**Build a call** in the sheet turns the options you pick into code. Choose **Resize** or **Details**, then **curl**, **Laravel** or **Rails** (Laravel and Rails show when dply detects that framework), and **Copy**.

**Run it on the app** sends a sample picture through your live app's Images resource with those options. For **Resize** it shows the original and the returned picture side by side, with the round-trip time, both sizes and how much smaller it got. For **Details** it shows the JSON your app would get. The demo runs on container apps and needs a deploy made after Images was added. On an older deploy it says so. You need permission to edit the app. Each run is one transformation and is billed like any other.

### Worker apps (SSR and hybrid)

The resource is Cloudflare's Images binding at `env.IMAGES`.

```js
export default {
  async fetch(request, env) {
    const upload = await request.arrayBuffer();
    const info = await env.IMAGES.info(new Response(upload).body);

    const result = await env.IMAGES
      .input(new Response(upload).body)
      .transform({ width: 800, fit: 'scale-down' })
      .output({ format: 'image/webp', quality: 80 });

    return result.response();
  },
};
```

### Pricing

Bills every transformation from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Images" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Transformations | $0.65 | per 1,000 |

Usage is collected daily and billed to your organization. This bills at the invoice line **Workers CPU, Durable Objects, object storage and images**.

### Remove it

Open the **Images** box on **Overview** and choose **Remove from this app** under **Turn off**, then **Remove**. The app loses Images on the next deploy. There is nothing stored to lose.

## Image optimization (Delivery)

Image optimization adds a `/_dply/image` endpoint to your site's own hostname. It fetches a source image from a host you allow, resizes and converts it, and returns it with long cache headers. URLs must be signed, so nobody can use your site as an open image proxy.

It is available on static, SSR, and hybrid sites. Container apps do not have the **Delivery** section; use the Images resource instead.

### Turn it on

1. Open your site and go to **Delivery**.
2. Under **Image optimization**, check **Enable**.
3. Under **Allowed source hostnames**, enter each host your source images live on, one per line, such as `images.example.com`. These merge with any declared in `dply.yaml`.
4. Choose **Save images**.

dply generates a signing secret. Open **Images advanced** to see it under **Signing secret**, with **Copy** and **Rotate**. Rotating the secret breaks every URL signed with the old one. **Test image** requests a signed sample URL and shows the result.

You can also declare allowed hosts in your repository. The signing secret stays in the dashboard.

```yaml
images:
  allowed_hosts:
    - "images.example.com"
    - "cdn.example.com"
```

### Build a signed URL

```http
GET https://{your-site}/_dply/image?fmt=auto&q=75&url=https://images.example.com/hero.jpg&w=800&sig={hmac}
```

| Parameter | Required | Notes |
| --- | --- | --- |
| `url` | Yes | Absolute `http` or `https` URL. Its host must be allowed. |
| `w` | No | Width, 1 to 4096. |
| `q` | No | Quality, 1 to 100. |
| `fmt` | No | `auto` (default), `avif`, `webp`, `jpeg`, or `png`. |
| `sig` | Yes | Hex HMAC-SHA256 of the other parameters, keyed with the signing secret. |

To compute `sig`: take every parameter except `sig`, sort by name, join as `name=value` pairs with `&` using the plain (not URL-encoded) values, and HMAC-SHA256 the result with the signing secret. Sign on your server. Never send the secret to the browser.

```php
function signedImageUrl(string $site, string $secret, string $src, int $width, int $quality = 75): string
{
    $params = ['fmt' => 'auto', 'q' => (string) $quality, 'url' => $src, 'w' => (string) $width];
    ksort($params);
    $canonical = implode('&', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($params), $params));
    $params['sig'] = hash_hmac('sha256', $canonical, $secret);

    return "https://{$site}/_dply/image?".http_build_query($params);
}
```

```js
import { createHmac } from 'node:crypto';

export function signedImageUrl(site, secret, src, width, quality = 75) {
  const params = { fmt: 'auto', q: String(quality), url: src, w: String(width) };
  const canonical = Object.keys(params).sort().map((k) => `${k}=${params[k]}`).join('&');
  const sig = createHmac('sha256', secret).update(canonical).digest('hex');
  return `https://${site}/_dply/image?` + new URLSearchParams({ ...params, sig });
}
```

Store the secret as an environment variable in the app that signs URLs.

### Responses

Resized images are served with `Cache-Control: public, max-age=86400, s-maxage=86400, immutable`. To change an image, change its source URL.

| Status | Meaning |
| --- | --- |
| `400` | Missing `url` or `sig`, or the source URL is not valid `http(s)`. |
| `403` | Bad signature, or the source host is not allowed. |
| `404` | Image optimization is not enabled for this site. |
| `502` | The source could not be fetched or returned an error. |

Image optimization has no plan requirement and is not currently metered.

## Related

- [Resources overview](/docs/resources)
- [Object storage](/docs/resources/object-storage)
- [Caching](/docs/caching)
- [Configuration files](/docs/configuration-files)
