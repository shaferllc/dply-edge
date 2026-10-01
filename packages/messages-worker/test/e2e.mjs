// End to end, all local: the Worker under `wrangler dev` (local KV, Durable
// Objects and Queues), a local HTTP server as the destination, and the real
// @upstash/qstash Client and Receiver. Run: npm run test:e2e
import { spawn } from 'node:child_process';
import { createHash } from 'node:crypto';
import http from 'node:http';
import { Client, Receiver } from '@upstash/qstash';

const PORT = 8799;
const BASE = `http://127.0.0.1:${PORT}`;
const OPERATOR = 'op_' + Math.random().toString(36).slice(2);
const TOKEN = 'tok_' + Math.random().toString(36).slice(2);
const KEYS = { currentSigningKey: 'sig_current_' + Math.random().toString(36).slice(2), nextSigningKey: 'sig_next_' + Math.random().toString(36).slice(2) };
const ORG = 'org-e2e';

let failures = 0;
const check = (name, ok, detail = '') => { console.log(`${ok ? 'ok  ' : 'FAIL'} ${name}${ok ? '' : ' ' + detail}`); if (!ok) failures++; };
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const waitFor = async (fn, ms) => { const end = Date.now() + ms; while (Date.now() < end) { const v = await fn(); if (v) return v; await sleep(250); } return null; };

