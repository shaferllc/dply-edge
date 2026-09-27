import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { AppHub, buildStats } from './hub';
import { appLimits, hubName, originAllowed, payloadBytes, shardCount } from './protocol';
import { channelAuthToken } from './auth';
import worker from './index';
import type { AppRecord, Env } from './types';

// Minimal stand-ins for the hibernation API: the hub only touches
// getWebSockets / storage / setAlarm on the state, and send / close /
// (de)serializeAttachment / readyState on a socket.
class FakeSocket {
  sent: Array<Record<string, unknown>> = [];
  readyState = 1;
  constructor(private attachment: unknown) {}
  send(frame: string) {
    this.sent.push(JSON.parse(frame));
  }
  closeCode?: number;
  closeReason?: string;
  close(code?: number, reason?: string) {
    this.readyState = 3;
    this.closeCode = code;
    this.closeReason = reason;
  }
  serializeAttachment(a: unknown) {
    this.attachment = structuredClone(a);
  }
  deserializeAttachment() {
    return structuredClone(this.attachment);
  }
}

function fakeState() {
  const data = new Map<string, unknown>();
  const sockets: FakeSocket[] = [];
  const state = {
    sockets,
    data,
    alarm: null as number | null,
    getWebSockets: () => sockets,
    storage: {
      get: async (k: string) => structuredClone(data.get(k)),
      put: async (k: string, v: unknown) => void data.set(k, structuredClone(v)),
      setAlarm: async (t: number) => void (state.alarm = t),
    },
    acceptWebSocket: (ws: FakeSocket) => void sockets.push(ws),
  };
  return state;
}

function hubWith(state: ReturnType<typeof fakeState>) {
  return new AppHub(state as unknown as DurableObjectState, {} as Env);
}

function addSocket(
  state: ReturnType<typeof fakeState>,
  socketId: string,
  channels: Record<string, unknown>,
  extra: Record<string, unknown> = {},
) {
  const ws = new FakeSocket({ socketId, channels, connectedAt: Date.now(), ...extra });
  state.sockets.push(ws);
  return ws;
}

async function stats(hub: AppHub) {
  return (await hub.fetch(new Request('https://hub/internal/stats', { method: 'POST' }))).json() as Promise<
    Record<string, number>
  >;
}

function publish(hub: AppHub, body: unknown, maxBytes?: number) {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  if (maxBytes) headers['X-App-Max-Message-Bytes'] = String(maxBytes);
  return hub.fetch(new Request('https://hub/internal/publish', { method: 'POST', headers, body: JSON.stringify(body) }));
}

const asWs = (s: FakeSocket) => s as unknown as WebSocket;

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(1_790_000_000_000);
  vi.spyOn(console, 'log').mockImplementation(() => {});
});
afterEach(() => {
  vi.useRealTimers();
  vi.restoreAllMocks();
});

describe('app limits (KV record defaults)', () => {
  it('gives an old record without the new fields the contract defaults', () => {
    const old: AppRecord = { id: 'a', key: 'k', secret: 's', enabled: true, maxConnections: 200 };
    expect(appLimits(old)).toEqual({ allowedOrigins: [], clientEvents: false, maxMessageBytes: 10240 });
  });

  it('reads the new fields when present', () => {
    expect(appLimits({ allowedOrigins: ['https://a.test'], clientEvents: true, maxMessageBytes: 99 })).toEqual({
      allowedOrigins: ['https://a.test'],
      clientEvents: true,
      maxMessageBytes: 99,
    });
  });

  it('matches origins exactly, any origin when the list is empty', () => {
    expect(originAllowed([], null)).toBe(true);
    expect(originAllowed([], 'https://x.test')).toBe(true);
    expect(originAllowed(['https://a.test'], 'https://a.test')).toBe(true);
    expect(originAllowed(['https://a.test'], 'https://a.test.evil')).toBe(false);
    expect(originAllowed(['https://a.test'], 'http://a.test')).toBe(false);
    expect(originAllowed(['https://a.test'], null)).toBe(false);
  });

  it('measures payloads in UTF-8 bytes as sent on the wire', () => {
    expect(payloadBytes('abc')).toBe(3);
    expect(payloadBytes('é')).toBe(2);
    expect(payloadBytes({ a: 1 })).toBe(7);
  });
});

