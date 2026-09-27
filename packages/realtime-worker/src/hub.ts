/// <reference types="@cloudflare/workers-types" />

// AppHub — one Durable Object instance per app id. It owns every live
// WebSocket connection for that app and fans out channel + presence messages.
// Connections use the WebSocket Hibernation API: per-connection state lives in
// each socket's attachment, so the DO can evict from memory and rebuild any
// view (subscribers, presence rosters) by scanning getWebSockets().
//
// Sharding: an app with KV `shards` > 1 is spread over hubs named `{appId}`
// (shard 0, the pre-sharding hub) and `{appId}:{n}`. The Worker picks a shard
// per socket and fans publishes/stats/disconnect out to all of them; a hub
// forwards client events and presence joins/leaves to its peers and asks them
// for their presence members. With one shard no peer is ever called.

import {
  buildPresencePayload,
  encode,
  isClientEvent,
  isPresenceChannel,
  channelRequiresAuth,
  DEFAULT_MAX_MESSAGE_BYTES,
  hubName,
  makeSocketId,
  payloadBytes,
  type InboundMessage,
  type PresenceMember,
} from './protocol';
import { verifyChannelAuth, type AppCredentials } from './auth';
import type { Env } from './types';

interface ConnState {
  socketId: string;
  // channel name -> presence member (presence channels) or null (public/private)
  channels: Record<string, PresenceMember | null>;
  // Added with usage metering / app limits; attachments from before lack
  // them (treated as: not metered, client events off, default size cap).
  connectedAt?: number; // epoch ms
  clientEvents?: boolean;
  maxMessageBytes?: number;
}

/** Persisted monotonic totals (DO storage key `usage`). */
export interface UsageTotals {
  closedMs: number; // connected time of sockets that have closed
  messagesIn: number;
  messagesOut: number;
}

export interface StatsBody {
  connections: number;
  peak_connections: number;
  connection_seconds: number;
  messages_in: number;
  messages_out: number;
  updated_at: number;
  /** @deprecated camelCase key the pre-contract relay returned; kept for old dply readers. */
  peakConnections: number;
}

/**
 * Stats JSON from persisted totals + live sockets. Open sockets contribute
 * `now - connectedAt`; when one closes that exact span moves into closedMs,
 * so connection_seconds only ever grows and nothing is counted twice.
 */
export function buildStats(totals: UsageTotals, openConnectedAt: number[], peak: number, now: number): StatsBody {
  const openMs = openConnectedAt.reduce((sum, at) => sum + Math.max(0, now - at), 0);
  const peakConnections = Math.max(peak, openConnectedAt.length);
  return {
    connections: openConnectedAt.length,
    peak_connections: peakConnections,
    connection_seconds: Math.floor((totals.closedMs + openMs) / 1000),
    messages_in: totals.messagesIn,
    messages_out: totals.messagesOut,
    updated_at: Math.floor(now / 1000),
    peakConnections,
  };
}

/** Pending counts are flushed to storage at most this long after they happen. */
const FLUSH_DELAY_MS = 5000;

interface Subscriber {
  socketId: string;
  member: PresenceMember | null;
}

/** Creds plus this hub's place among the app's shards (missing = shard 0 of 1). */
interface HubCredentials extends AppCredentials {
  shard?: number;
  shards?: number;
}

interface PublishPayload {
  name: string;
  channels?: string[];
  channel?: string;
  data?: unknown;
  socket_id?: string;
}

export class AppHub implements DurableObject {
  // Usage deltas not yet in storage (lost on eviction: at most FLUSH_DELAY_MS).
  private pending: UsageTotals = { closedMs: 0, messagesIn: 0, messagesOut: 0 };
  private flushScheduled = false;
  // Sockets already accounted as closed — close + error can both fire for one.
  // ponytail: grows until the DO is evicted; fine at per-app socket churn.
  private dropped = new Set<string>();
  // channel -> subscribed socket -> its socketId + presence member. Memory only:
  // rebuilt from attachments on first use after a wake (null until then).
  private index: Map<string, Map<WebSocket, Subscriber>> | null = null;
  // Storage mirrors, loaded once per wake (undefined = not loaded yet).
  private creds: HubCredentials | null | undefined;
  private peak: number | undefined;

