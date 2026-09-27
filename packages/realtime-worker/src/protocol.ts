// Pusher wire-protocol helpers. The realtime Worker speaks the same protocol
// as Pusher Channels / Laravel Reverb, so `laravel-echo` + `pusher-js` connect
// to it unchanged. Every outbound frame carries `data` as a JSON *string*
// (Pusher double-encodes the payload); clients JSON.parse it on receipt.

export interface InboundMessage {
  event: string;
  channel?: string;
  data?: unknown;
}

/** Build an outbound Pusher frame with the conventional double-encoded data. */
export function encode(event: string, channel: string | undefined, data: unknown): string {
  const frame: Record<string, unknown> = { event };
  if (channel !== undefined) {
    frame.channel = channel;
  }
  frame.data = typeof data === 'string' ? data : JSON.stringify(data ?? {});
  return JSON.stringify(frame);
}

export function isPrivateChannel(channel: string): boolean {
  return channel.startsWith('private-');
}

export function isPresenceChannel(channel: string): boolean {
  return channel.startsWith('presence-');
}

/** Private + presence channels require an HMAC auth token to subscribe. */
export function channelRequiresAuth(channel: string): boolean {
  return isPrivateChannel(channel) || isPresenceChannel(channel);
}

export function isClientEvent(event: string): boolean {
  return event.startsWith('client-');
}

/** Pusher socket ids are two dot-separated integers, e.g. "123456.789012". */
export function makeSocketId(rand: () => number = Math.random): string {
  const left = Math.floor(rand() * 1_000_000_000);
  const right = Math.floor(rand() * 1_000_000_000);
  return `${left}.${right}`;
}

export interface PresenceMember {
  user_id: string;
  user_info?: unknown;
}

/** Pusher presence payload: { count, ids, hash } keyed by user_id. */
export function buildPresencePayload(members: Map<string, unknown>): {
  presence: { count: number; ids: string[]; hash: Record<string, unknown> };
} {
  return {
    presence: {
      count: members.size,
      ids: [...members.keys()],
      hash: Object.fromEntries(members),
    },
  };
}

export const DEFAULT_MAX_MESSAGE_BYTES = 10240;

export interface AppLimits {
  allowedOrigins: string[];
  clientEvents: boolean;
  maxMessageBytes: number;
}

/** Resolve the optional KV record fields to their defaults (old records lack them). */
export function appLimits(record: {
  allowedOrigins?: unknown;
  clientEvents?: unknown;
  maxMessageBytes?: unknown;
}): AppLimits {
  const max = Number(record.maxMessageBytes);
  return {
    allowedOrigins: Array.isArray(record.allowedOrigins) ? record.allowedOrigins.map(String) : [],
    clientEvents: record.clientEvents === true,
    maxMessageBytes: Number.isFinite(max) && max > 0 ? max : DEFAULT_MAX_MESSAGE_BYTES,
  };
}

/** Empty allow-list = any origin; otherwise the Origin header must match one exactly. */
export function originAllowed(allowedOrigins: string[], origin: string | null): boolean {
  return allowedOrigins.length === 0 || (origin !== null && allowedOrigins.includes(origin));
}

/** UTF-8 size of an event's `data` as it goes on the wire (strings as-is, else JSON). */
export function payloadBytes(data: unknown): number {
  return new TextEncoder().encode(typeof data === 'string' ? data : JSON.stringify(data ?? {})).byteLength;
}

/** Hard ceiling on shards per app, whatever the KV record says. */
export const MAX_SHARDS = 32;

/** KV `shards` → an integer in [1, MAX_SHARDS]; missing/garbage = 1 (one hub, as before sharding). */
export function shardCount(raw: unknown): number {
  const n = Math.floor(Number(raw));
  return Number.isFinite(n) && n > 1 ? Math.min(n, MAX_SHARDS) : 1;
}

/** Durable Object name of an app's shard: shard 0 is the app id itself (the pre-sharding hub). */
export function hubName(appId: string, shard: number): string {
  return shard === 0 ? appId : `${appId}:${shard}`;
}
