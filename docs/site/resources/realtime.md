---
title: "Realtime (WebSockets)"
description: "Add Reverb- and Pusher-compatible WebSockets to your app for broadcasting, private and presence channels, without running a socket server."
---

Realtime gives your app WebSockets for broadcasting: live notifications, dashboards, chat, and presence. It speaks the Pusher protocol, which is what Laravel Reverb speaks, so Laravel Echo, `pusher-js`, and any Pusher server SDK work unchanged. dply runs the socket server for you. Browsers connect to it, your app publishes to it over HTTPS, and your app still authorises private and presence channels itself.

> [!NOTE]
> Realtime needs a card on the account. Connections and messages are billed to that card, past your plan's included usage credit.

## Create a Realtime app

1. In your app, open **Resources**.
2. Choose **Add resource**, then **Realtime**.
3. Optionally enter a **Name**. It is only shown in dply.
4. Pick **Max connections**: the most sockets that may be open at once. Past this, new connections are refused.
5. Optionally list **Allowed origins**, one per line, such as `https://example.com`. Empty allows any site to connect.
6. Choose **Create**, then deploy the app.

An app has one Realtime connection. Realtime works with container apps and with SSR or hybrid apps.

Each Realtime app gets its own host, shown under **Credentials**. Copy the host from there rather than building it yourself.

## What the app receives

The next deploy sets these. For a Laravel app it also sets `BROADCAST_CONNECTION=reverb`. Values you save in [Environment variables](/docs/environment-variables) win.

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=...
REVERB_APP_KEY=rtk_...
REVERB_APP_SECRET=rts_...
REVERB_HOST=<your realtime host>
REVERB_PORT=443
REVERB_SCHEME=https
PUSHER_APP_ID=...
PUSHER_APP_KEY=rtk_...
PUSHER_APP_SECRET=rts_...
PUSHER_HOST=<your realtime host>
PUSHER_PORT=443
PUSHER_SCHEME=https
PUSHER_APP_CLUSTER=mt1
VITE_REVERB_APP_KEY=rtk_...
VITE_REVERB_HOST=<your realtime host>
VITE_REVERB_PORT=443
VITE_REVERB_SCHEME=https
VITE_PUSHER_APP_KEY=rtk_...
VITE_PUSHER_HOST=<your realtime host>
VITE_PUSHER_PORT=443
VITE_PUSHER_SCHEME=https
VITE_PUSHER_APP_CLUSTER=mt1
```

The `VITE_*` values are also passed to the build, because Vite bakes them into your JavaScript. On an SSR or hybrid app they arrive as Worker bindings (`env.REVERB_APP_KEY` and so on), with the secret bound as a secret.

## Laravel

Run `php artisan install:broadcasting --reverb` in your project if you have not already. It adds the `reverb` connection, `resources/js/echo.js`, and `routes/channels.php`. The stock files from Laravel 11 and 12 work unchanged.

> [!IMPORTANT]
> Do not run `php artisan reverb:start` on dply. dply runs the socket server; your app only publishes to it.

`config/broadcasting.php`:

```php
'reverb' => [
    'driver' => 'reverb',
    'key' => env('REVERB_APP_KEY'),
    'secret' => env('REVERB_APP_SECRET'),
    'app_id' => env('REVERB_APP_ID'),
    'options' => [
        'host' => env('REVERB_HOST'),
        'port' => env('REVERB_PORT', 443),
        'scheme' => env('REVERB_SCHEME', 'https'),
        'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
    ],
],
```

`resources/js/echo.js`:

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});
```

### Channels and authorisation

Public channels need no setup. Private and presence channels are authorised by your own app at `POST /broadcasting/auth` (added by `Broadcast::routes()`), signed with the app secret. The socket server never sees your users.

```php
// routes/channels.php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('orders.{order}', function ($user, App\Models\Order $order) {
    return $user->id === $order->user_id;
});
```

```php
// Anywhere in the app
broadcast(new App\Events\OrderShipped($order));
```

```js
Echo.private(`orders.${orderId}`).listen('OrderShipped', (e) => console.log(e));
```

For a step-by-step walkthrough, see [Laravel broadcasting with Realtime](/docs/guides/laravel-broadcasting).

## Node and other stacks

Any Pusher client and server SDK works.

In the browser:

```js
import Pusher from 'pusher-js';

const pusher = new Pusher('rtk_...', {
  wsHost: '<your realtime host>',
  wssPort: 443,
  forceTLS: true,
  enabledTransports: ['ws', 'wss'],
  cluster: 'mt1',
});

pusher.subscribe('orders').bind('order.shipped', (data) => console.log(data));
```

