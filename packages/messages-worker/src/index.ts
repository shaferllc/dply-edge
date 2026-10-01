// dply Messages: an HTTP message queue (README.md). Headers are read as
// Dply-* or the compatible Upstash-* names (parse.ts withDplyHeaders).
//
//   POST /v2/publish/{url}      publish (Upstash-Delay, -Not-Before, -Retries,
//                               -Callback, -Failure-Callback, -Method,
//                               -Deduplication-Id, -Timeout, Upstash-Forward-*)
//   POST /v2/batch              several at once
//   POST /v2/schedules/{url}    Upstash-Cron (5 fields, UTC)
//   GET|DELETE /v2/schedules[/id], POST /v2/schedules/{id}/pause|resume
//   GET|DELETE /v2/messages/{id}
//   GET /v2/dlq, DELETE /v2/dlq[/id], POST /v2/dlq/retry
//   GET /v2/keys
//
// Delivery is at least once: a message is signed with the
// organization's current key (Upstash-Signature) and retried with backoff
// until it succeeds or its retries run out, then kept in the DLQ.
// dply manages organizations and tokens through /_operator (OPERATOR_TOKEN).

import { OrgState } from './org';
import { durationMs, refuseDestination, withDplyHeaders } from './parse';
import { sha256Hex, signMessage } from './sign';
import type { Account, Env, Limits, NewMessage, StoredMessage } from './types';
import { DEFAULT_LIMITS } from './types';

export { OrgState };

const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status, headers: { 'content-type': 'application/json' } });
const fail = (error: string, status = 400) => json({ error }, status);

export default {
  async fetch(request: Request, env: Env): Promise<Response> {
    try {
      const url = new URL(request.url);
      if (url.pathname.startsWith('/_operator/')) return await operator(request, url, env);
      if (url.pathname.startsWith('/v2/')) return await api(withDplyHeaders(request), url, env);
      if (url.pathname === '/healthz') return new Response('ok');
      return fail('Not found', 404);
    } catch (e) {
      console.error('messages: request failed', e);
      return fail('Internal error', 500);
    }
  },

  async queue(batch: MessageBatch<{ org: string; id: string }>, env: Env): Promise<void> {
    await Promise.all(batch.messages.map((m) => deliver(m, env)));
  },
} satisfies ExportedHandler<Env, { org: string; id: string }>;

// ---- customer API ----

