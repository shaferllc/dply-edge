---
title: "External Redis"
description: "Connect your app to a Redis you already run, such as Upstash, by pasting its address."
---

If you already have a Redis or Redis-compatible server, such as Upstash, Redis Cloud, or your own, you can point your app at it instead of starting [dply Valkey](/docs/resources/valkey). dply stores the address encrypted, sets the same environment variables it sets for dply Valkey, and leaves the server itself alone. dply does not run, monitor, or bill an external Redis.

## Connect an address

1. In your app, open **Overview**.
2. Choose **Add resource**, then **dply Valkey**.
3. Choose **Attach existing**.
4. Enter a **Name**, such as `Cache`.
5. Paste the **Address**, for example `rediss://default:secret@cache.example.com:6379`.
6. Choose **Attach**, then deploy the app.

The address must start with `redis://` or `rediss://`. It is encrypted and is not shown again in the builder. An app can have one Redis connection. To switch from dply Valkey to an external Redis, delete the Valkey connection first.

> [!TIP]
> Use `rediss://` (TLS) whenever your provider offers it. The app connects straight to the address over the internet, so the server must accept connections from outside your provider's network.

## What the app receives

On each deploy, dply sets these from the address:

| Variable | Value |
|---|---|
| `REDIS_URL` | The address you pasted |
| `REDIS_USERNAME` | The user in the address, or `default` |
| `REDIS_PASSWORD` | The password in the address |
| `REDIS_HOST` | The host in the address |
| `REDIS_PORT` | The port in the address |

For a Laravel app it also sets `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, and `REDIS_PERSISTENT=true`. Any of these you save in [Environment variables](/docs/environment-variables) wins.

### Laravel

```env
# Not set by dply. Add it in Environment to keep sessions in Redis:
SESSION_DRIVER=redis
```

For queues, add [queue workers](/docs/queue-workers) with their **Connection** set to `redis`. While they run, dply sets `QUEUE_CONNECTION=redis` for you.

### Node

```js
import { createClient } from 'redis';

const redis = await createClient({ url: process.env.REDIS_URL }).connect();
```

## Upstash

In the Upstash console, open your database and copy its Redis connection string, the one that starts with `rediss://`. Paste it as the address. Upstash requires TLS, so keep the `rediss://` scheme.

## Test, replace, or remove

Choose **Open** on the Redis card to see the address (password masked) and the values set on the app.

- **Test connection** connects from dply, signs in, and sends `PING`. The host must be reachable from the internet.
- **Replace address** saves a new `redis://` or `rediss://` address. The app uses it on the next deploy.
- **Remove from this app** takes `REDIS_URL` and the other Redis values off the app on the next deploy. The Redis server and its data are not touched.

The **Sleep** button on the card also takes the address off the app on the next deploy and keeps it saved; **Wake** puts it back.

## Related

- [Valkey (Redis)](/docs/resources/valkey)
- [Environment variables](/docs/environment-variables)
- [Queue workers](/docs/queue-workers)