// ---- the destination ----
const received = [];
let flaky = 0;
const receiver = new Receiver(KEYS);
const server = http.createServer(async (req, res) => {
  let body = '';
  for await (const chunk of req) body += chunk;
  const url = `http://127.0.0.1:${server.address().port}${req.url}`;
  let verified = false;
  try { verified = await receiver.verify({ signature: req.headers['upstash-signature'] ?? '', body, url }); } catch { verified = false; }
  received.push({ path: req.url, method: req.method, headers: req.headers, body, verified, at: Date.now() });
  if (req.url.startsWith('/fail')) { res.writeHead(500); res.end('nope'); return; }
  if (req.url.startsWith('/flaky') && flaky++ === 0) { res.writeHead(503); res.end('busy'); return; }
  res.writeHead(200, { 'content-type': 'application/json' }); res.end('{"done":true}');
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const DEST = `http://127.0.0.1:${server.address().port}`;

// ---- the Worker ----
const worker = spawn('npx', ['wrangler', 'dev', '--local', '--port', String(PORT), '--ip', '127.0.0.1',
  '--var', `OPERATOR_TOKEN:${OPERATOR}`, '--var', 'ALLOW_PRIVATE_DESTINATIONS:true', '--persist-to', `.wrangler/e2e-${Date.now()}`], { stdio: ['ignore', 'pipe', 'pipe'] });
let log = '';
worker.stdout.on('data', (d) => { log += d; });
worker.stderr.on('data', (d) => { log += d; });
const stop = () => { worker.kill('SIGTERM'); server.close(); };
process.on('exit', stop);

try {
  const up = await waitFor(async () => (await fetch(`${BASE}/healthz`).then((r) => r.ok).catch(() => false)), 60_000);
  if (!up) throw new Error('wrangler dev did not start:\n' + log.slice(-2000));

  const op = (method, path, body) => fetch(`${BASE}/_operator${path}`, { method, headers: { authorization: `Bearer ${OPERATOR}`, 'content-type': 'application/json' }, body: body && JSON.stringify(body) });
  check('operator API refuses a bad token', (await fetch(`${BASE}/_operator/orgs/${ORG}`, { method: 'PUT', headers: { authorization: 'Bearer nope' }, body: '{}' })).status === 401);
  await op('PUT', `/orgs/${ORG}`, { enabled: true, ...KEYS });
  await op('PUT', `/tokens/${createHash('sha256').update(TOKEN).digest('hex')}`, { org: ORG });

  const client = new Client({ baseUrl: BASE, token: TOKEN });
  check('a bad token is refused', await new Client({ baseUrl: BASE, token: 'wrong' }).publishJSON({ url: `${DEST}/ok`, body: {} }).then(() => false, (e) => /Unauthorized/.test(String(e))));

  // Publish, signed, with forwarded headers.
  const pub = await client.publishJSON({ url: `${DEST}/ok?x=1`, body: { hello: 'world' }, headers: { 'x-custom': 'yes' } });
  const got = await waitFor(() => received.find((r) => r.headers['upstash-message-id'] === pub.messageId), 15_000);
  check('publishJSON is delivered', !!got, JSON.stringify(pub));
  check('the delivery verifies with the Receiver', got?.verified === true);
  check('the destination keeps its query string', got?.path === '/ok?x=1', got?.path);
  check('Upstash-Forward-* headers arrive without the prefix', got?.headers['x-custom'] === 'yes');
  check('the JSON body arrives', got?.body === '{"hello":"world"}', got?.body);

  // Delay.
  const t0 = Date.now();
  const delayed = await client.publishJSON({ url: `${DEST}/ok`, body: { d: 1 }, delay: 3 });
  const d = await waitFor(() => received.find((r) => r.headers['upstash-message-id'] === delayed.messageId), 20_000);
  check('Upstash-Delay holds a message back', !!d && d.at - t0 >= 2_900, d ? `${d.at - t0} ms` : 'never');

  // Retry after a failure (backoff 12 s), then success.
  const retried = await client.publishJSON({ url: `${DEST}/flaky`, body: {}, retries: 2 });
  const r2 = await waitFor(() => received.filter((r) => r.headers['upstash-message-id'] === retried.messageId).length >= 2 && received.filter((r) => r.headers['upstash-message-id'] === retried.messageId), 40_000);
  check('a failed delivery is retried and then succeeds', !!r2 && r2[1].headers['upstash-retried'] === '1');

  // Out of retries: DLQ plus the failure callback.
  const failing = await client.publishJSON({ url: `${DEST}/fail`, body: { f: 1 }, retries: 0, failureCallback: `${DEST}/callback` });
  const dlq = await waitFor(async () => (await client.dlq.listMessages()).messages.find((m) => m.messageId === failing.messageId), 20_000);
  check('an exhausted message lands in the DLQ', !!dlq && dlq.responseStatus === 500, JSON.stringify(dlq));
  const cb = await waitFor(() => received.find((r) => r.path === '/callback' && JSON.parse(r.body).sourceMessageId === failing.messageId), 20_000);
  check('the failure callback is sent, signed', !!cb && cb.verified && JSON.parse(cb.body).status === 500);
  if (dlq) {
    await client.dlq.delete(dlq.dlqId);
    check('a DLQ entry can be deleted', !(await client.dlq.listMessages()).messages.some((m) => m.dlqId === dlq.dlqId));
  }

  // Deduplication.
  const a = await client.publishJSON({ url: `${DEST}/ok`, body: { n: 1 }, deduplicationId: 'same-thing' });
  const b = await client.publishJSON({ url: `${DEST}/ok`, body: { n: 2 }, deduplicationId: 'same-thing' });
  check('a duplicate id returns the first message', b.messageId === a.messageId && b.deduplicated === true, JSON.stringify(b));

  // Cancel before delivery.
  const later = await client.publishJSON({ url: `${DEST}/ok`, body: { c: 1 }, delay: 60 });
  check('a pending message can be read', (await client.messages.get(later.messageId)).messageId === later.messageId);
  await client.messages.cancel(later.messageId);
  check('a cancelled message is gone', await client.messages.get(later.messageId).then(() => false, () => true));

  // Refusals.
  check('dply hosts are refused', await client.publishJSON({ url: 'https://api.dply.io/x', body: {} }).then(() => false, (e) => /cannot receive/.test(String(e))));
  check('too many retries are refused', await client.publishJSON({ url: `${DEST}/ok`, body: {}, retries: 50 }).then(() => false, () => true));

  // Batch.
  const batch = await client.batchJSON([{ url: `${DEST}/ok`, body: { b: 1 } }, { url: `${DEST}/ok`, body: { b: 2 } }]);
  const both = await waitFor(() => batch.every((m) => received.some((r) => r.headers['upstash-message-id'] === m.messageId)), 15_000);
  check('batch publishes each message', batch.length === 2 && !!both);

  // Schedules: create, list, fire on the next minute, delete.
  const sched = await client.schedules.create({ destination: `${DEST}/cron`, cron: '* * * * *', body: 'tick' });
  check('a schedule is listed', (await client.schedules.list()).some((s) => s.scheduleId === sched.scheduleId));
  const tick = await waitFor(() => received.find((r) => r.path === '/cron' && r.headers['upstash-schedule-id'] === sched.scheduleId), 75_000);
  check('a schedule fires on its minute, signed', !!tick && tick.verified && tick.body === 'tick');
  await client.schedules.delete(sched.scheduleId);
  check('a deleted schedule is gone', !(await client.schedules.list()).some((s) => s.scheduleId === sched.scheduleId));

  // Keys, pause, usage.
  // The SDK no longer wraps /v2/keys; older ones do. Plain request.
  const keys = await (await fetch(`${BASE}/v2/keys`, { headers: { authorization: `Bearer ${TOKEN}` } })).json();
  check('/v2/keys returns the signing keys', keys.current === KEYS.currentSigningKey && keys.next === KEYS.nextSigningKey);
  await op('PUT', `/orgs/${ORG}`, { enabled: false, ...KEYS });
  check('a paused organization cannot publish', await client.publishJSON({ url: `${DEST}/ok`, body: {} }).then(() => false, (e) => /paused/.test(String(e))));
  await op('PUT', `/orgs/${ORG}`, { enabled: true, ...KEYS });
  const usage = await (await op('GET', `/orgs/${ORG}/usage`)).json();
  check('usage counts published messages', usage.published >= 9 && usage.delivered >= 6, JSON.stringify(usage));

  await op('DELETE', `/orgs/${ORG}`);
  check('a purged organization is gone', await client.publishJSON({ url: `${DEST}/ok`, body: {} }).then(() => false, () => true));
} catch (e) {
  console.error(e);
  console.error(log.slice(-3000));
  failures++;
} finally {
  stop();
}
console.log(failures === 0 ? 'all passed' : `${failures} failed`);
process.exit(failures === 0 ? 0 : 1);