async function api(request: Request, url: URL, env: Env): Promise<Response> {
  const token = (request.headers.get('authorization') ?? '').replace(/^Bearer\s+/i, '');
  const who = token === '' ? null : await env.ACCOUNTS.get<{ org: string }>(`token:${await sha256Hex(token)}`, 'json');
  if (!who) return fail('Unauthorized', 401);
  const account = await env.ACCOUNTS.get<Account>(`org:${who.org}`, 'json');
  if (!account) return fail('Unauthorized', 401);
  const limits: Limits = { ...DEFAULT_LIMITS, ...(account.limits ?? {}) };
  const org = env.ORG.get(env.ORG.idFromName(who.org));
  const writing = request.method !== 'GET';
  // Paused (billing): nothing new is accepted; reading and deleting still work.
  if (writing && request.method !== 'DELETE' && !account.enabled) return fail('This organization is paused. Choose a plan in dply to send messages again.', 403);

  // Everything after the origin, so a destination keeps its own path and query.
  const rest = request.url.slice(url.origin.length);
  const route = (prefix: string) => rest.startsWith(prefix) ? rest.slice(prefix.length) : null;

  let dest: string | null;
  if (request.method === 'POST' && (dest = route('/v2/publish/')) !== null) {
    const built = await buildMessage(dest, request.headers, new Uint8Array(await request.arrayBuffer()), limits, env);
    if ('error' in built) return fail(built.error);
    const result = await org.publish(who.org, built, limits);
    if ('error' in result) return fail(result.error, result.status);
    return json({ messageId: result.messageId, url: built.url, ...(result.deduplicated ? { deduplicated: true } : {}) });
  }
  if (request.method === 'POST' && rest.split('?')[0] === '/v2/batch') {
    let items: { destination: string; headers?: Record<string, string>; body?: string }[];
    try { items = await request.json(); } catch { return fail('The body must be a JSON array of messages'); }
    if (!Array.isArray(items) || items.length === 0 || items.length > 100) return fail('Send 1 to 100 messages');
    const out = [];
    for (const item of items) {
      const built = await buildMessage(item.destination, new Headers(item.headers ?? {}), new TextEncoder().encode(item.body ?? ''), limits, env);
      if ('error' in built) return fail(built.error);
      const result = await org.publish(who.org, built, limits);
      if ('error' in result) return fail(result.error, result.status);
      out.push({ messageId: result.messageId, url: built.url, ...(result.deduplicated ? { deduplicated: true } : {}) });
    }
    return json(out);
  }
  if (request.method === 'POST' && (dest = route('/v2/schedules/')) !== null && /^https?:/i.test(dest)) {
    const cron = request.headers.get('upstash-cron');
    if (!cron) return fail('Set the Dply-Cron header, like "*/5 * * * *" (UTC)');
    const built = await buildMessage(dest, request.headers, new Uint8Array(await request.arrayBuffer()), limits, env);
    if ('error' in built) return fail(built.error);
    const delayMs = Math.max(0, built.notBefore - Date.now());
    const result = await org.createSchedule(who.org, {
      cron, url: built.url, method: built.method, headers: built.headers, body: built.body, retries: built.retries,
      delayMs, callback: built.callback, failureCallback: built.failureCallback, timeoutMs: built.timeoutMs,
      scheduleId: request.headers.get('upstash-schedule-id') ?? undefined,
    }, limits);
    return 'error' in result ? fail(result.error, result.status) : json(result);
  }

  const path = rest.split('?')[0].split('/').filter(Boolean).map(decodeURIComponent); // ['v2', ...]
  const [, section, idPart, action] = path;
  if (section === 'schedules') {
    if (request.method === 'GET' && !idPart) return json((await org.listSchedules()).map(scheduleOut));
    if (request.method === 'GET' && idPart) { const s = await org.getSchedule(idPart); return s ? json(scheduleOut(s)) : fail('Schedule not found', 404); }
    if (request.method === 'DELETE' && idPart) { await org.deleteSchedule(idPart); return new Response(null, { status: 200 }); }
    if (request.method === 'PATCH' || (request.method === 'POST' && (action === 'pause' || action === 'resume'))) {
      return (await org.pauseSchedule(idPart, action === 'pause')) ? new Response(null, { status: 200 }) : fail('Schedule not found', 404);
    }
  }
  if (section === 'messages' && idPart) {
    if (request.method === 'GET') { const m = await org.get(idPart); return m ? json(messageOut(m)) : fail('Message not found', 404); }
    if (request.method === 'DELETE') return (await org.cancel(idPart)) ? json({ cancelled: 1 }) : fail('Message not found', 404);
  }
  if (section === 'messages' && !idPart && request.method === 'DELETE') {
    // Bulk cancel by id (?messageIds=a&messageIds=b); cancelling "all" is not offered.
    const ids = url.searchParams.getAll('messageIds');
    if (ids.length === 0) return fail('Name the messages with messageIds');
    let cancelled = 0;
    for (const one of ids) cancelled += (await org.cancel(one)) ? 1 : 0;
    return json({ cancelled });
  }
  if (section === 'dlq') {
    if (request.method === 'GET') {
      const page = await org.listDlq(url.searchParams.get('cursor'), Math.min(100, Number(url.searchParams.get('count') ?? 100) || 100));
      return json({ messages: page.messages.map(dlqOut), ...(page.cursor ? { cursor: page.cursor } : {}) });
    }
    if (request.method === 'DELETE' && idPart) { await org.deleteDlq([idPart]); return new Response(null, { status: 200 }); }
    if (request.method === 'DELETE') {
      // Ids in the query (current SDK) or the body (older SDKs).
      const body = await request.json<{ dlqIds?: string[] }>().catch(() => ({ dlqIds: [] }));
      const ids = [...url.searchParams.getAll('dlqIds'), ...(body.dlqIds ?? [])];
      return json({ deleted: await org.deleteDlq(ids) });
    }
    if (request.method === 'POST' && idPart === 'retry') {
      const body = await request.json<{ dlqIds?: string[] }>().catch(() => ({ dlqIds: [] }));
      return json({ responses: await org.retryDlq(who.org, body.dlqIds ?? []) });
    }
  }
  if (section === 'keys' && request.method === 'GET') return json({ current: account.currentSigningKey, next: account.nextSigningKey });
  return fail('Not found', 404);
}