  constructor(
    private readonly state: DurableObjectState,
    private readonly env: Env,
  ) {
    // pusher-js pings every activity_timeout; answering at the edge keeps an idle
    // app hibernated (no wake = no duration or request billed). Byte-exact match.
    if (typeof WebSocketRequestResponsePair !== 'undefined') {
      state.setWebSocketAutoResponse(
        new WebSocketRequestResponsePair('{"event":"pusher:ping","data":{}}', encode('pusher:pong', undefined, {})),
      );
    }
  }

  async fetch(request: Request): Promise<Response> {
    const url = new URL(request.url);

    // Internal calls from the edge Worker (already authenticated there).
    if (url.pathname === '/internal/publish') {
      return this.handlePublish(
        request,
        Number(request.headers.get('X-App-Max-Message-Bytes')) || DEFAULT_MAX_MESSAGE_BYTES,
        request.headers.get('X-Hub-Fanout') !== '1',
      );
    }
    // Peer shards of the same app (see Sharding above).
    if (url.pathname === '/internal/members') {
      return Response.json({ members: [...this.presenceMembers(url.searchParams.get('channel') ?? '')] });
    }
    if (url.pathname === '/internal/relay') {
      const { channel, frame } = (await request.json()) as { channel: string; frame: string };
      return Response.json({ delivered: this.broadcast(channel, frame) });
    }
    if (url.pathname === '/internal/stats') {
      return this.handleStats();
    }
    if (url.pathname === '/internal/stats/reset') {
      return this.handleStatsReset();
    }
    if (url.pathname === '/internal/disconnect') {
      return this.handleDisconnect();
    }

    if (request.headers.get('Upgrade') !== 'websocket') {
      return new Response('expected websocket', { status: 426 });
    }
    return this.handleConnect(request);
  }

  // --- WebSocket lifecycle ------------------------------------------------

  private async handleConnect(request: Request): Promise<Response> {
    // The KV record's maxConnections (forwarded by the edge Worker). Refused
    // before the upgrade, with Pusher's over-quota code in the body.
    const maxHeader = request.headers.get('X-App-Max-Connections');
    if (maxHeader !== null && Number.isFinite(Number(maxHeader)) && this.liveSockets().length >= Number(maxHeader)) {
      console.log({ src: 'realtime', do: 'AppHub', event: 'connect_rejected', reason: 'over_quota', max: Number(maxHeader) });
      return Response.json({ error: 'over_quota', code: 4004, message: 'Over connection quota' }, { status: 403 });
    }
    // The edge Worker forwards the resolved app credentials as headers. Persist
    // them so hibernated message handlers can verify channel auth — only when
    // they changed (new app, rotated secret), not on every connect.
    const creds: HubCredentials = {
      id: request.headers.get('X-App-Id') ?? '',
      key: request.headers.get('X-App-Key') ?? '',
      secret: request.headers.get('X-App-Secret') ?? '',
      enabled: true,
      shard: Number(request.headers.get('X-App-Shard')) || 0,
      shards: Number(request.headers.get('X-App-Shards')) || 1,
    };
    const known = await this.loadCreds();
    if (
      !known ||
      known.id !== creds.id ||
      known.key !== creds.key ||
      known.secret !== creds.secret ||
      (known.shard ?? 0) !== creds.shard ||
      (known.shards ?? 1) !== creds.shards
    ) {
      this.creds = creds;
      await this.state.storage.put('app', creds);
    }

    const pair = new WebSocketPair();
    const client = pair[0];
    const server = pair[1];

    this.state.acceptWebSocket(server);
    const socketId = makeSocketId();
    const conn: ConnState = {
      socketId,
      channels: {},
      connectedAt: Date.now(),
      clientEvents: request.headers.get('X-App-Client-Events') === '1',
      maxMessageBytes: Number(request.headers.get('X-App-Max-Message-Bytes')) || DEFAULT_MAX_MESSAGE_BYTES,
    };
    server.serializeAttachment(conn);
    await this.recordPeak();

    server.send(
      encode('pusher:connection_established', undefined, {
        socket_id: socketId,
        activity_timeout: 120,
      }),
    );

    console.log({ src: 'realtime', do: 'AppHub', event: 'connected', socketId, connections: this.liveSockets().length });

    return new Response(null, { status: 101, webSocket: client });
  }

  // --- Usage stats (for billing) -----------------------------------------

