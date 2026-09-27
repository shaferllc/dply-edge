---
title: "Object storage"
description: "Private file buckets on Cloudflare R2 for uploads and generated files, reached through your app's private host or a Worker binding."
---

Object storage gives your app a bucket for files: user uploads, generated PDFs, exports, and anything else that must outlive a deploy or be shared between instances. Container apps have no shared disk, so files that need to persist belong here. Buckets run on Cloudflare R2.

A bucket is private to your app. dply does not issue S3 access keys or a public bucket URL. Your app reads and writes files, and serves them to visitors itself when it needs to.

## Create a bucket

1. Open your app. On **Overview**, choose **Add resource**, then **Object storage**.
2. Keep **Create new** selected and enter a **Name**, such as `uploads`.
3. Pick a **Location**: where Cloudflare keeps the bucket. It cannot be moved later.
4. Choose **Create**, then redeploy the app.

To use a bucket your organization already has, choose **Attach existing**, pick it under **Existing Bucket**, and choose **Attach**. Several apps can share one bucket.

### Locations

| Location | Value |
| --- | --- |
| Automatic (Cloudflare chooses) | none |
| Western North America | `wnam` |
| Eastern North America | `enam` |
| Western Europe | `weur` |
| Eastern Europe | `eeur` |
| Asia-Pacific | `apac` |
| Oceania | `oc` |

A location is a placement hint. **Add resource** does not offer data jurisdictions (EU or FedRAMP). A bucket that dply creates from your repository's `bindings:` follows your organization's [data region](/docs/data-regions).

## Use it from your code

### Laravel (container apps)

On the next deploy dply sets `DPLY_STORAGE_HOST` and `FILESYSTEM_DISK`, and registers a filesystem disk named after the resource, in lower case. `Storage::put` and friends write to the bucket.

```php
use Illuminate\Support\Facades\Storage;

Storage::disk('uploads')->put('avatars/42.jpg', $bytes);
$bytes = Storage::disk('uploads')->get('avatars/42.jpg');
Storage::disk('uploads')->delete('avatars/42.jpg');

// FILESYSTEM_DISK=uploads makes it the default disk:
Storage::put('exports/report.csv', $csv);
```

If your app has no `AWS_ACCESS_KEY_ID` of its own, the first attached bucket is also registered as the `s3` disk, so existing `Storage::disk('s3')` calls keep working. If you save your own AWS keys under **Environment**, your `s3` disk stays yours, and the bucket is still available by its own name.

> [!IMPORTANT]
> The `dply/laravel` package provides the disk. dply adds it on the next deploy when the app does not require it, but only when dply builds the image. If your repository has its own `Dockerfile`, run `composer require dply/laravel`.

### Rails (container apps)

Add `gem "dply-rails"`. The next deploy sets `DPLY_STORAGE_HOST`.

```ruby
Dply::Rails::Storage.put('avatars/42.jpg', bytes)
Dply::Rails::Storage.get('avatars/42.jpg')
Dply::Rails::Storage.delete('avatars/42.jpg')
```

### Any language (container apps)

The bucket answers at its private host. Copy the exact host from the bucket's sheet. It looks like `dply.my-app.uploads.internal`.

| Request | Result |
| --- | --- |
| `GET http://{host}/` | Lists up to 100 objects as `{"objects": [{"key", "size"}]}`. |
| `GET http://{host}/{path}` | The file, with the content type it was stored with. A missing file is a `404`. |
| `PUT http://{host}/{path}` | Stores the request body. The `content-type` header is saved with it. |
| `DELETE http://{host}/{path}` | Removes the file. |

```js
import { readFile } from 'node:fs/promises';

const host = 'http://dply.my-app.uploads.internal';

await fetch(`${host}/avatars/42.jpg`, {
  method: 'PUT',
  headers: { 'content-type': 'image/jpeg' },
  body: await readFile('photo.jpg'),
});

const file = await fetch(`${host}/avatars/42.jpg`);
const bytes = Buffer.from(await file.arrayBuffer());

await fetch(`${host}/avatars/42.jpg`, { method: 'DELETE' });
```

### Worker apps (SSR and hybrid)

The bucket is an R2 binding at `env.NAME`, where `NAME` is the resource name in upper case.

```js
export default {
  async fetch(request, env) {
    await env.UPLOADS.put('notes/hello.txt', 'hello', {
      httpMetadata: { contentType: 'text/plain' },
    });
    const object = await env.UPLOADS.get('notes/hello.txt');
    if (object === null) return new Response('Not found', { status: 404 });
    return new Response(object.body, {
      headers: { 'content-type': object.httpMetadata?.contentType ?? 'application/octet-stream' },
    });
  },
};
```

## Browse files

Choose **Open** on the bucket's card. The sheet has these tabs:

- **How it works** and **Implementation**: the paths and samples above, with this bucket's names filled in.
- **Files**: lists files, with **Names starting with…** to filter and **Load more** for the next page. Enter an **Object name** and **Contents** and choose **Upload**, **Read**, or **Delete file**. This writes the bucket directly and works before the next deploy. Uploads here must be under 64 KB. Larger files go through your app.
- **Usage**: objects, storage, writes (Class A: upload, list, delete), and reads (Class B: get, head) this month, from Cloudflare, updated every 15 minutes.
- **Costs**: this month's cost so far.

## Pricing

Buckets bill every unit from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Object storage" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Storage | $0.0195 | per GB-month |
| Writes (Class A) | $5.85 | per million |
| Reads (Class B) | $0.468 | per million |

Storage is the bucket's largest size during the month. Usage is collected daily from Cloudflare and billed to your organization, including usage from an app you have since deleted. This bills at the invoice line **Workers CPU, Durable Objects, object storage and images**.

## Delete a bucket

Choose **Delete** on the card, or **Delete bucket** at the bottom of the sheet.

A bucket with files in it cannot be deleted. In **Delete this resource?**, choose **Empty and delete** to remove every file and then the bucket. Each run spends about 20 seconds deleting; if files remain it says "Removed N files. More remain. Run Empty and delete again." Repeat until the bucket is gone. **Empty and delete** only works on buckets your organization created.

If the app's live deploy still uses the bucket, dply detaches it now and deletes it after your next deploy. See [Detach or delete](/docs/resources#detach-or-delete). To remove the bucket from this app but keep its files, choose **Detach**.

> [!WARNING]
> Deleting a bucket deletes every file in it. This cannot be undone.

## Related

- [Resources overview](/docs/resources)
- [Images](/docs/resources/images)
- [Data regions](/docs/data-regions)
- [Usage & metering](/docs/usage)