/** A publish request's headers and body as a stored message, or why it is refused. */
async function buildMessage(dest: string, headers: Headers, body: Uint8Array, limits: Limits, env: Env): Promise<NewMessage | { error: string }> {
  const refused = refuseDestination(dest, (env.BLOCKED_HOST_SUFFIXES ?? '').split(','), env.ALLOW_PRIVATE_DESTINATIONS === 'true');
  if (refused) return { error: refused };
  if (body.byteLength > limits.maxBodyBytes) return { error: `The body is larger than ${limits.maxBodyBytes} bytes` };

  const now = Date.now();
  const delay = durationMs(headers.get('upstash-delay'));
  if (headers.get('upstash-delay') && delay === null) return { error: 'Dply-Delay must look like 30s, 10m, 2h or 1d' };
  const notBeforeHeader = headers.get('upstash-not-before');
  const notBefore = notBeforeHeader ? Number(notBeforeHeader) * 1000 : now + (delay ?? 0);
  if (!Number.isFinite(notBefore)) return { error: 'Dply-Not-Before must be a Unix time in seconds' };
  if (notBefore - now > limits.maxDelayMs) return { error: `Delay messages at most ${Math.round(limits.maxDelayMs / 86_400_000)} days` };

  const retriesHeader = headers.get('upstash-retries');
  const retries = retriesHeader === null ? 3 : Number(retriesHeader);
  if (!Number.isInteger(retries) || retries < 0 || retries > limits.maxRetries) return { error: `Dply-Retries must be 0 to ${limits.maxRetries}` };

  const timeout = durationMs(headers.get('upstash-timeout'));
  const timeoutMs = Math.min(limits.maxTimeoutMs, timeout ?? 30_000);

  for (const name of ['upstash-callback', 'upstash-failure-callback']) {
    const cb = headers.get(name);
    if (cb) {
      const bad = refuseDestination(cb, (env.BLOCKED_HOST_SUFFIXES ?? '').split(','), env.ALLOW_PRIVATE_DESTINATIONS === 'true');
      if (bad) return { error: `${name}: ${bad}` };
    }
  }

  const forward: Record<string, string> = {};
  headers.forEach((value, key) => {
    const k = key.toLowerCase();
    if (k.startsWith('upstash-forward-')) forward[key.slice('upstash-forward-'.length)] = value;
    else if (k === 'content-type') forward['content-type'] = value;
  });

  let dedupId = headers.get('upstash-deduplication-id') ?? undefined;
  if (!dedupId && headers.get('upstash-content-based-deduplication') === 'true') {
    dedupId = 'content_' + await sha256Hex(dest + '\n' + JSON.stringify(forward) + '\n' + toBase64(body));
  }

  return {
    url: dest,
    method: (headers.get('upstash-method') ?? 'POST').toUpperCase(),
    headers: forward,
    body: toBase64(body),
    notBefore,
    retries,
    callback: headers.get('upstash-callback') ?? undefined,
    failureCallback: headers.get('upstash-failure-callback') ?? undefined,
    timeoutMs,
    dedupId,
  };
}

// ---- delivery ----

/** Backoff: min(1 day, e^(2.5·n)) seconds before retry n (12 s, 2.5 min, 30 min, 6 h, 1 day). */
export function backoffSeconds(attempt: number): number {
  return Math.min(86_400, Math.round(Math.exp(2.5 * attempt)));
}

async function deliver(m: Message<{ org: string; id: string }>, env: Env): Promise<void> {
  const { org: orgId, id } = m.body;
  const org = env.ORG.get(env.ORG.idFromName(orgId));
  const msg = await org.claim(id);
  if (!msg) { m.ack(); return; } // cancelled, delivered or dead-lettered meanwhile

  const account = await env.ACCOUNTS.get<Account>(`org:${orgId}`, 'json');
  if (!account?.enabled) {
    // Paused (billing): kept in the DLQ, where it can be retried after resuming.
    await org.deadLetter(id, null, '', 'organization paused');
    m.ack();
    return;
  }

  const refused = refuseDestination(msg.url, (env.BLOCKED_HOST_SUFFIXES ?? '').split(','), env.ALLOW_PRIVATE_DESTINATIONS === 'true');
  let status: number | null = null;
  let responseBody = '';
  let error = '';
  if (refused) {
    error = refused;
  } else {
    try {
      const body = fromBase64(msg.body);
      const headers = new Headers(msg.headers);
      headers.set('Upstash-Signature', await signMessage(account.currentSigningKey, msg.url, body));
      headers.set('Upstash-Message-Id', msg.messageId);
      headers.set('Upstash-Retried', String(msg.attempts));
      if (msg.scheduleId) headers.set('Upstash-Schedule-Id', msg.scheduleId);
      // The same four as Dply-*, for receivers written against dply's docs.
      for (const name of ['Signature', 'Message-Id', 'Retried', 'Schedule-Id']) {
        const value = headers.get('Upstash-' + name);
        if (value !== null) headers.set('Dply-' + name, value);
      }
      headers.set('User-Agent', 'dply-messages');
      const res = await fetch(msg.url, {
        method: msg.method,
        headers,
        body: msg.method === 'GET' || msg.method === 'HEAD' ? undefined : body,
        redirect: 'manual',
        signal: AbortSignal.timeout(msg.timeoutMs),
      });
      status = res.status;
      responseBody = (await res.text()).slice(0, 8192);
      if (status >= 200 && status < 300) {
        await org.delivered(id, status);
        if (msg.callback) await callback(org, orgId, msg.callback, msg, status, res.headers, responseBody);
        m.ack();
        return;
      }
      error = `HTTP ${status}`;
    } catch (e) {
      error = e instanceof Error ? e.message : String(e);
    }
  }

  const attempts = await org.failedAttempt(id, status, error);
  if (!refused && attempts <= msg.maxRetries) {
    m.retry({ delaySeconds: backoffSeconds(attempts) });
    return;
  }
  await org.deadLetter(id, status, responseBody, error);
  if (msg.failureCallback) await callback(org, orgId, msg.failureCallback, msg, status ?? 0, new Headers(), responseBody);
  m.ack();
}

