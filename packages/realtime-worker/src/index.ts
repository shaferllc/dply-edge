/// <reference types="@cloudflare/workers-types" />

// dply-realtime — a Pusher/Reverb-compatible realtime relay on Cloudflare.
// Deployed once to the platform Cloudflare account; dply provisions apps into
// it by writing credentials into the APPS KV namespace (never re-deploying the
// Worker). Routes:
//   GET  /app/{appKey}        WebSocket connect (laravel-echo / pusher-js)
//   POST /apps/{appId}/events Server-side publish (pusher-php-server compatible)
//   GET|POST /apps/{appId}/stats, POST /apps/{appId}/stats/reset  Usage stats
//   POST /apps/{appId}/disconnect  Close every open socket (dply puts the app to sleep)
//   GET  /health              Liveness probe
//
// Per-app hosts: on {label}.{APP_HOST_SUFFIX} (customer relay only) every app
// route answers only for the app whose KV record names that exact hostname;
// anything else is a 404 invalid_app_key, whether or not the app exists. Any
// other host (the shared realtime-apps host, workers.dev) serves every app.
//
// Sharding: a record with `shards` > 1 spreads the app over hubs `{appId}`
// and `{appId}:{n}` (docs/edge-realtime.md → Sharding). 1 shard = one hub.

import { timingSafeEqual, verifyPublishRequest, type AppCredentials } from './auth';
import { AppHub } from './hub';
import { appLimits, hubName, originAllowed, shardCount } from './protocol';
import type { AppRecord, Env } from './types';

export { AppHub };

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    const url = new URL(request.url);
    const path = url.pathname;

    if (path === '/health' || path === '/') {
      return Response.json({ ok: true, service: 'dply-realtime' });
    }

    const connectMatch = path.match(/^\/app\/([^/]+)$/);
    if (connectMatch) {
      return handleConnect(request, env, url, decodeURIComponent(connectMatch[1]));
    }

    const publishMatch = path.match(/^\/apps\/([^/]+)\/events$/);
    if (publishMatch && request.method === 'POST') {
      return handlePublish(request, env, url, decodeURIComponent(publishMatch[1]));
    }

    // Operator/billing: read or reset peak-concurrent stats for an app.
    const statsMatch = path.match(/^\/apps\/([^/]+)\/stats(\/reset)?$/);
    if (statsMatch) {
      return handleStats(request, env, url, decodeURIComponent(statsMatch[1]), Boolean(statsMatch[2]));
    }

    const disconnectMatch = path.match(/^\/apps\/([^/]+)\/disconnect$/);
    if (disconnectMatch && request.method === 'POST') {
      return handleDisconnect(request, env, url, decodeURIComponent(disconnectMatch[1]));
    }

    return Response.json({ error: 'not_found' }, { status: 404 });
  },
};