describe('worker connect origin check', () => {
  function envWith(record: AppRecord) {
    const hubFetch = vi.fn(async (_req: Request) => new Response(null, { status: 200 }));
    const env = {
      APPS: { get: async (k: string) => (k === `key:${record.key}` ? record : null) },
      APP_HUB: { idFromName: (n: string) => n, get: () => ({ fetch: hubFetch }) },
    } as unknown as Env;
    return { env, hubFetch };
  }
  const connect = (env: Env, origin?: string) =>
    worker.fetch(
      new Request('https://relay/app/k1', {
        headers: { Upgrade: 'websocket', ...(origin ? { Origin: origin } : {}) },
      }),
      env,
    );

  it('rejects a disallowed origin with 403 before the hub is touched', async () => {
    const { env, hubFetch } = envWith({ id: 'a', key: 'k1', secret: 's', enabled: true, allowedOrigins: ['https://ok.test'] });
    const res = await connect(env, 'https://bad.test');
    expect(res.status).toBe(403);
    expect(((await res.json()) as { code: number }).code).toBe(4009);
    expect(hubFetch).not.toHaveBeenCalled();
    expect((await connect(env, 'https://ok.test')).status).toBe(200);
  });

  it('lets an old record (no allowedOrigins) connect from anywhere and forwards default limits', async () => {
    const { env, hubFetch } = envWith({ id: 'a', key: 'k1', secret: 's', enabled: true });
    expect((await connect(env, 'https://anything.test')).status).toBe(200);
    const forwarded = hubFetch.mock.calls[0][0];
    expect(forwarded.headers.get('X-App-Client-Events')).toBe('0');
    expect(forwarded.headers.get('X-App-Max-Message-Bytes')).toBe('10240');
  });
});

describe('worker stats route', () => {
  const record: AppRecord = { id: 'a', key: 'k1', secret: 's1', enabled: true };
  const hubFetch = vi.fn(async (_req: Request) => Response.json({ ok: true }));
  const env = {
    APPS: { get: async (k: string) => (k === 'id:a' ? record : null) },
    APP_HUB: { idFromName: (n: string) => n, get: () => ({ fetch: hubFetch }) },
  } as unknown as Env;
  const call = (method: string, secret = 's1', path = '/apps/a/stats') =>
    worker.fetch(new Request(`https://relay${path}`, { method, headers: { 'X-Dply-Key': 'k1', 'X-Dply-Secret': secret } }), env);

  it('serves GET and POST with header auth, and reset', async () => {
    expect((await call('GET')).status).toBe(200);
    expect((await call('POST')).status).toBe(200);
    expect((await call('POST', 's1', '/apps/a/stats/reset')).status).toBe(200);
    expect(hubFetch.mock.calls.map(([r]) => new URL(r.url).pathname)).toEqual([
      '/internal/stats',
      '/internal/stats',
      '/internal/stats/reset',
    ]);
    expect((await call('GET', 'wrong')).status).toBe(401);
  });
});

describe('publish size limit', () => {
  it('returns 413 above maxMessageBytes and does not count it', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const sub = addSocket(state, '1.1', { orders: null });
    const res = await publish(hub, { name: 'e', channels: ['orders'], data: 'x'.repeat(11) }, 10);
    expect(res.status).toBe(413);
    expect(sub.sent).toHaveLength(0);
    expect((await stats(hub)).messages_in).toBe(0);

    expect((await publish(hub, { name: 'e', channels: ['orders'], data: 'x'.repeat(10) }, 10)).status).toBe(200);
    expect(sub.sent).toHaveLength(1);
  });

  it('defaults to 10240 bytes when no limit header is passed', async () => {
    const hub = hubWith(fakeState());
    expect((await publish(hub, { name: 'e', channels: ['c'], data: 'x'.repeat(10241) })).status).toBe(413);
    expect((await publish(hub, { name: 'e', channels: ['c'], data: 'x'.repeat(10240) })).status).toBe(200);
  });
});