/** A callback is a new message to the callback URL describing the outcome. */
async function callback(org: DurableObjectStub<OrgState>, orgId: string, url: string, msg: StoredMessage, status: number, headers: Headers, body: string): Promise<void> {
  const header: Record<string, string[]> = {};
  headers.forEach((v, k) => { header[k] = [v]; });
  const payload = JSON.stringify({
    status, header, body: toBase64(new TextEncoder().encode(body)), retried: msg.attempts, maxRetries: msg.maxRetries,
    sourceMessageId: msg.messageId, url: msg.url, method: msg.method, sourceBody: msg.body, scheduleId: msg.scheduleId,
  });
  await org.publish(orgId, {
    url, method: 'POST', headers: { 'content-type': 'application/json' }, body: toBase64(new TextEncoder().encode(payload)),
    notBefore: Date.now(), retries: 3, timeoutMs: 30_000,
  });
}

// ---- operator (dply) ----

async function operator(request: Request, url: URL, env: Env): Promise<Response> {
  const token = (request.headers.get('authorization') ?? '').replace(/^Bearer\s+/i, '');
  if (!env.OPERATOR_TOKEN || !timingSafeEqual(token, env.OPERATOR_TOKEN)) return fail('Unauthorized', 401);
  const [, , kind, key, action] = url.pathname.split('/'); // '', '_operator', kind, key, action
  if (kind === 'orgs' && key) {
    const org = env.ORG.get(env.ORG.idFromName(key));
    if (request.method === 'PUT') {
      const body = await request.json<Account>();
      if (!body.currentSigningKey || !body.nextSigningKey) return fail('currentSigningKey and nextSigningKey are required');
      await env.ACCOUNTS.put(`org:${key}`, JSON.stringify({ enabled: body.enabled !== false, currentSigningKey: body.currentSigningKey, nextSigningKey: body.nextSigningKey, limits: body.limits }));
      return json({ ok: true });
    }
    if (request.method === 'GET' && action === 'usage') return json(await org.usage());
    if (request.method === 'DELETE') {
      await org.purge();
      await env.ACCOUNTS.delete(`org:${key}`);
      return json({ ok: true });
    }
  }
  if (kind === 'tokens' && key && /^[0-9a-f]{64}$/.test(key)) {
    if (request.method === 'PUT') {
      const body = await request.json<{ org: string }>();
      await env.ACCOUNTS.put(`token:${key}`, JSON.stringify({ org: body.org }));
      return json({ ok: true });
    }
    if (request.method === 'DELETE') {
      await env.ACCOUNTS.delete(`token:${key}`);
      return json({ ok: true });
    }
  }
  return fail('Not found', 404);
}

// ---- shapes ----

function messageOut(m: StoredMessage) {
  return {
    messageId: m.messageId, url: m.url, method: m.method, header: headersOut(m.headers), body: textOrUndefined(m.body), bodyBase64: m.body,
    maxRetries: m.maxRetries, notBefore: m.notBefore, createdAt: m.createdAt, callback: m.callback, failureCallback: m.failureCallback, scheduleId: m.scheduleId,
  };
}

function dlqOut(m: StoredMessage) {
  return { ...messageOut(m), dlqId: m.dlqId, responseStatus: m.responseStatus, responseBody: m.responseBody, error: m.error };
}

function scheduleOut(s: import('./types').Schedule) {
  return {
    scheduleId: s.scheduleId, cron: s.cron, destination: s.destination, method: s.method, createdAt: s.createdAt, retries: s.retries,
    delay: s.delay, callback: s.callback, failureCallback: s.failureCallback, isPaused: s.isPaused, nextScheduleTime: s.nextScheduleTime,
    header: headersOut(JSON.parse(s.headersRaw)), body: textOrUndefined(s.bodyBase64), bodyBase64: s.bodyBase64,
  };
}

function headersOut(h: Record<string, string>): Record<string, string[]> {
  return Object.fromEntries(Object.entries(h).map(([k, v]) => [k, [v]]));
}

function textOrUndefined(b64: string): string | undefined {
  try { return new TextDecoder('utf-8', { fatal: true, ignoreBOM: false }).decode(fromBase64(b64)); } catch { return undefined; }
}

function toBase64(bytes: Uint8Array): string {
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin);
}

function fromBase64(b64: string): Uint8Array {
  const bin = atob(b64);
  const out = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
  return out;
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
}