  /** Update the high-water mark of concurrent connections for this window (written only when it rises). */
  private async recordPeak(): Promise<void> {
    const current = this.liveSockets().length;
    if (current > (await this.loadPeak())) {
      this.peak = current;
      await this.state.storage.put('peakConnections', current);
    }
  }

  private async loadPeak(): Promise<number> {
    this.peak ??= (await this.state.storage.get<number>('peakConnections')) ?? 0;
    return this.peak;
  }

  private count(delta: Partial<UsageTotals>): void {
    this.pending.closedMs += delta.closedMs ?? 0;
    this.pending.messagesIn += delta.messagesIn ?? 0;
    this.pending.messagesOut += delta.messagesOut ?? 0;
    if (!this.flushScheduled) {
      this.flushScheduled = true;
      void this.state.storage.setAlarm(Date.now() + FLUSH_DELAY_MS);
    }
  }

  async alarm(): Promise<void> {
    this.flushScheduled = false;
    await this.flush();
  }

  /** Move pending deltas into the persisted totals; returns the new totals. */
  private async flush(): Promise<UsageTotals> {
    const stored = (await this.state.storage.get<UsageTotals>('usage')) ?? { closedMs: 0, messagesIn: 0, messagesOut: 0 };
    const p = this.pending;
    if (p.closedMs === 0 && p.messagesIn === 0 && p.messagesOut === 0) {
      return stored;
    }
    const totals: UsageTotals = {
      closedMs: stored.closedMs + p.closedMs,
      messagesIn: stored.messagesIn + p.messagesIn,
      messagesOut: stored.messagesOut + p.messagesOut,
    };
    this.pending = { closedMs: 0, messagesIn: 0, messagesOut: 0 };
    await this.state.storage.put('usage', totals);
    return totals;
  }

  private async handleStats(): Promise<Response> {
    const totals = await this.flush();
    const peak = await this.loadPeak();
    const open = this.liveSockets().map((ws) => this.attachmentOf(ws)?.connectedAt ?? Date.now());
    return Response.json(buildStats(totals, open, peak, Date.now()));
  }

  /** Reset the peak to the current live count — dply calls this per billing window. Totals never reset. */
  private async handleStatsReset(): Promise<Response> {
    const current = this.liveSockets().length;
    this.peak = current;
    await this.state.storage.put('peakConnections', current);
    return Response.json({ ok: true, peak_connections: current, peakConnections: current });
  }

  /**
   * Close every open socket: Pusher error 4003 (application disabled — a
   * 4000-4099 code, so pusher-js does not reconnect), then close. Each
   * socket's connected time is counted here and it is marked dropped, so the
   * close callbacks neither count it again nor fan out presence
   * member_removed frames (which would bill messages for a shutdown).
   */
  private async handleDisconnect(): Promise<Response> {
    const now = Date.now();
    let closed = 0;
    for (const ws of this.liveSockets()) {
      const conn = this.attachmentOf(ws);
      if (conn) {
        this.dropped.add(conn.socketId);
        this.unindex(ws, Object.keys(conn.channels));
        if (conn.connectedAt) {
          this.count({ closedMs: Math.max(0, now - conn.connectedAt) });
        }
      }
      try {
        ws.send(encode('pusher:error', undefined, { code: 4003, message: 'Application disabled' }));
        ws.close(4003, 'Application disabled');
      } catch {
        // already closing
      }
      closed++;
    }
    await this.flush();
    return Response.json({ ok: true, closed });
  }

  async webSocketMessage(ws: WebSocket, raw: string | ArrayBuffer): Promise<void> {
    let msg: InboundMessage;
    try {
      msg = JSON.parse(typeof raw === 'string' ? raw : new TextDecoder().decode(raw));
    } catch {
      return; // ignore malformed frames, matching Pusher leniency
    }

    const event = msg.event ?? '';
    if (event === 'pusher:ping') {
      ws.send(encode('pusher:pong', undefined, {}));
      return;
    }
    if (event === 'pusher:subscribe') {
      await this.handleSubscribe(ws, this.objectData(msg.data));
      return;
    }
    if (event === 'pusher:unsubscribe') {
      await this.handleUnsubscribe(ws, String(this.objectData(msg.data).channel ?? msg.channel ?? ''));
      return;
    }
    if (isClientEvent(event)) {
      await this.handleClientEvent(ws, event, msg);
      return;
    }
  }