describe('client events', () => {
  const clientEvent = (data: unknown = { typing: true }) =>
    JSON.stringify({ event: 'client-typing', channel: 'private-chat', data });

  it('fans out to other subscribers only (never the sender) when enabled', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const opts = { clientEvents: true, maxMessageBytes: 10240 };
    const sender = addSocket(state, '1.1', { 'private-chat': null }, opts);
    const peer = addSocket(state, '2.2', { 'private-chat': null }, opts);
    const outsider = addSocket(state, '3.3', { 'private-other': null }, opts);

    await hub.webSocketMessage(asWs(sender), clientEvent());

    expect(sender.sent).toHaveLength(0);
    expect(outsider.sent).toHaveLength(0);
    expect(peer.sent).toEqual([{ event: 'client-typing', channel: 'private-chat', data: '{"typing":true}' }]);
    const s = await stats(hub);
    expect(s.messages_in).toBe(1);
    expect(s.messages_out).toBe(1);
  });

  it('replies pusher:error 4301 when the app has client events off (the default)', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    // Old attachment (no clientEvents field) — off.
    const sender = addSocket(state, '1.1', { 'private-chat': null });
    const peer = addSocket(state, '2.2', { 'private-chat': null });

    await hub.webSocketMessage(asWs(sender), clientEvent());

    expect(peer.sent).toHaveLength(0);
    expect(sender.sent[0].event).toBe('pusher:error');
    expect(JSON.parse(sender.sent[0].data as string).code).toBe(4301);
  });

  it('rejects oversized client events', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const opts = { clientEvents: true, maxMessageBytes: 5 };
    const sender = addSocket(state, '1.1', { 'private-chat': null }, opts);
    const peer = addSocket(state, '2.2', { 'private-chat': null }, opts);

    await hub.webSocketMessage(asWs(sender), clientEvent('123456'));

    expect(peer.sent).toHaveLength(0);
    expect(JSON.parse(sender.sent[0].data as string).code).toBe(4301);
  });

  it('ignores client events on public or unsubscribed channels', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const opts = { clientEvents: true };
    const sender = addSocket(state, '1.1', { chat: null }, opts);
    const peer = addSocket(state, '2.2', { chat: null, 'private-chat': null }, opts);

    await hub.webSocketMessage(asWs(sender), JSON.stringify({ event: 'client-x', channel: 'chat', data: {} }));
    await hub.webSocketMessage(asWs(sender), clientEvent());

    expect(peer.sent).toHaveLength(0);
  });
});

describe('usage counters', () => {
  it('buildStats returns exactly the contract shape (plus legacy peakConnections)', () => {
    const body = buildStats({ closedMs: 5_500, messagesIn: 3, messagesOut: 7 }, [1_000, 4_000], 1, 10_000);
    expect(body).toEqual({
      connections: 2,
      peak_connections: 2,
      connection_seconds: 20, // 5.5 closed + 9 + 6 open = 20.5 -> floor
      messages_in: 3,
      messages_out: 7,
      updated_at: 10,
      peakConnections: 2,
    });
  });

  it('counts open sockets at read time and moves them to closed without double counting', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const a = addSocket(state, '1.1', {});
    vi.advanceTimersByTime(10_000);
    addSocket(state, '2.2', {});
    vi.advanceTimersByTime(5_000);

    expect((await stats(hub)).connection_seconds).toBe(15 + 5);

    // a closes at t=15s (its 15s moves from "open" to "closed").
    await hub.webSocketClose(asWs(a), 1000);
    // close + error for the same socket must count once.
    await hub.webSocketError(asWs(a));
    state.sockets.splice(0, 1);
    let s = await stats(hub);
    expect(s.connection_seconds).toBe(15 + 5);
    expect(s.connections).toBe(1);

    vi.advanceTimersByTime(3_000);
    s = await stats(hub);
    expect(s.connection_seconds).toBe(15 + 8);
    expect(s.peak_connections).toBe(1); // never below the live count
  });

  it('excludes a closed socket the runtime still lists', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const a = addSocket(state, '1.1', {});
    vi.advanceTimersByTime(4_000);
    await hub.webSocketClose(asWs(a), 1006);
    vi.advanceTimersByTime(60_000);
    const s = await stats(hub);
    expect(s.connections).toBe(0);
    expect(s.connection_seconds).toBe(4);
  });

  it('persists counts via the alarm flush and stays monotonic across evictions', async () => {
    const state = fakeState();
    let hub = hubWith(state);
    addSocket(state, '1.1', { orders: null });
    addSocket(state, '2.2', { orders: null });

    await publish(hub, { name: 'e', channels: ['orders'], data: {} });
    expect(state.alarm).toBe(Date.now() + 5000);
    expect(state.data.get('usage')).toBeUndefined(); // accumulated in memory
    await hub.alarm();
    expect(state.data.get('usage')).toEqual({ closedMs: 0, messagesIn: 1, messagesOut: 2 });

    // Evict: a fresh instance over the same storage keeps the totals.
    hub = hubWith(state);
    await publish(hub, { name: 'e', channels: ['orders'], data: {} });
    const first = await stats(hub);
    expect(first.messages_in).toBe(2);
    expect(first.messages_out).toBe(4);

    hub = hubWith(state);
    const second = await stats(hub);
    expect(second.messages_in).toBe(2);
    expect(second.messages_out).toBe(4);
    expect(second.connection_seconds).toBeGreaterThanOrEqual(first.connection_seconds);
  });

  it('reset clears the peak only, never the totals', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    state.data.set('peakConnections', 40);
    addSocket(state, '1.1', { orders: null });
    await publish(hub, { name: 'e', channels: ['orders'], data: {} });

    expect((await stats(hub)).peak_connections).toBe(40);
    const reset = (await (
      await hub.fetch(new Request('https://hub/internal/stats/reset', { method: 'POST' }))
    ).json()) as Record<string, number>;
    expect(reset.peak_connections).toBe(1);
    const s = await stats(hub);
    expect(s.peak_connections).toBe(1);
    expect(s.messages_in).toBe(1);
    expect(s.messages_out).toBe(1);
  });
});

