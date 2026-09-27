---
title: "Laravel broadcasting with Realtime"
description: "Broadcast Laravel events to browsers with Echo and the stock Reverb driver, using dply's managed Realtime relay instead of running Reverb yourself."
---

Realtime is a managed WebSocket relay that speaks the Pusher protocol, the same protocol Laravel Reverb uses. Your app keeps its normal Reverb setup: `BROADCAST_CONNECTION=reverb` on the server and Echo with `broadcaster: 'reverb'` in the browser. dply runs the WebSocket server, so you never run `php artisan reverb:start`.

> [!NOTE]
> Realtime is available on every plan. It has no allowance of its own — connection-minutes and messages bill at the usage rates, less your plan's included usage credit; see [Plans & pricing](/docs/pricing).

## How it fits together

- **Browsers** connect to the relay over `wss://` with the app's public key.
- **Your app** publishes events to the relay over HTTPS, signed with the app's secret.
- **Private and presence channels** are authorized by your own app at `/broadcasting/auth`. The relay never sees your users.

This guide assumes a Laravel 11 or 12 app already deployed as a container. See [Deploy a Laravel app](/docs/guides/laravel).

## Install broadcasting in your app

Locally, install broadcasting with the Reverb driver:

```bash
php artisan install:broadcasting --reverb
```

This adds the `reverb` connection to `config/broadcasting.php`, `resources/js/echo.js` and `routes/channels.php`, and installs `laravel-echo` and `pusher-js`. The stock files work unchanged. Commit them.

The `reverb` connection reads these variables:

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

## Add a Realtime resource

1. On the app's **Overview**, choose **Add resource**, then **Realtime**.
2. Redeploy the app.

The next deploy sets these variables. A value you save under **Environment** wins over the injected one.

| Variable | Value |
|---|---|
| `BROADCAST_CONNECTION` | `reverb` |
| `REVERB_APP_ID`, `REVERB_APP_KEY`, `REVERB_APP_SECRET` | The Realtime app's credentials |
| `REVERB_HOST` | The relay's host |
| `REVERB_PORT`, `REVERB_SCHEME` | `443`, `https` |
| `PUSHER_*` | The same values, for apps on the `pusher` driver; `PUSHER_APP_CLUSTER` is `mt1` |
| `VITE_REVERB_APP_KEY`, `VITE_REVERB_HOST`, `VITE_REVERB_PORT`, `VITE_REVERB_SCHEME` | For the browser bundle |
| `VITE_PUSHER_*` | The same, for Pusher clients |

> [!IMPORTANT]
> Vite bakes `VITE_*` values into the JavaScript at build time. dply passes them to the asset build, so the first deploy after adding Realtime is the one that makes Echo work. Redeploy after you rotate the secret or recreate the resource.

## Configure Echo

The file `install:broadcasting` generates already reads the right variables:

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

## Broadcast an event

Broadcast as usual. For a private channel, authorize it in `routes/channels.php`:

```php
Broadcast::channel('orders.{orderId}', function ($user, $orderId) {
    return $user->id === Order::findOrNew($orderId)->user_id;
});
```

```php
class OrderShipped implements ShouldBroadcast
{
    public function __construct(public Order $order) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('orders.'.$this->order->id)];
    }
}
```

```js
Echo.private(`orders.${orderId}`).listen('OrderShipped', (e) => console.log(e));
```

`ShouldBroadcast` events are queued. Process them with queue workers or a Queue resource (see [Deploy a Laravel app](/docs/guides/laravel#run-queued-jobs)), or use `ShouldBroadcastNow` to publish during the request.

## Test the connection

Open the Realtime resource from the app's **Overview**. Under **Listen in the browser**, choose **Connect**, then **Send test event**. When the `dply.test` event appears, the relay works end to end. If it does not, the resource may be asleep, or **Allowed origins** may not include the dashboard.

## Settings

In the Realtime resource's **Settings**:

- **Max connections**: sockets open at once. The relay refuses connections beyond it. The largest size depends on the plan: 200 on Starter, 1,000 on Pro, 5,000 on Team.
- **Allowed origins**: one origin per line, such as `https://example.com`. Empty allows any.
- **Client events**: let browsers send `client-*` events to each other on private and presence channels (Echo `whisper`).

These apply at the relay right away, without a redeploy.

**Rotate secret** issues a new secret immediately. Browsers keep working because the key does not change, but the running app signs with the old secret until you redeploy, so its broadcasts are refused in between.

## Usage and billing

Realtime is metered by connection-minutes (each open socket, each minute) and messages (publishes in plus frames delivered out). They bill at these rates, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Realtime" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Connection-minutes | $0.25 | per million |
| Messages | $0.62 | per million |

See [Usage & metering](/docs/usage) and [Realtime (WebSockets)](/docs/resources/realtime).

## Next steps

- [Realtime (WebSockets)](/docs/resources/realtime)
- [Queue workers](/docs/queue-workers)
- [Troubleshooting errors & 5xx](/docs/guides/troubleshooting-runtime)