  async webSocketClose(ws: WebSocket, code: number): Promise<void> {
    await this.dropConnection(ws);
    try {
      // Complete the close handshake (not automatic before compat 2026-04-07).
      ws.close(code === 1005 || code === 1006 ? 1000 : code);
    } catch {
      // already closed
    }
  }

  async webSocketError(ws: WebSocket): Promise<void> {
    await this.dropConnection(ws);
  }

  // --- Subscribe / unsubscribe -------------------------------------------

  private async handleSubscribe(ws: WebSocket, data: Record<string, unknown>): Promise<void> {
    let conn = ws.deserializeAttachment() as ConnState;
    const channel = String(data.channel ?? '');
    if (!channel) {
      return;
    }

    if (channelRequiresAuth(channel)) {
      const app = await this.appCreds();
      const channelData = isPresenceChannel(channel) ? String(data.channel_data ?? '') : undefined;
      const ok = await verifyChannelAuth(
        String(data.auth ?? ''),
        app.key,
        app.secret,
        conn.socketId,
        channel,
        channelData,
      );
      if (!ok) {
        console.log({ src: 'realtime', do: 'AppHub', event: 'subscribe_denied', channel, socketId: conn.socketId });
        ws.send(
          encode('pusher:error', channel, {
            code: 4009,
            message: `Connection not authorized for ${channel}`,
          }),
        );
        return;
      }
    }

    let member: PresenceMember | null = null;
    if (isPresenceChannel(channel)) {
      try {
        const parsed = JSON.parse(String(data.channel_data ?? '{}')) as {
          user_id: unknown;
          user_info?: unknown;
        };
        member = { user_id: String(parsed.user_id), user_info: parsed.user_info };
      } catch {
        ws.send(encode('pusher:error', channel, { code: 4009, message: 'Invalid channel_data' }));
        return;
      }
    }

    // Other shards' members, asked before this socket is indexed: two sockets
    // of one user joining two shards at once then both announce (a duplicate
    // member_added) rather than neither.
    const remote = member ? await this.peerMembers(channel) : new Map<string, unknown>();
    // Re-read: awaiting peers lets this socket's other frames (or its close) run.
    conn = ws.deserializeAttachment() as ConnState;
    if (this.dropped.has(conn.socketId)) {
      return;
    }
    conn.channels[channel] = member;
    ws.serializeAttachment(conn);
    this.subscribersOf(channel, true)!.set(ws, { socketId: conn.socketId, member });
    console.log({ src: 'realtime', do: 'AppHub', event: 'subscribed', channel, presence: isPresenceChannel(channel), socketId: conn.socketId });

    if (isPresenceChannel(channel) && member) {
      const members = new Map([...remote, ...this.presenceMembers(channel)]);
      ws.send(encode('pusher_internal:subscription_succeeded', channel, buildPresencePayload(members)));
      // Tell everyone else only if this user wasn't already present (on any shard).
      if (!remote.has(member.user_id) && !this.userHasOtherConnection(channel, member.user_id, conn.socketId)) {
        await this.announce(
          channel,
          encode('pusher_internal:member_added', channel, {
            user_id: member.user_id,
            user_info: member.user_info,
          }),
          conn.socketId,
        );
      }
    } else {
      ws.send(encode('pusher_internal:subscription_succeeded', channel, {}));
    }
  }

  private async handleUnsubscribe(ws: WebSocket, channel: string): Promise<void> {
    if (!channel) {
      return;
    }
    const conn = ws.deserializeAttachment() as ConnState;
    const member = conn.channels[channel];
    if (!(channel in conn.channels)) {
      return;
    }
    delete conn.channels[channel];
    ws.serializeAttachment(conn);
    this.unindex(ws, [channel]);

    if (member && isPresenceChannel(channel)) {
      await this.memberLeft(channel, member.user_id, conn.socketId);
    }
  }