describe('disconnect (dply sleeps or deletes the app)', () => {
  const record: AppRecord = { id: 'a', key: 'k1', secret: 's1', enabled: true };
  const hubFetch = vi.fn(async (_req: Request) => Response.json({ ok: true, closed: 0 }));
  const env = {
    APPS: { get: async (k: string) => (k === 'id:a' ? record : null) },
    APP_HUB: { idFromName: (n: string) => n, get: () => ({ fetch: hubFetch }) },
  } as unknown as Env;
  const call = (method: string, secret = 's1') =>
    worker.fetch(new Request('https://relay/apps/a/disconnect', { method, headers: { 'X-Dply-Key': 'k1', 'X-Dply-Secret': secret } }), env);

  it('routes POST with header auth to the hub; refuses a wrong secret and GET', async () => {
    expect((await call('POST')).status).toBe(200);
    expect(new URL(hubFetch.mock.calls[0][0].url).pathname).toBe('/internal/disconnect');
    expect((await call('POST', 'wrong')).status).toBe(401);
    expect((await call('GET')).status).toBe(404);
    expect(hubFetch).toHaveBeenCalledTimes(1);
  });

  it('closes every socket with 4003, counts their time, and bills no presence goodbyes', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const a = addSocket(state, '1.1', { 'presence-room': { user_id: 'u1' } });
    const b = addSocket(state, '2.2', { 'presence-room': { user_id: 'u2' }, news: null });
    vi.advanceTimersByTime(30_000);

    const res = (await (await hub.fetch(new Request('https://hub/internal/disconnect', { method: 'POST' }))).json()) as { closed: number };

    expect(res.closed).toBe(2);
    for (const ws of [a, b]) {
      expect(ws.sent).toEqual([{ event: 'pusher:error', data: JSON.stringify({ code: 4003, message: 'Application disabled' }) }]);
      expect(ws.closeCode).toBe(4003);
    }
    // The runtime's close callbacks must not count them again or fan out.
    await hub.webSocketClose(asWs(a), 4003);
    await hub.webSocketClose(asWs(b), 4003);
    const s = await stats(hub);
    expect(s.connections).toBe(0);
    expect(s.connection_seconds).toBe(60);
    expect(s.messages_out).toBe(0);
  });
});

describe('connect refusals', () => {
  function envWith(record: AppRecord) {
    const hubFetch = vi.fn(async (_req: Request) => new Response(null, { status: 200 }));
    const env = {
      APPS: { get: async (k: string) => (k === `key:${record.key}` ? record : null) },
      APP_HUB: { idFromName: (n: string) => n, get: () => ({ fetch: hubFetch }) },
    } as unknown as Env;
    return { env, hubFetch };
  }
  const connect = (env: Env) => worker.fetch(new Request('https://relay/app/k1', { headers: { Upgrade: 'websocket' } }), env);

  it('rejects a disabled app before the hub is touched', async () => {
    const { env, hubFetch } = envWith({ id: 'a', key: 'k1', secret: 's', enabled: false });
    expect((await connect(env)).status).toBe(401);
    expect(hubFetch).not.toHaveBeenCalled();
  });

  it('forwards maxConnections, and the hub refuses past it with 4004', async () => {
    const { env, hubFetch } = envWith({ id: 'a', key: 'k1', secret: 's', enabled: true, maxConnections: 1 });
    await connect(env);
    expect(hubFetch.mock.calls[0][0].headers.get('X-App-Max-Connections')).toBe('1');

    const state = fakeState();
    addSocket(state, '1.1', {});
    const res = await hubWith(state).fetch(
      new Request('https://hub/app/k1', { headers: { Upgrade: 'websocket', 'X-App-Max-Connections': '1' } }),
    );
    expect(res.status).toBe(403);
    expect(((await res.json()) as { code: number }).code).toBe(4004);
  });
});

