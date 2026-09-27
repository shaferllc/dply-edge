---
title: "Key-value (KV)"
description: "A globally replicated key-value store for caches, sessions, and settings, billed per read, write, and GB stored."
---

A key-value store keeps short values under string keys, replicated across Cloudflare's network so reads are fast wherever your app runs. Use it for cache entries, feature flags, rendered fragments, and other data that is read far more often than it is written. It runs on Cloudflare Workers KV.

KV is eventually consistent: a write can take a short time to be seen everywhere. For a counter or a lock that must be exact, use [State](/docs/resources/state). For a Redis-compatible cache with atomic commands, use [Valkey (Redis)](/docs/resources/valkey).

> [!NOTE]
> You need a card on file to create a key-value store. The 5-day trial takes a card up front, so trial organizations can create one. An organization on an older trial without a card must add one first. Without a card, the **Create new** form shows "Add a card before starting a key-value store. Reads, writes, and storage are billed to that card." and a **Billing** button.

## Create a store

1. Open your app. On **Overview**, choose **Add resource**, then **Key-value store**.
2. Keep **Create new** selected and enter a **Name**, such as `cache`.
3. Choose **Create**, then redeploy the app.

To use a store your organization already has, choose **Attach existing**, pick it under **Existing Store**, and choose **Attach**.

Key-value stores are available to container apps and to SSR and hybrid apps. See [which kinds each runtime supports](/docs/resources#which-kinds-each-runtime-supports).

## Use it from your code

### Laravel (container apps)

On the next deploy dply sets `DPLY_KV_HOST` and registers a cache store named after the resource, in lower case. If no Redis is attached, it also sets `CACHE_STORE` to that store, so the default `Cache` facade uses it.

```php
use Illuminate\Support\Facades\Cache;

Cache::store('cache')->put('session', 'hello', 3600);
Cache::store('cache')->get('session');
Cache::store('cache')->forget('session');

// With CACHE_STORE set by dply, the default store is the same:
Cache::put('greeting', 'hello', 3600);
```

A value expires only when its TTL is at least 60 seconds. A shorter TTL stores the value with no expiry.

> [!IMPORTANT]
> The `dply/laravel` package provides the cache store. dply adds it on the next deploy when the app does not require it, but only when dply builds the image. If your repository has its own `Dockerfile`, run `composer require dply/laravel`.

### Rails (container apps)

Add `gem "dply-rails"`. The next deploy sets `DPLY_KV_HOST`, and `Rails.cache` uses the store when no Redis is attached.

```ruby
Rails.cache.write('session', 'hello')
Rails.cache.read('session')
Rails.cache.delete('session')
```

### Any language (container apps)

The store answers at its private host. Copy the exact host from the store's sheet. It looks like `dply.my-app.cache.internal`.

| Request | Result |
| --- | --- |
| `GET http://{host}/` | Lists up to 100 keys as `{"keys": [...]}`. |
| `GET http://{host}/{key}` | The value as text. A missing key is a `404`. |
| `PUT http://{host}/{key}` | Stores the request body. Send `x-dply-ttl: {seconds}` (60 or more) to expire it. |
| `DELETE http://{host}/{key}` | Removes the key. |

```js
const host = 'http://dply.my-app.cache.internal';

await fetch(`${host}/session`, {
  method: 'PUT',
  body: 'hello',
  headers: { 'x-dply-ttl': '3600' },
});

const res = await fetch(`${host}/session`);
const value = res.status === 404 ? null : await res.text();

await fetch(`${host}/session`, { method: 'DELETE' });
```

### Worker apps (SSR and hybrid)

The store is a KV namespace binding at `env.NAME`, where `NAME` is the resource name in upper case.

```js
export default {
  async fetch(request, env) {
    await env.CACHE.put('session', 'hello', { expirationTtl: 3600 });
    const value = await env.CACHE.get('session');
    await env.CACHE.delete('session');
    return new Response(value ?? 'missing');
  },
};
```

## Browse and edit keys

Choose **Settings** on the store's card. The sheet has these tabs:

- **How it works**: the HTTP paths above.
- **Implementation**: Laravel, Rails, and HTTP samples with this store's names filled in.
- **Keys**: lists keys, with **Keys starting with…** to filter and **Load more** for the next page. Under **Try a key**, enter a **Key**, a **Value**, and optionally **Expire after (seconds)** (at least 60), then choose **Write**, **Read**, or **Delete key**. This writes the store directly and does not call your app. Demo values must be under 8 KB.
- **Usage**: reads, writes, deletes, and lists this month, and storage.
- **Costs**: this month's cost so far.
- **Settings**: rename the store's binding. The app uses the new host and name after the next deploy. The store keeps its data.

## Sleep

Choose **Sleep** on the card to take the store off the app. On the next deploy the app loses the address and cannot read or write it. The store keeps its data, and that data is still billed as storage; operations until the next deploy are billed too. Delete the store to stop all charges. Choose **Wake** and redeploy to bring it back.

## Pricing

Key-value stores are billed per operation and for storage, every unit from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Key-value" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Reads | $0.60 | per million |
| Writes, deletes and lists | $6.00 | per million |
| Storage | $0.60 | per GB-month |

Storage is the store's largest size during the month. Usage is collected hourly from Cloudflare, and each day is collected again in full the next morning. A sleeping store is billed for what it uses: its storage, and any operations before the next deploy takes it off the app. This bills at the invoice line **SQL, queues and key-value**.

## Delete a store

Choose **Delete** on the card, or **Delete store** under **Settings**. This removes the store and every key in it. It cannot be undone.

If the app's live deploy still uses the store, dply detaches it now and deletes it after your next deploy. If another app still uses it, it is kept, and billed, until no app does: redeploy this app, detach or delete it on the other app too, and it is deleted after the next deploy. See [Detach or delete](/docs/resources#detach-or-delete). To remove the store from this app but keep it, choose **Detach** instead.

## Related

- [Resources overview](/docs/resources)
- [State (Durable Objects)](/docs/resources/state)
- [Valkey (Redis)](/docs/resources/valkey)
- [Usage & metering](/docs/usage)
