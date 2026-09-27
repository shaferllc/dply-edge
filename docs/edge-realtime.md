# Edge Realtime (Reverb-compatible broadcasting for Edge apps)

Edge apps get WebSockets / broadcasting as a managed **Realtime** resource: a
Pusher-protocol relay on Cloudflare Durable Objects (the protocol Laravel Reverb
speaks). The app keeps its normal Reverb setup (`BROADCAST_CONNECTION=reverb`,
Echo `broadcaster: 'reverb'`); dply points it at the relay. We do **not** run
Reverb inside the app container (second port, sleep, capacity — see "Why not"
below).

```
Browser (Echo, reverb) ──wss──► {host}/app/{key} ─► AppHub DO (one per app shard, hibernating)
App (container / Worker) ──HTTPS POST {host}/apps/{id}/events──┘
Browser ──POST /broadcasting/auth──► the app itself signs private / presence channels
```

## Relay deployments

`packages/realtime-worker` is deployed twice from one codebase:

| Worker | Serves | Host | KV `APPS` |
|---|---|---|---|
| `dply-realtime` (default env) | dply's own control plane (EchoClientConfig) | `realtime.on-dply.site` | existing namespace |
| `dply-realtime-apps` (`--env apps`) | customer Edge apps | `realtime-apps.on-dply.site` | its own namespace |

Customer load can never affect the control plane's relay. Config:
`config/edge.php` → `edge.realtime.{host, worker, kv_namespace_id}` (env
`EDGE_REALTIME_HOST`, `EDGE_REALTIME_KV_NAMESPACE_ID`).

### Deploying the relay

A code deploy restarts every `AppHub` Durable Object, so every open socket
drops briefly. pusher-js (Echo) reconnects and resubscribes on its own, but
deploy off-peak. The customer relay is `wrangler deploy --env apps` and is
deployed separately from dply's own (default env), so one never takes down
the other.

### Per-app hosts — `{label}.realtime.dply.io`

Every app also gets its own host, stored in `edge_realtime_apps.hostname` at
provision: `{label}.{edge.realtime.app_host_suffix}` (env
`EDGE_REALTIME_APP_HOST_SUFFIX`, default `realtime.dply.io`). `{label}` is the
site's label on its dply delivery host (`shop-a1b2c3.on-dply.site` →
`shop-a1b2c3`); when the site has no single-label dply host (custom domain,
no site) it is a slug of the site/app name plus 6 random characters. It is a
valid DNS label, unique across apps (`-2`, `-3`… on collision).

- The customer relay routes `*.realtime.dply.io/*` (zone `dply.io`) and has
  `APP_HOST_SUFFIX = "realtime.dply.io"`. On a host ending in
  `.realtime.dply.io`, every app route (`/app/{key}`, `/apps/{id}/…`) is served
  only when the record's `hostname` equals the request host (lowercase);
  otherwise — including an unknown app — `404 {"error":"invalid_app_key"}`, so
  a host never reveals which apps exist. Any other host (the shared
  `realtime-apps.on-dply.site`, workers.dev) serves every app as before.
- `edge.realtime.per_app_hosts` (env `EDGE_REALTIME_PER_APP_HOSTS`, default
  false) decides whether the app's env (`REVERB_HOST`, `PUSHER_HOST`,
  `VITE_*_HOST`) and the workspace sheet use the app's own host
  (`EdgeRealtimeApps::hostFor`). dply's own calls (stats, disconnect, the
  sheet's test publish) always use the shared host. Paths never change, so
  Pusher signatures (which cover the path, not the host) are unaffected.

## Contract

### KV record (`id:{appId}` and `key:{appKey}`, same JSON)

```json
{
  "id": "01J…",                 // EdgeRealtimeApp ULID = Pusher app_id
  "key": "rtk_…",               // public app key (24 chars after prefix)
  "secret": "rts_…",            // signing secret (40 chars after prefix)
  "enabled": true,
  "maxConnections": 200,        // concurrent sockets; relay refuses beyond
  "allowedOrigins": [],         // exact Origin values; empty = any
  "clientEvents": false,        // allow client-* events on private/presence
  "maxMessageBytes": 10240,     // publish payload + client event cap
  "hostname": "shop-a1b2c3.realtime.dply.io", // the app's own host; missing/null = shared host only
  "shards": 1                   // hub Durable Objects (see Sharding); missing = 1
}
```

### Stats — `GET {host}/apps/{id}/stats` (headers `X-Dply-Key`, `X-Dply-Secret`)

```json
{
  "connections": 12,            // open now
  "peak_connections": 40,       // since last reset
  "connection_seconds": 123456, // monotonic total since the hub was created
  "messages_in": 900,           // publishes received (monotonic)
  "messages_out": 15000,        // frames delivered to sockets (monotonic)
  "updated_at": 1790460000
}
```

`POST …/stats/reset` resets `peak_connections` only. Totals never reset:
dply's collector bills the difference from the last reading.

### Disconnect — `POST {host}/apps/{id}/disconnect` (same headers)

