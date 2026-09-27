---
title: "State (Durable Objects)"
description: "Small, strongly consistent values for counters, locks, and flags, stored in one Durable Object per app."
---

State keeps small values that stay put between requests and deploys: a counter, a lock, a flag, a rate-limit bucket. Every call goes to one place, in order, so two requests never both win. It runs on a Cloudflare Durable Object.

Use State when a value must be exact. A [key-value store](/docs/resources/key-value) is faster to read from everywhere but is eventually consistent, so two requests that increment the same KV key can lose an update. State cannot.

State is available on every plan, including the trial, on container apps and on SSR and hybrid apps.

## Add State

1. Open your app. On **Overview**, choose **Add resource**, then **State**.
2. Enter a **Name**, such as `visits`.
3. Choose **Attach**, then redeploy the app.

State is not created or shared like a bucket: it belongs to the app. Every State you add to one app shares the same set of keys. Adding two gives you two names for the same store. The data outlives deploys.

## Use it from your code

State answers the same paths on every runtime:

| Request | Result |
| --- | --- |
| `GET /` | Lists up to 100 keys as `{"keys": [...]}`. |
| `GET /{key}` | The value as text. A missing key is a `404`. |
| `PUT /{key}` | Stores the request body as text. |
| `DELETE /{key}` | Removes the key. |
| `POST /incr/{key}` | Adds one and returns the new number. A missing key starts at 0. |

### Container apps

Call the private host. Copy the exact host from the State sheet's **Connect** tab. It looks like `dply.my-app.visits.internal`.

```php
use Illuminate\Support\Facades\Http;

Http::withBody('hello', 'text/plain')->put('http://dply.my-app.visits.internal/greeting');
$value = Http::get('http://dply.my-app.visits.internal/greeting')->body();
$visits = (int) Http::post('http://dply.my-app.visits.internal/incr/visits')->body();
```

```js
const host = 'http://dply.my-app.visits.internal';

await fetch(`${host}/greeting`, { method: 'PUT', body: 'hello' });
const value = await (await fetch(`${host}/greeting`)).text();
const visits = Number(await (await fetch(`${host}/incr/visits`, { method: 'POST' })).text());
```

### Worker apps (SSR and hybrid)

Call `env.NAME.fetch()` with a path. The host in the URL does not matter.

```js
export default {
  async fetch(request, env) {
    const n = await (await env.VISITS.fetch('https://state/incr/visits', { method: 'POST' })).text();
    return new Response('Visit #' + n);
  },
};
```

The raw Durable Object namespace is available as `env.NAME.namespace` if you need it.

### Try it

State lives inside the running app and only the app can reach it, so the dashboard cannot read or write it. The sheet's **Try it** tab gives a command to run from the app instead:

```bash
curl -X POST http://dply.my-app.visits.internal/incr/visits
curl http://dply.my-app.visits.internal/
```

## Pricing

State bills every unit from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Durable Objects" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Requests | $0.18 | per million |
| Duration | $15.00 | per million GB-seconds |
| Rows read | $0.0012 | per million |
| Rows written | $1.20 | per million |
| Storage | $0.24 | per GB-month |

Duration is the time the object is active, at 128 MB. Storage is the largest size during the month. Usage is collected daily and billed to your organization. This bills at the invoice line **Workers CPU, Durable Objects, object storage and images**.

> [!NOTE]
> On a container app, the Durable Object that runs the container itself is metered at these same rates, whether or not you add State. Its usage and State's are reported together.

## Delete

Choose **Delete** on the card, or **Delete State** in the sheet. The app loses this State on the next deploy, and code that calls it gets errors.

The keys are not wiped. Every State on the app shares one store, so another State on the app, or one you add later, sees the same keys again. They are deleted only with the app.

## Related

- [Resources overview](/docs/resources)
- [Key-value (KV)](/docs/resources/key-value)
- [Usage & metering](/docs/usage)