async function handleConnect(request: Request, env: Env, url: URL, appKey: string): Promise<Response> {
  if (request.headers.get('Upgrade') !== 'websocket') {
    return Response.json({ error: 'expected_websocket' }, { status: 426 });
  }

  const app = await lookupAppByKey(env, appKey);
  if (wrongHost(env, url, app)) {
    return hostNotFound();
  }
  if (!app || !app.enabled) {
    console.log({ src: 'realtime', event: 'connect_rejected', reason: app ? 'disabled' : 'unknown_key', appKey });
    return Response.json({ error: 'invalid_app_key' }, { status: 401 });
  }
  const limits = appLimits(app);
  const origin = request.headers.get('Origin');
  if (!originAllowed(limits.allowedOrigins, origin)) {
    console.log({ src: 'realtime', event: 'connect_rejected', reason: 'origin', appId: app.id, origin });
    // Pusher's code for an origin the app doesn't allow.
    return Response.json({ error: 'origin_not_allowed', code: 4009, message: `Origin not allowed: ${origin ?? '(none)'}` }, { status: 403 });
  }
  console.log({ src: 'realtime', event: 'connect', appId: app.id, appKey: app.key });

  const shards = shardCount(app.shards);
  const hasMax = typeof app.maxConnections === 'number' && Number.isFinite(app.maxConnections);
  // Forward the upgrade to the app's hub, carrying resolved credentials so the
  // DO can verify channel auth without re-reading KV.
  const forwardTo = (shard: number): Promise<Response> => {
    const forwarded = new Request(request.url, request);
    forwarded.headers.set('X-App-Id', app.id);
    forwarded.headers.set('X-App-Key', app.key);
    forwarded.headers.set('X-App-Secret', app.secret);
    forwarded.headers.set('X-App-Client-Events', limits.clientEvents ? '1' : '0');
    forwarded.headers.set('X-App-Max-Message-Bytes', String(limits.maxMessageBytes));
    if (hasMax) {
      // Each shard holds its share of the cap (approximate: see docs → Sharding).
      forwarded.headers.set('X-App-Max-Connections', String(Math.ceil(app.maxConnections! / shards)));
    }
    if (shards > 1) {
      forwarded.headers.set('X-App-Shard', String(shard));
      forwarded.headers.set('X-App-Shards', String(shards));
    }
    return hubFor(env, app.id, shard).fetch(forwarded);
  };
  if (shards === 1) {
    return forwardTo(0);
  }
  // Random shard; a full one (403, 4004) passes the socket on to the next, so
  // the app refuses only when every shard is at its share.
  const start = Math.floor(Math.random() * shards);
  let res!: Response;
  for (let i = 0; i < shards; i++) {
    res = await forwardTo((start + i) % shards);
    if (res.status !== 403) {
      return res;
    }
  }
  return res;
}

async function handlePublish(request: Request, env: Env, url: URL, appId: string): Promise<Response> {
  const record = await lookupAppById(env, appId);
  if (wrongHost(env, url, record)) {
    return hostNotFound();
  }
  if (!record || !record.enabled) {
    console.log({ src: 'realtime', event: 'publish_rejected', reason: record ? 'disabled' : 'unknown_app', appId });
    return Response.json({ error: 'invalid_app' }, { status: 401 });
  }

  const rawBody = await request.text();
  const app: AppCredentials = record;
  const authorized = await verifyPublishRequest(
    app,
    request.method,
    url.pathname,
    url.searchParams,
    request.headers,
    rawBody,
  );
  if (!authorized) {
    console.log({ src: 'realtime', event: 'publish_unauthorized', appId });
    return Response.json({ error: 'unauthorized' }, { status: 401 });
  }

  const shards = shardCount(record.shards);
  const internal = (shard: number) =>
    new Request('https://hub/internal/publish', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-App-Max-Message-Bytes': String(appLimits(record).maxMessageBytes),
        // Only shard 0 counts the publish in messages_in, so fan-out never multiplies it.
        ...(shard > 0 ? { 'X-Hub-Fanout': '1' } : {}),
      },
      body: rawBody,
    });
  if (shards === 1) {
    return hubFor(env, app.id).fetch(internal(0));
  }
  const responses = await fanOut(env, app.id, shards, internal);
  const failed = responses.find((r) => !r.ok);
  if (failed) {
    return failed;
  }
  const bodies = (await Promise.all(responses.map((r) => r.json()))) as Array<{ channels: number; delivered: number }>;
  return Response.json({ ok: true, channels: bodies[0].channels, delivered: sum(bodies, 'delivered') });
}

async function handleStats(
  request: Request,
  env: Env,
  url: URL,
  appId: string,
  reset: boolean,
): Promise<Response> {
  const record = await operatorRecord(request, env, url, appId);
  if (record instanceof Response) {
    return record;
  }

  const internalPath = reset ? '/internal/stats/reset' : '/internal/stats';
  const shards = shardCount(record.shards);
  const internal = () => new Request(`https://hub${internalPath}`, { method: 'POST' });
  if (shards === 1) {
    return hubFor(env, appId).fetch(internal());
  }
  const bodies = await jsonFromAll(await fanOut(env, appId, shards, internal));
  if (bodies instanceof Response) {
    return bodies;
  }
  // Peak = sum of the shards' own peaks: an upper bound on the app's true
  // concurrent peak (the shards need not have peaked at the same moment).
  const peak = sum(bodies, 'peak_connections');
  if (reset) {
    return Response.json({ ok: true, peak_connections: peak, peakConnections: peak });
  }
  return Response.json({
    connections: sum(bodies, 'connections'),
    peak_connections: peak,
    connection_seconds: sum(bodies, 'connection_seconds'),
    messages_in: sum(bodies, 'messages_in'),
    messages_out: sum(bodies, 'messages_out'),
    updated_at: Math.max(...bodies.map((b) => Number(b.updated_at) || 0)),
    peakConnections: peak,
  });
}

