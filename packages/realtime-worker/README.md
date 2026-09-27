# @dply/realtime-worker

A Pusher / Laravel Reverb–compatible realtime relay that runs on Cloudflare
Workers + Durable Objects. It speaks the Pusher wire protocol, so `laravel-echo`
and `pusher-js` connect to it unchanged, and `pusher-php-server` (Laravel's
`pusher` broadcast driver) can publish to it with credentials only.

This is the data plane for dply's managed **Realtime** resource: deploy it once
to the platform Cloudflare account, then dply provisions per-customer apps into
it by writing credentials to the `APPS` KV namespace — it never re-deploys the
Worker.

## Architecture

```
laravel-echo / pusher-js ──wss──▶ GET /app/{appKey} ──▶ AppHub Durable Object
pusher-php-server ────────POST──▶ /apps/{appId}/events ─▶  (one per app id)
                                                            • channels
dply (Laravel) ──writes creds──▶ APPS KV namespace          • presence rosters
                                                            • client events
```

- **One Durable Object instance per app id** (`idFromName(appId)`) holds every
  live WebSocket for that app and fans out channel + presence messages.
- Connections use the **WebSocket Hibernation API**: per-connection state lives
  in each socket's attachment, so the DO can evict from memory and rebuild any
  view (subscribers, presence) by scanning `getWebSockets()`.
- Tenancy comes from the app key → DO routing, so channel names are the bare
  Pusher names (`private-orders`, `presence-room`) — fully Echo-compatible.

## Endpoints

| Method | Path                    | Who         | Purpose                                  |
| ------ | ----------------------- | ----------- | ---------------------------------------- |
| `GET`  | `/app/{appKey}`         | browsers    | WebSocket connect (Upgrade required)     |
| `POST` | `/apps/{appId}/events`  | app servers | Publish an event to channel(s)           |
| `GET`/`POST` | `/apps/{appId}/stats` | dply   | Usage counters (header auth, below)      |
| `POST` | `/apps/{appId}/stats/reset` | dply    | Reset `peak_connections` only            |
| `GET`  | `/health`               | monitoring  | Liveness                                 |

Channel auth (private/presence) is handled by the **customer's own** app server
(standard Laravel `/broadcasting/auth`), signing with the app secret — exactly
like Pusher. The Worker only verifies those signatures; it never holds an auth
endpoint itself.

## The dply ↔ Worker contract (KV)

dply writes one JSON record per app under **two** keys so both connect and
publish can resolve it:

- `key:{appKey}` → record
- `id:{appId}`  → record

```json
{
  "id": "rt_01HX…",
  "key": "rtk_AbC123…",
  "secret": "rts_…",
  "enabled": true,
  "maxConnections": 1000,
  "allowedOrigins": ["https://app.example.com"],
  "clientEvents": false,
  "maxMessageBytes": 10240
}
```

The last three are optional — records written before they existed behave as
their defaults:

| Field | Default | Effect |
| --- | --- | --- |
| `allowedOrigins` | `[]` (any) | WebSocket connect needs an exact `Origin` match, else `403` (code 4009) before upgrade |
| `clientEvents` | `false` | `client-*` events on subscribed private-/presence- channels fan out to the channel's other subscribers (never the sender); when off the sender gets `pusher:error` 4301 |
| `maxMessageBytes` | `10240` | UTF-8 size cap on publish `data` (`413`) and client event `data` (`pusher:error` 4301) |

Limits are read at connect time (stored on the socket), so a changed
`clientEvents` / `maxMessageBytes` reaches existing sockets on reconnect.

To deprovision, delete both keys (or set `enabled: false` to hard-stop new
connections + publishes immediately).

## Publish auth

`POST /apps/{appId}/events` accepts either:

1. **dply header auth** (simple): `X-Dply-Key` + `X-Dply-Secret`.
2. **Pusher REST signature** (drop-in for `pusher-php-server`):
   `?auth_key&auth_timestamp&auth_signature&body_md5`, signed as
   `HMAC_SHA256(secret, "POST\n/apps/{id}/events\n{sorted query}")`.

Body shape (Pusher-compatible):

```json
{ "name": "OrderShipped", "channels": ["private-orders"], "data": { "id": 42 }, "socket_id": "123.456" }
```

## Usage stats

`GET` (or `POST`) `/apps/{appId}/stats` with `X-Dply-Key` + `X-Dply-Secret`:

```json
{
  "connections": 12,
  "peak_connections": 40,
  "connection_seconds": 123456,
  "messages_in": 900,
  "messages_out": 15000,
  "updated_at": 1790460000,
  "peakConnections": 40
}
```

- `connection_seconds`, `messages_in` (accepted server publishes + client
  events), `messages_out` (frames fanned out to sockets) are monotonic — never
  reset; dply bills the difference between readings. Open sockets count up to
  the read; each socket's connect time lives in its hibernation attachment.
- Counters accumulate in memory and flush to DO storage on a 5s alarm (and on
  every stats read), so an eviction loses at most ~5s of counts.
- `peak_connections` resets only via `POST …/stats/reset`.
- `peakConnections` is the legacy camelCase key, kept for older dply readers.

## Connecting from a customer app (Laravel Echo)

```js
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
window.Pusher = Pusher;

window.Echo = new Echo({
  broadcaster: 'reverb', // or 'pusher' — same protocol
  key: import.meta.env.VITE_DPLY_REALTIME_KEY, // the app key
  wsHost: 'realtime-apps.on-dply.site', // customer relay (--env apps)
  wsPort: 443,
  wssPort: 443,
  forceTLS: true,
  enabledTransports: ['ws', 'wss'],
  cluster: 'mt1', // ignored by the relay; pusher-js requires a value
});
```

Server side, point Laravel's `pusher` broadcaster at the relay (host +
key/secret) and it publishes over the Pusher REST signature path above.

## Develop

```bash
npm install
npm test          # vitest: md5 + auth vectors, hub (fake DO state), limits
npx tsc --noEmit  # typecheck
npm run dev       # wrangler dev (local DO + KV)
```

## Deploy (operator)

One codebase, two Workers (see `docs/edge-realtime.md`):

| Command | Worker | Host | Serves |
| --- | --- | --- | --- |
| `npx wrangler deploy` | `dply-realtime` | `realtime.on-dply.site` | dply's own control plane |
| `npx wrangler deploy --env apps` | `dply-realtime-apps` | `realtime-apps.on-dply.site` | customer Edge apps |

Each has its own KV namespace and Durable Objects, so customer load never
touches the control plane relay. First deploy of the `apps` env:

1. `npx wrangler kv namespace create APPS --env apps`, paste the id over
   `REPLACE_WITH_APPS_KV_ID` in `wrangler.toml`.
2. `npx wrangler deploy --env apps` (runs its own `v1` DO migration).
3. Set `EDGE_REALTIME_KV_NAMESPACE_ID` / `EDGE_REALTIME_HOST` in dply.

The route is a specific zone route (not a `custom_domain`): the Edge worker's
`*.on-dply.site/*` wildcard would shadow a custom domain.