describe('per-app hosts', () => {
  const record: AppRecord = { id: 'a', key: 'k1', secret: 's1', enabled: true, hostname: 'shop.realtime.dply.io' };
  function envWith(suffix: string | undefined, rec: AppRecord = record) {
    const hubFetch = vi.fn(async (_req: Request) => Response.json({ ok: true }));
    const env = {
      APPS: { get: async (k: string) => (k === `key:${rec.key}` || k === `id:${rec.id}` ? rec : null) },
      APP_HUB: { idFromName: (n: string) => n, get: () => ({ fetch: hubFetch }) },
      APP_HOST_SUFFIX: suffix,
    } as unknown as Env;
    return { env, hubFetch };
  }
  const connect = (env: Env, host: string, key = 'k1') =>
    worker.fetch(new Request(`https://${host}/app/${key}`, { headers: { Upgrade: 'websocket' } }), env);
  const operator = (env: Env, host: string, path: string) =>
    worker.fetch(new Request(`https://${host}${path}`, { method: 'POST', headers: { 'X-Dply-Key': 'k1', 'X-Dply-Secret': 's1' } }), env);

  it('connects on the app own host, case-insensitively', async () => {
    const { env, hubFetch } = envWith('realtime.dply.io');
    expect((await connect(env, 'shop.realtime.dply.io')).status).toBe(200);
    expect((await connect(env, 'SHOP.Realtime.dply.io')).status).toBe(200);
    expect(hubFetch).toHaveBeenCalledTimes(2);
  });

  it('404s another app host, and an unknown key the same way', async () => {
    const { env, hubFetch } = envWith('realtime.dply.io');
    const wrong = await connect(env, 'other.realtime.dply.io');
    expect(wrong.status).toBe(404);
    expect(await wrong.json()).toEqual({ error: 'invalid_app_key' });
    const unknown = await connect(env, 'shop.realtime.dply.io', 'nope');
    expect(unknown.status).toBe(404);
    expect(await unknown.json()).toEqual({ error: 'invalid_app_key' });
    expect(hubFetch).not.toHaveBeenCalled();
  });

  it('404s a record without a hostname on a per-app host', async () => {
    const { env } = envWith('realtime.dply.io', { id: 'a', key: 'k1', secret: 's1', enabled: true });
    expect((await connect(env, 'shop.realtime.dply.io')).status).toBe(404);
  });

  it('checks /apps/{id}/… routes the same way', async () => {
    const { env } = envWith('realtime.dply.io');
    expect((await operator(env, 'shop.realtime.dply.io', '/apps/a/stats')).status).toBe(200);
    expect((await operator(env, 'shop.realtime.dply.io', '/apps/a/disconnect')).status).toBe(200);
    expect((await operator(env, 'other.realtime.dply.io', '/apps/a/stats')).status).toBe(404);
    expect((await operator(env, 'other.realtime.dply.io', '/apps/a/disconnect')).status).toBe(404);
    const publish = await worker.fetch(new Request('https://other.realtime.dply.io/apps/a/events', { method: 'POST', body: '{}' }), env);
    expect(publish.status).toBe(404);
    expect(await publish.json()).toEqual({ error: 'invalid_app_key' });
    // On its own host an unsigned publish gets as far as signature checking.
    expect((await worker.fetch(new Request('https://shop.realtime.dply.io/apps/a/events', { method: 'POST', body: '{}' }), env)).status).toBe(401);
  });

  it('keeps the shared host and workers.dev serving every app', async () => {
    const { env } = envWith('realtime.dply.io');
    expect((await connect(env, 'realtime-apps.on-dply.site')).status).toBe(200);
    expect((await connect(env, 'dply-realtime-apps.x.workers.dev')).status).toBe(200);
    expect((await connect(env, 'realtime.dply.io')).status).toBe(200);
    expect((await operator(env, 'realtime-apps.on-dply.site', '/apps/a/stats')).status).toBe(200);
    expect((await connect(env, 'realtime-apps.on-dply.site', 'nope')).status).toBe(401);
  });

  it('never checks without APP_HOST_SUFFIX (the control-plane relay)', async () => {
    const { env } = envWith(undefined, { id: 'a', key: 'k1', secret: 's1', enabled: true });
    expect((await connect(env, 'shop.realtime.dply.io')).status).toBe(200);
  });
});

