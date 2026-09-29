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

`Cache::many()` reads up to 100 keys in one request, and `Cache::flush()` removes every key in the store.

> [!WARNING]
> `Cache::increment()` and `Cache::decrement()` throw an error on a key-value store, and so do `Cache::lock()` and anything built on them, such as rate limiting and `WithoutOverlapping`. The store is eventually consistent, so it can't count or lock safely. Attach [Valkey (Redis)](/docs/resources/valkey) or use [State](/docs/resources/state) for those.

> [!IMPORTANT]
> The `dply/laravel` package provides the cache store. dply adds it on the next deploy when the app does not require it, but only when dply builds the image. If your repository has its own `Dockerfile`, run `composer require dply/laravel`.

### Rails (container apps)

Add `gem "dply-rails"`. The next deploy sets `DPLY_KV_HOST`, and `Rails.cache` uses the store when no Redis is attached. `read_multi` reads up to 100 keys in one request, `clear` removes every key, and `increment` / `decrement` raise, as in Laravel.

```ruby
Rails.cache.write('session', 'hello')
Rails.cache.read('session')
Rails.cache.delete('session')
```

### Any language (container apps)

The store answers at its private host. Copy the exact host from the store's sheet. It looks like `dply.my-app.cache.internal`. The host belongs to this app: only this app's containers can reach it, and it needs no credentials. The same store attached to another app has a different host there. To reach a store from anywhere else, see [From outside the app](#from-outside-the-app).

| Request | Result |
| --- | --- |
| `GET http://{host}/` | Lists up to 1,000 keys as `{"keys": [...], "cursor": ...}`. Add `?prefix=` to filter, and pass `?cursor=` from the previous page for the next one. `cursor` is `null` on the last page. Add `&detail=1` to get `{"name", "expiration", "metadata"}` for each key instead of just its name. |
| `POST http://{host}/` | Reads up to 100 keys at once. Send `{"keys": ["a", "b"]}` and get `{"values": {"a": "…", "b": null}}` back. A missing key is `null`. |
| `GET http://{host}/{key}` | The value as text. A missing key is a `404`. The key's metadata, if any, comes back in an `x-dply-metadata` header. |
| `PUT http://{host}/{key}` | Stores the request body. |
| `DELETE http://{host}/{key}` | Removes the key. |

A `PUT` takes these optional headers:

| Header | Value |
| --- | --- |
| `x-dply-ttl` | Seconds until the key expires, 60 or more. A shorter value stores it with no expiry. |
| `x-dply-expires-at` | A unix time at least 60 seconds ahead, when the key expires. Send this or `x-dply-ttl`, not both. |
| `x-dply-metadata` | JSON of up to 1024 bytes kept alongside the value. It comes back when you read the key or list with `detail=1`. |

A `GET` for a key can send `x-dply-cache-ttl: {seconds}` (30 or more) to let each location cache the value for that long. Reads get faster, but a change can take that long to show. A header that breaks these rules gets a `400` that says which rule.

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
- **Keys**: lists keys, with each key's expiry and metadata under its name. Use **Keys starting with…** to filter and **Load more** for the next page. Choose a key to load it and its value into **Try a key**, change the **Value**, and choose **Write** to save it. Under **Try a key** you can also enter a **Key**, a **Value**, and optionally **Expire after (seconds)** (at least 60), then choose **Write**, **Read**, or **Delete key**. This writes the store directly and does not call your app. Demo values must be under 8 KB, and a larger value isn't loaded into the field.
- **Usage**: reads, writes, deletes, and lists this month, and storage.
- **Costs**: this month's cost so far.
- **Settings**: rename the store's binding. The app uses the new host and name after the next deploy. The store keeps its data.

### Delete keys by prefix

1. On **Keys**, search for a prefix, such as `user:`.
2. Choose **Delete keys starting with user:**. The sheet shows how many keys match, or "1,000+" when there are more than one page.
3. Type the prefix to confirm, then choose **Delete keys**.

The keys are deleted in the background, a page of 1,000 at a time, and the sheet shows how many have gone so far. Only one delete runs on a store at a time. It cannot be undone. To remove every key, delete the store instead: an empty prefix is not allowed.

## From outside the app

The private host only works inside the app. To seed, inspect or fix keys from your machine or CI, use the [HTTP API](/docs/api/reference#key-value-stores) or the CLI with an API token:

```bash
dply kv list
dply kv keys cache --prefix user:
dply kv get cache user:1
dply kv put cache user:1 hello --ttl 3600 --metadata '{"plan":"pro"}'
dply kv delete cache user:1
```

Reading needs `edge.read`, and writing or deleting needs `edge.write`. Everything except listing stores also needs you to be an organization owner or admin. These calls share **60 requests per minute per organization** across all its tokens. Use them for admin work, not app traffic.

## Sleep

Choose **Sleep** on the card to take the store off the app. On the next deploy the app loses the address and cannot read or write it. The store keeps its data, and that data is still billed as storage; operations until the next deploy are billed too. Delete the store to stop all charges. Choose **Wake** and redeploy to bring it back.

## Pricing

Key-value stores are billed per operation and for storage, every unit from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Key-value" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Reads | $0.65 | per million |
| Writes, deletes and lists | $6.50 | per million |
| Storage | $0.65 | per GB-month |

Storage is the store's largest size during the month. Usage is collected hourly from Cloudflare, and each day is collected again in full the next morning. A sleeping store is billed for what it uses: its storage, and any operations before the next deploy takes it off the app. This bills at the invoice line **SQL, queues and key-value**.

## Delete a store

Choose **Delete** on the card, or **Delete store** under **Settings**. This removes the store and every key in it. It cannot be undone.

If the app's live deploy still uses the store, dply detaches it now and deletes it after your next deploy. If another app still uses it, it is kept, and billed, until no app does: redeploy this app, detach or delete it on the other app too, and it is deleted after the next deploy. See [Detach or delete](/docs/resources#detach-or-delete). To remove the store from this app but keep it, choose **Detach** instead.

## Related

- [Resources overview](/docs/resources)
- [State (Durable Objects)](/docs/resources/state)
- [Valkey (Redis)](/docs/resources/valkey)
- [Usage & metering](/docs/usage)