  private async handleClientEvent(ws: WebSocket, event: string, msg: InboundMessage): Promise<void> {
    const conn = ws.deserializeAttachment() as ConnState;
    const channel = String(msg.channel ?? '');
    if (!conn.clientEvents) {
      ws.send(encode('pusher:error', undefined, { code: 4301, message: 'Client events are not enabled for this app' }));
      return;
    }
    // Client events are only allowed on subscribed private/presence channels.
    if (!channel || !(channel in conn.channels) || !channelRequiresAuth(channel)) {
      return;
    }
    const max = conn.maxMessageBytes ?? DEFAULT_MAX_MESSAGE_BYTES;
    if (payloadBytes(msg.data) > max) {
      ws.send(encode('pusher:error', undefined, { code: 4301, message: `Client event rejected - message exceeds ${max} bytes` }));
      return;
    }
    this.count({ messagesIn: 1 });
    // Fan out to the channel's other subscribers (every shard) — never back to the sender.
    await this.announce(channel, encode(event, channel, msg.data), conn.socketId);
  }

  // --- Publish (server-triggered events) ---------------------------------

  /** `countIn` false on shards 1..n of a fanned-out publish: shard 0 counts it once. */
  private async handlePublish(request: Request, maxMessageBytes: number, countIn = true): Promise<Response> {
    let payload: PublishPayload;
    try {
      payload = (await request.json()) as PublishPayload;
    } catch {
      return Response.json({ error: 'invalid_body' }, { status: 400 });
    }
    if (payloadBytes(payload.data) > maxMessageBytes) {
      return Response.json({ error: 'payload_too_large', max_bytes: maxMessageBytes }, { status: 413 });
    }
    const channels = payload.channels ?? (payload.channel ? [payload.channel] : []);
    if (countIn) {
      this.count({ messagesIn: 1 });
    }
    let delivered = 0;
    for (const channel of channels) {
      delivered += this.broadcast(channel, encode(payload.name, channel, payload.data), payload.socket_id);
    }
    console.log({ src: 'realtime', do: 'AppHub', event: 'publish', name: payload.name, channels: channels.length, delivered });
    return Response.json({ ok: true, channels: channels.length, delivered });
  }

  // --- Helpers ------------------------------------------------------------

  private async appCreds(): Promise<AppCredentials> {
    return (await this.loadCreds()) ?? { id: '', key: '', secret: '', enabled: false };
  }

  private async loadCreds(): Promise<HubCredentials | null> {
    if (this.creds === undefined) {
      this.creds = (await this.state.storage.get<HubCredentials>('app')) ?? null;
    }
    return this.creds;
  }

  /** The app's other shards (none for a one-shard app, or before any socket told us). */
  private async peers(): Promise<DurableObjectStub[]> {
    const creds = await this.loadCreds();
    const shards = creds?.shards ?? 1;
    if (!creds || shards <= 1) {
      return [];
    }
    const self = creds.shard ?? 0;
    const ns = this.env.APP_HUB;
    return Array.from({ length: shards }, (_, n) => n)
      .filter((n) => n !== self)
      .map((n) => ns.get(ns.idFromName(hubName(creds.id, n))));
  }

  /** Call every peer; a peer that fails is skipped (logged), never fatal. */
  private async askPeers(path: string, init?: RequestInit): Promise<unknown[]> {
    const results = await Promise.all(
      (await this.peers()).map((peer) =>
        peer.fetch(new Request(`https://hub${path}`, init)).then(
          (r) => (r.ok ? r.json() : null),
          (e: unknown) => {
            console.log({ src: 'realtime', do: 'AppHub', event: 'peer_failed', path, error: String(e) });
            return null;
          },
        ),
      ),
    );
    return results.filter((r) => r !== null);
  }

  /** Presence members of `channel` held by the other shards, keyed by user_id. */
  private async peerMembers(channel: string): Promise<Map<string, unknown>> {
    const bodies = (await this.askPeers(`/internal/members?channel=${encodeURIComponent(channel)}`)) as Array<{
      members: Array<[string, unknown]>;
    }>;
    return new Map(bodies.flatMap((b) => b.members));
  }

  /** Broadcast here, and on every other shard. */
  private async announce(channel: string, frame: string, exceptSocketId?: string): Promise<void> {
    this.broadcast(channel, frame, exceptSocketId);
    await this.askPeers('/internal/relay', { method: 'POST', body: JSON.stringify({ channel, frame }) });
  }