Closes every open socket with a `pusher:error` frame, code 4003 (application
disabled; 4000–4099 tells pusher-js not to reconnect), then a 4003 close.
Returns `{"ok": true, "closed": n}`. dply calls it when the app is put to
sleep and before it is deleted. New connects are refused by `enabled: false`
in KV (the relay's KV reads can lag up to ~60s). The relay also refuses a
connect past `maxConnections` (403, code 4004).

### Sharding (large apps)

One `AppHub` DO tops out in the tens of thousands of sockets and serializes
all fan-out, so an app can be spread over `shards` hubs: `{appId}` (shard 0 —
the same object as an unsharded app, so `shards: 1` changes nothing and needs
no migration) and `{appId}:{n}`. dply writes `shards = ceil(effective
maxConnections / edge.realtime.shard_size)` (env `EDGE_REALTIME_SHARD_SIZE`,
default 10000; clamped 1–32, as the relay does).

- **Connect:** the Worker picks a random shard. Each shard enforces
  `ceil(maxConnections / shards)`; a full shard (4004) passes the socket to
  the next, so the app refuses only when every shard is at its share. The cap
  is approximate: up to `shards × ceil(max / shards)` sockets.
- **Publish** (`/apps/{id}/events`) goes to every shard in parallel; the
  response is `{ok, channels, delivered}` with `delivered` summed, or the first
  failing shard's response (e.g. 413). Only shard 0 counts it in
  `messages_in`. There is no `batch_events` route (never was).
- **Client events:** the receiving shard delivers locally (not to the sender)
  and relays the frame to the other shards.
- **Presence:** each shard holds its own members. On subscribe it asks the
  other shards for theirs and merges by `user_id`. `member_added` /
  `member_removed` go to every shard, and only when the user has no other
  connection on any shard. Races: the same user joining two shards within one
  round trip may be announced twice (clients treat a repeat as a no-op);
  leaving two shards at the same moment can make each see the other still
  present, so no `member_removed` is sent until that user's next leave. A peer
  that fails to answer is skipped (its members are missing from that one
  snapshot).
- **Stats / reset / disconnect** fan out to every shard and sum.
  `peak_connections` is the **sum of the shards' own peaks**: an upper bound
  on the app's real concurrent peak (shards needn't peak together).
- **Shard counts only grow.** Shrinking would strand sockets on the dropped
  shards and make the collector re-bill (totals would go down). So
  `EdgeRealtimeApps` keeps the app's high-water mark in `meta.shards` and the
  KV record never goes below it, even if the size is lowered. Sizes 10,000 and
  20,000 are offered only where the plan allows (Enterprise); 20,000 is 2 shards.

### Sleep, plan caps, billing

- Sleep (map card): `status = disabled` → KV `enabled: false` → disconnect.
  Wake: `status = active` → KV `enabled: true`. The env stays while asleep,
  so waking needs no redeploy.
- `subscription.standard.tiers.*.realtime_max_connections` caps the size;
  provisioning rejects larger sizes and KV writes clamp to it.
- `dply:edge:collect-realtime-usage` (hourly) diffs the monotonic stats into
  `edge_realtime_usage`, keeps the day's peak, then resets the relay's peak.

### Laravel side

- Model `App\Models\EdgeRealtimeApp`, table `edge_realtime_apps`:
  `id (ulid), organization_id, site_id (nullable), name, hostname (unique,
  nullable), app_key, app_secret
  (encrypted), status (active|disabled), max_connections, allowed_origins
  (json), client_events (bool), meta (json: usage baselines), timestamps`.
- Service `App\Modules\Edge\Services\Realtime\EdgeRealtimeApps`:
  `provision(Site, string $name, array $options): EdgeRealtimeApp`,
  `sync(EdgeRealtimeApp)` (rewrite both KV keys), `rotateSecret`,
  `destroy` (delete both KV keys), `stats(EdgeRealtimeApp): array`.
- Connection row on the site: `kind = realtime`, `name = REALTIME`,
  `target = EdgeRealtimeApp id`, host `resourceHost($site, 'realtime')`.
- Env (`EdgeContainerConnections::realtimeDriverEnv`, merged like the other
  driver env; the app's own saved env wins). Laravel apps:
  `BROADCAST_CONNECTION=reverb`, `REVERB_APP_ID/KEY/SECRET`, `REVERB_HOST`,
  `REVERB_PORT=443`, `REVERB_SCHEME=https`, the `PUSHER_*` equivalents (for the
  pusher driver), and `VITE_REVERB_APP_KEY/HOST/PORT/SCHEME` — the `VITE_*`
  values must also reach the **build**, since Vite bakes them into the JS.
- Usage: table `edge_realtime_usage (date, organization_id, site_id,
  realtime_app_id, connection_seconds, messages, peak_connections)`, collector
  diffing the monotonic stats, cost `App\Modules\Billing\Services\EdgeRealtimeCost`
  priced from `dply.edge.usage_billing.realtime_*`.
- Pricing (ruling r-p3dsj9znvtnyhphr): $0.50 per million connection-minutes
  and $0.50 per million messages past the plan allowance
  (`subscription.standard.tiers.*.realtime_connection_minutes` / `realtime_messages`:
  Pro 5M + 10M, Team 25M + 50M, Enterprise unlimited). Our Cloudflare cost:
  idle sockets are free (hibernation; `pusher:ping` is answered by
  `setWebSocketAutoResponse` without waking the hub), fan-out frames are free,
  a busy app's Durable Object is at most ~$4/month, publishes ~$0.45/million.

## Why not Reverb inside the container

- Reverb is a second long-running process on another port; the container proxy
  forwards one port.
- Open sockets hold capacity slots and may keep the container from sleeping
  (billing all month) — or sleep drops them.
- 101 responses were re-wrapped (and broken) by the sticky-cookie and debug
  paths; that passthrough is fixed separately for apps with their own sockets.