On the server, with the `pusher` npm package:

```js
import Pusher from 'pusher';

const pusher = new Pusher({
  appId: process.env.PUSHER_APP_ID,
  key: process.env.PUSHER_APP_KEY,
  secret: process.env.PUSHER_APP_SECRET,
  host: process.env.PUSHER_HOST,
  port: '443',
  useTLS: true,
});

await pusher.trigger('orders', 'order.shipped', { id: 42 });
```

In a Worker, read the same values from `env` instead of `process.env`. The `pusher` package signs with Node crypto, so the Worker needs the `nodejs_compat` flag.

## Client events

Client events let browsers send `client-*` events to each other on private and presence channels, such as Echo's `whisper`. They are off by default. To turn them on, open the Realtime card, go to **Settings**, turn on **Client events**, and choose **Save**. The sender does not receive its own event.

## Try it

The **Try it** tab connects the dashboard to your Realtime app on a throwaway public channel. Choose **Connect**, then **Send test event**. When the event shows up in the log, Realtime works end to end. The latency shown includes the trip through dply.

## Sleep and wake

Choose **Sleep** on the Realtime card to stop it. Open sockets are closed, and new connections and publishes are refused until you choose **Wake**. The app keeps its keys, so waking takes effect without a redeploy.

## Rotate the secret

Open **Credentials** and choose **Rotate secret**. A new secret is made at once. Browsers keep working because the key does not change, but the running app keeps signing with the old secret, so its broadcasts are refused until you redeploy.

The key is public: browsers use it to connect. The secret is only given to the app and is never shown in the dashboard.

## Change settings

On the **Settings** tab you can change **Max connections**, **Allowed origins**, and **Client events**, then choose **Save**. No redeploy is needed. Open sockets keep their old limits until they reconnect.

## Limits

| Limit | Value |
|---|---|
| Message size | 10,240 bytes of `data` per publish or client event. A larger publish gets HTTP `413`; a larger client event gets error `4301`. |
| Max connections per app | Starter: up to 200 · Pro: up to 1,000 · Team: up to 5,000 · Enterprise: up to 20,000 |
| Sizes | 100, 200, 500, 1,000, 5,000, 10,000, 20,000. A size above your plan's cap is refused when you create the app. |
| Batch publish | Not supported. Use one `trigger` per event. |

Error codes a client may see:

| Code | Meaning |
|---|---|
| `4003` | The Realtime app is asleep or deleted. The client does not reconnect. |
| `4004` | Over the app's max connections. |
| `4009` | Not authorised: the page's origin is not in **Allowed origins**, the channel's auth signature is wrong, or the presence data is invalid. |
| `4301` | Client events are off, or the event is too large. |

Apps with more than 10,000 connections are spread across several servers automatically. Presence and client events work across them.

## Pricing

Realtime is billed on connection-minutes (one socket open for one minute) and messages (each publish; delivering it to sockets is free, so a broadcast to 1,000 listeners is one message), every unit from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Realtime" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Connection-minutes | $0.25 | per million |
| Messages | $0.62 | per million |

This bills at the invoice line **Realtime**. Connections per app are capped by plan:

<!-- generated: php artisan dply:billing:price-table limits -->
|  | Starter | Pro | Team |
| --- | --- | --- | --- |
| Sites | Unlimited | Unlimited | Unlimited |
| Concurrent builds | 1 | 2 | 5 |
| Build timeout | 20 min | 45 min | 60 min |
| Custom domains (per organization) | 3 | 20 | 100 |
| Container app instances | 1 per app | Autoscaling | Autoscaling |
| Queue workers per app | 1 | 5, autoscaling | 10, autoscaling |
| SQL databases (D1) | 2 | 10 | 50 |
| Queues | 2 | 10 | 50 |
| Realtime connections per app | 200 | 1,000 | 5,000 |
| Audit log | No | No | Yes |

The **Overview** tab shows open connections, the peak, this month's messages, and this month's cost. Usage is collected every hour.

## Delete Realtime

Open the Realtime card and choose **Delete Realtime**, then **Delete resource**. Every browser is disconnected, usage up to that moment is billed, and the app loses its Realtime keys on the next deploy. This cannot be undone.

## Related

- [Laravel broadcasting with Realtime](/docs/guides/laravel-broadcasting)
- [Environment variables](/docs/environment-variables)
- [Usage & metering](/docs/usage)