describe('channel index', () => {
  const subscribe = (hub: AppHub, ws: FakeSocket, channel: string) =>
    hub.webSocketMessage(asWs(ws), JSON.stringify({ event: 'pusher:subscribe', data: { channel } }));
  const unsubscribe = (hub: AppHub, ws: FakeSocket, channel: string) =>
    hub.webSocketMessage(asWs(ws), JSON.stringify({ event: 'pusher:unsubscribe', data: { channel } }));
  const events = (ws: FakeSocket) => ws.sent.map((f) => f.event);

  it('broadcasts only to subscribers, without reading other sockets', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const a = addSocket(state, '1.1', { orders: null });
    const b = addSocket(state, '2.2', { orders: null, news: null });
    const c = addSocket(state, '3.3', { news: null });

    const first = (await (await publish(hub, { name: 'e', channels: ['orders'], data: {} })).json()) as { delivered: number };
    expect(first.delivered).toBe(2);
    expect(c.sent).toHaveLength(0);

    // Index built: a publish no longer deserializes any attachment.
    const reads = [a, b, c].map((ws) => vi.spyOn(ws, 'deserializeAttachment'));
    await publish(hub, { name: 'e', channels: ['news'], data: {} });
    expect(reads.every((r) => r.mock.calls.length === 0)).toBe(true);
    expect([a.sent.length, b.sent.length, c.sent.length]).toEqual([1, 2, 1]);
    expect((await stats(hub)).messages_out).toBe(4);
  });

  it('rebuilds from attachments after a wake (fresh instance over the same sockets)', async () => {
    const state = fakeState();
    let hub = hubWith(state);
    const a = addSocket(state, '1.1', {});
    const b = addSocket(state, '2.2', {});
    await publish(hub, { name: 'e', channels: ['orders'], data: {} }); // builds the index early
    await subscribe(hub, a, 'orders');

    hub = hubWith(state); // hibernated: memory gone, attachments kept
    await publish(hub, { name: 'e', channels: ['orders'], data: {} });
    expect(events(a)).toEqual(['pusher_internal:subscription_succeeded', 'e']);
    expect(b.sent).toHaveLength(0);

    // Subscribing on the woken instance updates the rebuilt index.
    await subscribe(hub, b, 'orders');
    await publish(hub, { name: 'e2', channels: ['orders'], data: {} });
    expect(events(a).at(-1)).toBe('e2');
    expect(events(b).at(-1)).toBe('e2');
  });

  it('drops a socket from the index on unsubscribe and on close', async () => {
    const state = fakeState();
    const hub = hubWith(state);
    const a = addSocket(state, '1.1', { orders: null });
    const b = addSocket(state, '2.2', { orders: null });
    const c = addSocket(state, '3.3', { orders: null });

    await unsubscribe(hub, a, 'orders');
    await hub.webSocketClose(asWs(b), 1000); // runtime may still list it
    const res = (await (await publish(hub, { name: 'e', channels: ['orders'], data: {} })).json()) as { delivered: number };

    expect(res.delivered).toBe(1);
    expect([a.sent.length, b.sent.length, c.sent.length]).toEqual([0, 0, 1]);
  });

  it('keeps presence joins and leaves exact across a wake', async () => {
    const state = fakeState();
    let hub = hubWith(state);
    const a = addSocket(state, '1.1', { 'presence-room': { user_id: 'u1' } });
    const a2 = addSocket(state, '2.2', { 'presence-room': { user_id: 'u1' } });
    const b = addSocket(state, '3.3', { 'presence-room': { user_id: 'u2' } });

    hub = hubWith(state);
    await hub.webSocketClose(asWs(a), 1000); // u1 still has a2
    expect(b.sent).toHaveLength(0);
    await hub.webSocketClose(asWs(a2), 1000);
    expect(b.sent).toEqual([
      { event: 'pusher_internal:member_removed', channel: 'presence-room', data: JSON.stringify({ user_id: 'u1' }) },
    ]);
    expect(a.sent).toHaveLength(0);
  });
});

describe('connect-path storage writes', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'WebSocketPair',
      class {
        0 = new FakeSocket(null);
        1 = new FakeSocket(null);
      },
    );
    // Node's Response refuses 101; the hub only needs one back.
    const Base = globalThis.Response;
    vi.stubGlobal(
      'Response',
      class extends Base {
        constructor(body?: BodyInit | null, init?: ResponseInit) {
          super(body, init?.status === 101 ? { status: 200 } : init);
        }
      },
    );
  });
  afterEach(() => vi.unstubAllGlobals());

  const connect = (hub: AppHub, secret = 's1') =>
    hub.fetch(
      new Request('https://hub/app/k1', {
        headers: { Upgrade: 'websocket', 'X-App-Id': 'a', 'X-App-Key': 'k1', 'X-App-Secret': secret },
      }),
    );

  it('writes creds only when they change and the peak only when it rises', async () => {
    const state = fakeState();
    const puts = vi.spyOn(state.storage, 'put');
    const writes = () => puts.mock.calls.map(([k, v]) => `${k}=${k === 'app' ? (v as { secret: string }).secret : v}`);
    let hub = hubWith(state);

    await connect(hub);
    await connect(hub);
    expect(writes()).toEqual(['app=s1', 'peakConnections=1', 'peakConnections=2']);

    // One leaves, one joins: live count back to 2, no write.
    const first = state.sockets[0];
    await hub.webSocketClose(asWs(first), 1000);
    state.sockets.splice(0, 1);
    await connect(hub);
    expect(writes()).toHaveLength(3);

    await connect(hub, 's2'); // rotated secret
    expect(writes().slice(3)).toEqual(['app=s2', 'peakConnections=3']);

    // Reset per billing window writes the live count; the next rise writes again.
    await hub.fetch(new Request('https://hub/internal/stats/reset', { method: 'POST' }));
    expect(state.data.get('peakConnections')).toBe(3);
    puts.mockClear();

    // Wake: loaded once from storage, still no redundant writes.
    hub = hubWith(state);
    await connect(hub, 's2');
    expect(writes()).toEqual(['peakConnections=4']);
    expect((await stats(hub)).peak_connections).toBe(4);
  });
});

