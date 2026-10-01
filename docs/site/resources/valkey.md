---
title: "Valkey (Redis)"
description: "Add a private, Redis-compatible Valkey store to your app for cache, sessions, and queues, billed per second while it is awake."
---

dply Valkey is a Redis-compatible in-memory store that belongs to one app. Your Redis client and `REDIS_URL` work unchanged. Use it for a Laravel or Rails cache, sessions, locks, rate limiting, and queues. The 0.25, 0.5 and 1 vCPU sizes sleep when nothing is connected and keep their keys; 2 and 4 vCPU stay on and write every change to disk.

> [!NOTE]
> Starting dply Valkey needs a card on the account, including during the trial. Usage is billed to that card. If the organization's subscription ends, the app stops receiving a 2 or 4 vCPU size's address on its next deploy.

## Create a store

1. In your app, open **Overview**.
2. Choose **Add resource**, then **dply Valkey**.
3. Leave **Create new** selected.
4. Enter a **Name**, such as `Cache`.
5. Pick a **Size**. For a size that sleeps, pick **Sleep when idle for**.
6. Choose **Create**, then deploy the app.

The address is written to the app's environment as `REDIS_URL` on the next deploy. It holds the password and is not shown in the builder. An app can have one Redis connection: dply Valkey or an [external Redis](/docs/resources/external-redis).

Valkey is available to container apps and to SSR or hybrid apps.

## Sizes

<!-- generated: php artisan dply:billing:price-table sizes --product=valkey -->
| Size | Memory | Per second awake | Per hour awake | Most per month | Sleeps |
| --- | --- | --- | --- | --- | --- |
| 0.25 vCPU | 250 MB | $0.00000186 | $0.0067 | $4.50 | Yes |
| 0.5 vCPU | 1 GB | $0.00000744 | $0.0268 | $18.00 | Yes |
| 1 vCPU | 2.5 GB | $0.0000186 | $0.067 | $45.00 | Yes |
| 2 vCPU | 5 GB | $0.0000475 | $0.171 | $115.00 | No (stays on) |
| 4 vCPU | 12 GB | $0.000062 | $0.223 | $150.00 | No (stays on) |

To change the size or sleep time later, choose **Details** on the Valkey card. The store restarts with its data on its next connection.

## Sleep and wake

A size that sleeps goes to sleep after **5 minutes**, **15 minutes**, or **1 hour** with no traffic, or you can pick **Stays on**. Before it sleeps, dply saves every key with its expiry. The next connection wakes it and restores the keys, which takes a few seconds; the client waits. A key never outlives its expiry while asleep. Sizes that sleep are also snapshotted every 15 minutes while awake.

2 vCPU and 4 vCPU never sleep. They keep an append-only file on a disk, so every change survives a restart.

> [!NOTE]
> The **Sleep** and **Wake** buttons on the Valkey card are different from idle sleep. **Sleep** takes `REDIS_URL` off the app on the next deploy and keeps the store and its keys. It also sets the store, 2 and 4 vCPU included, to sleep after 1 minute with no connections, so billing stops about a minute after the deploy that removes the address. Until then the app can still connect and wake it. **Wake** puts the address back on the next deploy and restores the size's own sleep setting.

## When memory is full

Each store runs with `maxmemory` set to its size and the `volatile-lru` eviction policy:

- Keys that have an expiry (cache entries) are evicted, least recently used first.
- Keys without an expiry (queued jobs, sessions without a TTL) are never evicted. When only those are left, writes fail with an out-of-memory error.

Watch **Memory used** and **Evicted keys** on the **Statistics** tab, and pick a larger size before it fills.

## Connect

The **Connect** tab on the Valkey sheet shows the **Address**, the **Username** (`default`), and the **Password** (choose **Show**). The address has the form `<id>.cache.dply.io:6380`.

> [!IMPORTANT]
> Connections must use TLS (`rediss://`) and send the host name (SNI) in the TLS handshake, so connect by host name, not IP address. The first command must be `AUTH` (or `HELLO` with `AUTH`). A connection that sends anything else first gets `NOAUTH`, and a wrong password gets `WRONGPASS`. Neither wakes a sleeping store.