  /**
   * A user's socket left `channel` (already unindexed): member_removed, on
   * every shard, once the user has no connection left on any shard.
   */
  private async memberLeft(channel: string, userId: string, socketId: string): Promise<void> {
    if (this.userHasOtherConnection(channel, userId, socketId)) {
      return;
    }
    if ((await this.peerMembers(channel)).has(userId)) {
      return;
    }
    await this.announce(channel, encode('pusher_internal:member_removed', channel, { user_id: userId }), socketId);
  }

  /**
   * The channel's subscribers from the index, building the index from every
   * socket's attachment on first use after a wake. `create` adds an empty
   * entry for a channel with none; empty entries are pruned on removal.
   */
  private subscribersOf(channel: string, create = false): Map<WebSocket, Subscriber> | undefined {
    if (!this.index) {
      this.index = new Map();
      for (const ws of this.state.getWebSockets()) {
        const conn = this.attachmentOf(ws);
        if (!conn || this.dropped.has(conn.socketId)) {
          continue;
        }
        for (const [ch, member] of Object.entries(conn.channels)) {
          let subs = this.index.get(ch);
          if (!subs) {
            this.index.set(ch, (subs = new Map()));
          }
          subs.set(ws, { socketId: conn.socketId, member });
        }
      }
    }
    let subs = this.index.get(channel);
    if (!subs && create) {
      this.index.set(channel, (subs = new Map()));
    }
    return subs;
  }

  /** Remove a socket from these channels' subscriber lists. */
  private unindex(ws: WebSocket, channels: string[]): void {
    for (const channel of channels) {
      const subs = this.subscribersOf(channel);
      subs?.delete(ws);
      if (subs?.size === 0) {
        this.index!.delete(channel);
      }
    }
  }

  /**
   * Send a frame to every socket subscribed to `channel`, except
   * `exceptSocketId`. Returns the number of sockets the frame was delivered to.
   */
  private broadcast(channel: string, frame: string, exceptSocketId?: string): number {
    let delivered = 0;
    for (const [ws, sub] of this.subscribersOf(channel) ?? []) {
      if (exceptSocketId && sub.socketId === exceptSocketId) {
        continue;
      }
      try {
        ws.send(frame);
        delivered++;
      } catch {
        // socket closing mid-broadcast; ignore
      }
    }
    if (delivered > 0) {
      this.count({ messagesOut: delivered });
    }
    return delivered;
  }

  /** Distinct presence members currently in `channel`, keyed by user_id. */
  private presenceMembers(channel: string): Map<string, unknown> {
    const members = new Map<string, unknown>();
    for (const { member } of this.subscribersOf(channel)?.values() ?? []) {
      if (member) {
        members.set(member.user_id, member.user_info ?? {});
      }
    }
    return members;
  }

  private userHasOtherConnection(channel: string, userId: string, exceptSocketId: string): boolean {
    for (const { socketId, member } of this.subscribersOf(channel)?.values() ?? []) {
      if (socketId === exceptSocketId) {
        continue;
      }
      if (member && member.user_id === userId) {
        return true;
      }
    }
    return false;
  }

  private async dropConnection(ws: WebSocket): Promise<void> {
    const conn = this.attachmentOf(ws);
    if (!conn || this.dropped.has(conn.socketId)) {
      return;
    }
    this.dropped.add(conn.socketId);
    this.unindex(ws, Object.keys(conn.channels));
    if (conn.connectedAt) {
      this.count({ closedMs: Math.max(0, Date.now() - conn.connectedAt) });
    }
    for (const [channel, member] of Object.entries(conn.channels)) {
      if (member && isPresenceChannel(channel)) {
        await this.memberLeft(channel, member.user_id, conn.socketId);
      }
    }
  }

  /** Open sockets, minus any whose close/error we've already handled. */
  private liveSockets(): WebSocket[] {
    return this.state.getWebSockets().filter((ws) => {
      if (ws.readyState !== 1 /* OPEN */) {
        return false;
      }
      const conn = this.attachmentOf(ws);
      return !conn || !this.dropped.has(conn.socketId);
    });
  }

  private attachmentOf(ws: WebSocket): ConnState | null {
    try {
      return ws.deserializeAttachment() as ConnState | null;
    } catch {
      return null;
    }
  }

  private objectData(data: unknown): Record<string, unknown> {
    if (typeof data === 'string') {
      try {
        return JSON.parse(data || '{}') as Record<string, unknown>;
      } catch {
        return {};
      }
    }
    return (data as Record<string, unknown>) ?? {};
  }
}
