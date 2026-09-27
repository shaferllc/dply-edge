// HMAC-SHA256 signing for Pusher-compatible auth. Two surfaces:
//   1. Channel auth — clients prove they may join private-/presence- channels.
//   2. REST trigger auth — servers prove they may publish events.
// Both use the Pusher scheme so the stock `pusher-php-server` SDK and
// `laravel-echo` work against this Worker with credentials only.

import { md5 } from './md5';

const encoder = new TextEncoder();

async function importKey(secret: string): Promise<CryptoKey> {
  return crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
}

export async function hmacSha256Hex(secret: string, message: string): Promise<string> {
  const key = await importKey(secret);
  const sig = await crypto.subtle.sign('HMAC', key, encoder.encode(message));
  const bytes = new Uint8Array(sig);
  let out = '';
  for (const b of bytes) {
    out += b.toString(16).padStart(2, '0');
  }
  return out;
}

/** Constant-time string comparison to avoid leaking signatures via timing. */
export function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) {
    return false;
  }
  let mismatch = 0;
  for (let i = 0; i < a.length; i++) {
    mismatch |= a.charCodeAt(i) ^ b.charCodeAt(i);
  }
  return mismatch === 0;
}

/**
 * Pusher channel auth token: `{key}:HMAC_SHA256(secret, "socketId:channel")`
 * for private channels, with `:channelData` appended for presence channels.
 */
export async function channelAuthToken(
  key: string,
  secret: string,
  socketId: string,
  channel: string,
  channelData?: string,
): Promise<string> {
  const base = channelData
    ? `${socketId}:${channel}:${channelData}`
    : `${socketId}:${channel}`;
  return `${key}:${await hmacSha256Hex(secret, base)}`;
}

export async function verifyChannelAuth(
  providedAuth: string,
  key: string,
  secret: string,
  socketId: string,
  channel: string,
  channelData?: string,
): Promise<boolean> {
  if (!providedAuth) {
    return false;
  }
  const expected = await channelAuthToken(key, secret, socketId, channel, channelData);
  return timingSafeEqual(providedAuth, expected);
}

export interface AppCredentials {
  id: string;
  key: string;
  secret: string;
  enabled: boolean;
}

/**
 * Verify a server-side publish request. Accepts either:
 *   - dply header auth: `X-Dply-Key` + `X-Dply-Secret` (simple, our SDK), or
 *   - Pusher REST signature: `?auth_key&auth_timestamp&auth_signature&body_md5`
 *     signed as HMAC_SHA256(secret, "METHOD\nPATH\nsortedQuery").
 */
export async function verifyPublishRequest(
  app: AppCredentials,
  method: string,
  pathname: string,
  searchParams: URLSearchParams,
  headers: Headers,
  rawBody: string,
  now: number = Date.now(),
): Promise<boolean> {
  const headerKey = headers.get('X-Dply-Key');
  const headerSecret = headers.get('X-Dply-Secret');
  if (headerKey && headerSecret) {
    return timingSafeEqual(headerKey, app.key) && timingSafeEqual(headerSecret, app.secret);
  }

  const authKey = searchParams.get('auth_key');
  const authSignature = searchParams.get('auth_signature');
  const authTimestamp = searchParams.get('auth_timestamp');
  if (!authKey || !authSignature || !authTimestamp) {
    return false;
  }
  if (!timingSafeEqual(authKey, app.key)) {
    return false;
  }
  // Reject stale/forward-dated requests (replay window: 600s, Pusher default).
  if (Math.abs(now / 1000 - Number(authTimestamp)) > 600) {
    return false;
  }
  // The signature covers the body only through body_md5, so a request with a
  // body must carry it (Pusher servers always do) and it must match.
  const bodyMd5 = searchParams.get('body_md5');
  if ((rawBody !== '' || bodyMd5) && !timingSafeEqual(bodyMd5 ?? '', md5(rawBody))) {
    return false;
  }

  const stringToSign = buildPusherSignString(method, pathname, searchParams);
  const expected = await hmacSha256Hex(app.secret, stringToSign);
  return timingSafeEqual(authSignature.toLowerCase(), expected);
}

/**
 * Pusher's REST string-to-sign: METHOD, path, and the query params (minus
 * the signature itself) lowercased-by-key, sorted, and joined `k=v&k=v`.
 */
export function buildPusherSignString(
  method: string,
  pathname: string,
  searchParams: URLSearchParams,
): string {
  const params: Array<[string, string]> = [];
  for (const [k, v] of searchParams.entries()) {
    if (k.toLowerCase() === 'auth_signature') {
      continue;
    }
    params.push([k.toLowerCase(), v]);
  }
  params.sort((a, b) => (a[0] < b[0] ? -1 : a[0] > b[0] ? 1 : 0));
  const query = params.map(([k, v]) => `${k}=${v}`).join('&');
  return `${method.toUpperCase()}\n${pathname}\n${query}`;
}