/** dply sleeping (or deleting) an app: the hub closes every socket with Pusher 4003. */
async function handleDisconnect(request: Request, env: Env, url: URL, appId: string): Promise<Response> {
  const record = await operatorRecord(request, env, url, appId);
  if (record instanceof Response) {
    return record;
  }
  console.log({ src: 'realtime', event: 'disconnect_all', appId });
  const shards = shardCount(record.shards);
  const internal = () => new Request('https://hub/internal/disconnect', { method: 'POST' });
  if (shards === 1) {
    return hubFor(env, appId).fetch(internal());
  }
  const bodies = await jsonFromAll(await fanOut(env, appId, shards, internal));
  return bodies instanceof Response ? bodies : Response.json({ ok: true, closed: sum(bodies, 'closed') });
}

/** Header auth only (operator/server to server): X-Dply-Key / X-Dply-Secret must match the KV record. Returns the record, or the refusal. */
async function operatorRecord(request: Request, env: Env, url: URL, appId: string): Promise<AppRecord | Response> {
  const record = await lookupAppById(env, appId);
  if (wrongHost(env, url, record)) {
    return hostNotFound();
  }
  if (!record) {
    return Response.json({ error: 'invalid_app' }, { status: 401 });
  }
  const key = request.headers.get('X-Dply-Key');
  const secret = request.headers.get('X-Dply-Secret');
  if (!key || !secret || !timingSafeEqual(key, record.key) || !timingSafeEqual(secret, record.secret)) {
    return Response.json({ error: 'unauthorized' }, { status: 401 });
  }
  return record;
}

/**
 * True on a per-app host ({label}.{APP_HOST_SUFFIX}) unless the record names
 * exactly this host. Unknown apps count as wrong, so the 404 never says
 * whether an app exists. Other hosts (and an unset suffix) never check.
 */
function wrongHost(env: Env, url: URL, record: AppRecord | null): boolean {
  const suffix = (env.APP_HOST_SUFFIX ?? '').trim().toLowerCase().replace(/^\.+/, '');
  const host = url.hostname.toLowerCase();
  if (suffix === '' || !host.endsWith(`.${suffix}`)) {
    return false;
  }
  return (record?.hostname ?? '').toLowerCase() !== host;
}

function hostNotFound(): Response {
  return Response.json({ error: 'invalid_app_key' }, { status: 404 });
}

function hubFor(env: Env, appId: string, shard = 0): DurableObjectStub {
  return env.APP_HUB.get(env.APP_HUB.idFromName(hubName(appId, shard)));
}

/** The same internal call on every shard, in parallel. */
function fanOut(env: Env, appId: string, shards: number, request: (shard: number) => Request): Promise<Response[]> {
  return Promise.all(Array.from({ length: shards }, (_, n) => hubFor(env, appId, n).fetch(request(n))));
}

/** Every shard's JSON body, or the first failed response as-is. */
async function jsonFromAll(responses: Response[]): Promise<Array<Record<string, number>> | Response> {
  const failed = responses.find((r) => !r.ok);
  return failed ?? ((await Promise.all(responses.map((r) => r.json()))) as Array<Record<string, number>>);
}

function sum<K extends string>(bodies: Array<Record<K, number>>, key: K): number {
  return bodies.reduce((total, b) => total + (Number(b[key]) || 0), 0);
}

async function lookupAppByKey(env: Env, appKey: string): Promise<AppRecord | null> {
  return env.APPS.get<AppRecord>(`key:${appKey}`, 'json');
}

async function lookupAppById(env: Env, appId: string): Promise<AppRecord | null> {
  return env.APPS.get<AppRecord>(`id:${appId}`, 'json');
}