### Laravel

For a Laravel app, the next deploy sets:

```env
REDIS_URL=rediss://default:<password>@<id>.cache.dply.io:6380
REDIS_USERNAME=default
REDIS_PASSWORD=<password>
REDIS_HOST=<id>.cache.dply.io
REDIS_PORT=6380
REDIS_CLIENT=phpredis
REDIS_PERSISTENT=true
CACHE_STORE=redis
```

`REDIS_PERSISTENT=true` keeps one TLS connection per PHP worker instead of a handshake on every request. Values you save in [Environment variables](/docs/environment-variables) win.

Sessions can use it too:

```env
SESSION_DRIVER=redis
```

To run queues on Valkey, add [queue workers](/docs/queue-workers) and set their **Connection** to `redis` (or leave it on **Automatic**). While workers run, dply sets `QUEUE_CONNECTION` to their connection so the app dispatches where the workers read. If your workers are on a sleeping database, the workers card offers **Queue on dply Valkey instead**. Queues on Valkey let a sleeping [database](/docs/resources/databases) stay asleep.

### Node

```js
import { createClient } from 'redis';

const redis = await createClient({ url: process.env.REDIS_URL }).connect();
await redis.set('greeting', 'hello', { EX: 60 });
```

In a Worker (SSR or hybrid app), use a client that opens TCP sockets through `cloudflare:sockets`, such as node-redis with the `nodejs_compat` flag.

### Rails

```ruby
config.cache_store = :redis_cache_store, { url: ENV['REDIS_URL'] }
```

### Over HTTPS (Workers, serverless, edge)

Every dply Valkey also answers Redis commands over HTTPS, so code that can't hold a Redis connection (Cloudflare Workers, serverless functions, edge middleware) can still use it, Lua scripts included.

The next deploy sets `REDIS_REST_URL` and `REDIS_REST_TOKEN` (the token is the store's password). A value you save in [Environment variables](/docs/environment-variables) wins.

```bash
curl "$REDIS_REST_URL/set/greeting/hello" -H "Authorization: Bearer $REDIS_REST_TOKEN"
curl "$REDIS_REST_URL/get/greeting"        -H "Authorization: Bearer $REDIS_REST_TOKEN"
# {"result":"hello"}
```

`POST /` takes one command as a JSON array, `POST /pipeline` several, and `POST /multi-exec` runs them in a transaction. Blocking and pub/sub commands (`BLPOP`, `SUBSCRIBE`, `MONITOR`) need the TCP address. A request to a sleeping store wakes it, like a connection does. The REST URL and an example are on the **Connect** tab.

## Test and inspect

The Valkey sheet has these tabs:

- **Overview**: size, sleep setting, awake time this month, and what happens when it is full.
- **Statistics**: whether it is awake and whether a snapshot is saved, without waking it. **Load live stats** connects (and wakes it) to show keys, memory, hit rate, operations per second, clients, evicted and expired keys, and the slowest recent commands.
- **Test**: **Run test from dply** connects the way the app does, then writes, reads, and deletes a test key and sends 10 `PING`s. **Run test from the app** runs the same check inside the running app.
- **Costs**: this month's cost, the hourly rate, and the monthly cap.

## Pricing

Valkey is billed per second while awake, up to the size's monthly maximum per app (see the table under [Sizes](#sizes)). A sleeping store is not billed. Commands sent over HTTPS are billed per 100,000 on top of that, and are not capped (see [Usage & metering](/docs/usage) for the rate). It appears on the invoice as **Valkey**, less your plan's included usage credit.

The invoice rounds the month's total once, to the nearest cent. Usage appears in [Usage & metering](/docs/usage).

## Delete a store

On the Valkey card, choose **Delete**, then **Delete resource**. The store and its keys are deleted, and `REDIS_URL` comes off the app on the next deploy.

> [!WARNING]
> Deleting dply Valkey deletes its keys and snapshots. It cannot be undone.

## Related

- [External Redis](/docs/resources/external-redis)
- [Queue workers](/docs/queue-workers)
- [Postgres, MySQL & MongoDB](/docs/resources/databases)
- [Usage & metering](/docs/usage)