describe('sharded apps', () => {
  const record: AppRecord = { id: 'a', key: 'k1', secret: 's1', enabled: true, shards: 2 };

  // A namespace whose stubs are real hubs over fake states, all sharing one env.
  function sharded(rec: AppRecord = record) {
    const hubs = new Map<string, { hub: AppHub; state: ReturnType<typeof fakeState> }>();
    const env = {
      APPS: { get: async (k: string) => (k === 'id:a' || k === 'key:k1' ? rec : null) },
      APP_HUB: { idFromName: (n: string) => n, get: (n: string) => ({ fetch: (r: Request) => hubs.get(n)!.hub.fetch(r) }) },
    } as unknown as Env;
    for (let n = 0; n < shardCount(rec.shards); n++) {
      const state = fakeState();
      state.data.set('app', { id: 'a', key: 'k1', secret: 's1', enabled: true, shard: n, shards: shardCount(rec.shards) });
      hubs.set(hubName('a', n), { hub: new AppHub(state as unknown as DurableObjectState, env), state });
    }
    return { env, shard: (n: number) => hubs.get(hubName('a', n))! };
  }
  const operator = (env: Env, path: string, init: RequestInit = {}) =>
    worker.fetch(
      new Request(`https://relay/apps/a${path}`, { method: 'POST', ...init, headers: { 'X-Dply-Key': 'k1', 'X-Dply-Secret': 's1', ...init.headers } }),
      env,
    );
  const json = async (res: Response | Promise<Response>) => (await res).json() as Promise<Record<string, number>>;

  it('names shard 0 as the app id and clamps the count', () => {
    expect(hubName('a', 0)).toBe('a');
    expect(hubName('a', 3)).toBe('a:3');
    expect([undefined, 0, 1, '2', 2.7, -4, 'x', 500].map(shardCount)).toEqual([1, 1, 1, 2, 2, 1, 1, 32]);
  });

  it('publishes to sockets on every shard, counting the publish once', async () => {
    const { env, shard } = sharded();
    const a = addSocket(shard(0).state, '1.1', { orders: null });
    const b = addSocket(shard(1).state, '2.2', { orders: null });

    const res = await json(operator(env, '/events', { body: JSON.stringify({ name: 'e', channels: ['orders'], data: {} }) }));
    expect(res).toEqual({ ok: true, channels: 1, delivered: 2 });
    expect([a.sent.length, b.sent.length]).toEqual([1, 1]);

    const s = await json(operator(env, '/stats'));
    expect(s.messages_in).toBe(1);
    expect(s.messages_out).toBe(2);
  });

  it('passes a shard 413 straight through', async () => {
    const { env } = sharded();
    const res = await operator(env, '/events', { body: JSON.stringify({ name: 'e', channels: ['c'], data: 'x'.repeat(10241) }) });
    expect(res.status).toBe(413);
  });

  it('carries client events across shards, never back to the sender', async () => {
    const { shard } = sharded();
    const opts = { clientEvents: true };
    const sender = addSocket(shard(0).state, '1.1', { 'private-chat': null }, opts);
    const local = addSocket(shard(0).state, '2.2', { 'private-chat': null }, opts);
    const remote = addSocket(shard(1).state, '3.3', { 'private-chat': null }, opts);

    await shard(0).hub.webSocketMessage(asWs(sender), JSON.stringify({ event: 'client-typing', channel: 'private-chat', data: { t: 1 } }));

    expect(sender.sent).toHaveLength(0);
    expect(local.sent).toEqual([{ event: 'client-typing', channel: 'private-chat', data: '{"t":1}' }]);
    expect(remote.sent).toEqual(local.sent);
  });

  it('merges presence members across shards and removes a user only when gone everywhere', async () => {
    const { shard } = sharded();
    const room = 'presence-room';
    const u1a = addSocket(shard(0).state, '1.1', { [room]: { user_id: 'u1', user_info: { n: 1 } } });
    const u1b = addSocket(shard(1).state, '2.2', { [room]: { user_id: 'u1', user_info: { n: 1 } } });
    const u2 = addSocket(shard(1).state, '3.3', {});
    const u3 = addSocket(shard(0).state, '4.4', {});
    const join = async (n: number, ws: FakeSocket, socketId: string, userId: string) => {
      const channelData = JSON.stringify({ user_id: userId });
      const auth = await channelAuthToken('k1', 's1', socketId, room, channelData);
      await shard(n).hub.webSocketMessage(asWs(ws), JSON.stringify({ event: 'pusher:subscribe', data: { channel: room, auth, channel_data: channelData } }));
    };

    await join(1, u2, '3.3', 'u2');
    const ok = u2.sent.find((f) => f.event === 'pusher_internal:subscription_succeeded')!;
    expect(JSON.parse(ok.data as string).presence).toMatchObject({ count: 2, ids: expect.arrayContaining(['u1', 'u2']) });
    // u1 hears u2 on both shards.
    for (const ws of [u1a, u1b]) {
      expect(ws.sent.map((f) => f.event)).toEqual(['pusher_internal:member_added']);
    }

    // u3 joins on shard 0: sees u1 (local + remote, deduped) and u2 (remote).
    await join(0, u3, '4.4', 'u3');
    const ok3 = u3.sent.find((f) => f.event === 'pusher_internal:subscription_succeeded')!;
    expect(JSON.parse(ok3.data as string).presence.count).toBe(3);

    const removed = (ws: FakeSocket) => ws.sent.filter((f) => f.event === 'pusher_internal:member_removed');
    // u1 leaves shard 0 but is still on shard 1: nobody hears a goodbye.
    await shard(0).hub.webSocketClose(asWs(u1a), 1000);
    expect([removed(u2), removed(u3)]).toEqual([[], []]);
    // Then leaves shard 1 too: everyone, on both shards, hears it once.
    await shard(1).hub.webSocketClose(asWs(u1b), 1000);
    expect(removed(u2)).toEqual([{ event: 'pusher_internal:member_removed', channel: room, data: '{"user_id":"u1"}' }]);
    expect(removed(u3)).toEqual(removed(u2));
  });

  it('aggregates stats and reset across shards', async () => {
    const { env, shard } = sharded();
    shard(0).state.data.set('peakConnections', 5);
    shard(1).state.data.set('peakConnections', 7);
    addSocket(shard(0).state, '1.1', {});
    addSocket(shard(1).state, '2.2', {});
    addSocket(shard(1).state, '3.3', {});
    vi.advanceTimersByTime(10_000);

    expect(await json(operator(env, '/stats', { method: 'GET' }))).toEqual({
      connections: 3,
      peak_connections: 12, // sum of shard peaks: an upper bound
      connection_seconds: 30,
      messages_in: 0,
      messages_out: 0,
      updated_at: Math.floor(Date.now() / 1000),
      peakConnections: 12,
    });
    expect(await json(operator(env, '/stats/reset'))).toEqual({ ok: true, peak_connections: 3, peakConnections: 3 });
  });

  it('disconnects every shard', async () => {
    const { env, shard } = sharded();
    const a = addSocket(shard(0).state, '1.1', {});
    const b = addSocket(shard(1).state, '2.2', {});
    expect(await json(operator(env, '/disconnect'))).toEqual({ ok: true, closed: 2 });
    expect([a.closeCode, b.closeCode]).toEqual([4003, 4003]);
  });

  it('gives each shard its share of the cap and moves on from a full one', async () => {
    const names: string[] = [];
    const env = {
      APPS: { get: async () => ({ ...record, maxConnections: 3 }) },
      APP_HUB: {
        idFromName: (n: string) => n,
        get: (n: string) => ({
          fetch: async (r: Request) => {
            names.push(`${n}@${r.headers.get('X-App-Shard')}/${r.headers.get('X-App-Shards')} max ${r.headers.get('X-App-Max-Connections')}`);
            return new Response(null, { status: n === 'a:1' ? 403 : 200 });
          },
        }),
      },
    } as unknown as Env;
    vi.spyOn(Math, 'random').mockReturnValue(0.9); // picks shard 1
    const res = await worker.fetch(new Request('https://relay/app/k1', { headers: { Upgrade: 'websocket' } }), env);
    expect(res.status).toBe(200);
    expect(names).toEqual(['a:1@1/2 max 2', 'a@0/2 max 2']);
  });
});
