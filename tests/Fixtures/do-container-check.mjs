// Runs a generated container Worker (durable_object scheduling) against a
// mock ctx.container. Used by EdgeContainerDeployTest; prints "ok" or throws.
// Usage: node do-container-check.mjs <worker.mjs> (its cloudflare:workers
// import already swapped for the stubs below by the test).
const assert = (cond, msg) => { if (!cond) throw new Error('FAIL: ' + msg); };

class MockContainer {
  // running: the build label of a container already up ('old' = a previous deploy).
  constructor(images, running = null) {
    this.images = images; this.started = []; this.signals = []; this.intercepts = [];
    this.running = false;
    if (running) this.boot({ image: images.app, labels: { 'dply-build': running === 'current' ? BUILD_ID : 'old' } });
  }
  boot(o) { this.running = true; this.image = o.image; this.labels = o.labels ?? {}; this.exitP = new Promise((res) => { this.exit = res; }); }
  start(o) {
    if (this.running) throw new Error('already running');
    if (o.containerSnapshot && o.image) throw new Error('image and containerSnapshot are exclusive');
    if (o.containerSnapshot && this.badSnapshot) throw new Error('snapshot expired');
    this.started.push(o); this.boot(o);
  }
  async snapshotContainer({ name }) { this.snapshots = (this.snapshots ?? 0) + 1; return { id: 'snap-' + this.snapshots, size: 1048576, name }; }
  monitor() { return this.exitP; }
  // A different string than images.app on purpose: stale checks use the label.
  async inspect() { return this.running ? { image: 'registry/' + this.image, labels: this.labels } : null; }
  signal(n) { this.signals.push(n); this.running = false; this.exit(0); }
  async destroy() { this.running = false; this.exit(0); }
  async setInactivityTimeout(ms) { this.inactivity = ms; }
  async interceptAllOutboundHttp(f) { this.intercepts.push(['http:*', f]); }
  async interceptOutboundHttps(h, f) { this.intercepts.push(['https:' + h, f]); }
  getTcpPort() {
    return { fetch: async (url) => { if (!this.running) throw new Error('not listening'); return new Response('hello ' + url); } };
  }
}

function makeCtx(name, container) {
  const data = new Map();
  const ctx = {
    id: { name, toString: () => name },
    container,
    alarmAt: null,
    storage: {
      get: async (k) => data.get(k), put: async (k, v) => { data.set(k, v); }, delete: async (k) => data.delete(k),
      setAlarm: async (t) => { ctx.alarmAt = t; }, getAlarm: async () => ctx.alarmAt,
    },
    pending: [],
    waitUntil(p) { ctx.pending.push(p); },
    blockConcurrencyWhile(fn) { const p = fn(); ctx.pending.push(p); return p; },
  };
  ctx.exports = { ContainerProxy: ({ props }) => new ContainerProxy({ props }, {}) };
  return ctx;
}
const settle = (ctx) => Promise.all(ctx.pending);
globalThis.IdentityTransformStream ??= TransformStream;
globalThis.fetch = async () => new Response('internet');

export async function run() {
  const env = { DPLY_QUEUE_TOKEN: 'tok' };

  // 1. A cold request starts the new image at the chosen size, probes, proxies.
  const c1 = new MockContainer({ app: 'img@new' });
  const ctx1 = makeCtx('instance-0', c1);
  const app = new App(ctx1, env);
  await settle(ctx1);
  const res = await app.fetch(new Request('https://example.test/hello'));
  assert(await res.text() === 'hello http://example.test/hello', 'proxied over http');
  assert(c1.started.length === 1 && c1.started[0].image === 'img@new', 'started the configured image');
  assert(JSON.stringify(c1.started[0].instance) === JSON.stringify(DO_INSTANCE), 'instance size passed');
  assert(c1.started[0].enableInternet === true, 'internet on');
  assert(c1.started[0].labels['dply-build'] === BUILD_ID, 'labelled with the build');
  assert(c1.started[0].env.DPLY_QUEUE_TOKEN === 'tok' && 'DPLY_PHP_FPM_MAX_CHILDREN' in c1.started[0].env, 'env passed');
  assert(c1.inactivity === 6 * 3600 * 1000, 'inactivity backstop set');
  assert(c1.intercepts.some(([k]) => k === 'http:*'), 'outbound intercepted');
  await new Promise((r) => setTimeout(r, 10));
  assert(app.inflightRequests === 0, 'request no longer in flight once its body is read');
  assert((await app.getState()).status === 'healthy', 'healthy after the probe');
  assert(ctx1.alarmAt !== null, 'sleep alarm scheduled');

  // 2. Idle past sleepAfter: the alarm stops it (SIGTERM) and state follows.
  app.sleepAfterMs = Date.now() - 1;
  await app.alarm();
  assert(c1.signals[0] === 15 && !c1.running, 'slept with SIGTERM');
  await new Promise((r) => setTimeout(r, 10));
  assert((await app.getState()).status === 'stopped_with_code', 'state stopped');

  // 3. Busy: an in-flight request keeps it awake.
  const c3 = new MockContainer({ app: 'img@new' }, 'current');
  const ctx3 = makeCtx('instance-0', c3);
  const busy = new App(ctx3, env);
  await settle(ctx3);
  busy.inflightRequests = 1; busy.sleepAfterMs = Date.now() - 1;
  await busy.alarm();
  assert(c3.running && c3.signals.length === 0, 'in-flight request keeps it awake');

  // 4. After a deploy: still on the old image, replaced on its next request.
  const c4 = new MockContainer({ app: 'img@new' }, 'old');
  const ctx4 = makeCtx('instance-0', c4);
  const old = new App(ctx4, env);
  await settle(ctx4);
  assert(old.stale === true, 'old image seen as stale');
  const res4 = await old.fetch(new Request('https://example.test/'));
  assert(res4.status === 200 && c4.signals[0] === 15 && c4.labels['dply-build'] === BUILD_ID, 'stale instance replaced by this build');

  // 4b. A current container is not stale even though its image string differs.
  const c4b = new MockContainer({ app: 'img@new' }, 'current');
  const ctx4b = makeCtx('instance-1', c4b);
  const cur = new App(ctx4b, env);
  await settle(ctx4b);
  assert(cur.stale === false, 'current build is not stale');

  // 5. Outbound: exact host handler, then the catch-all.
  App.outboundByHost = { ...(App.outboundByHost ?? {}), 'dply.app.kv.internal': async () => new Response('kv') };
  const proxy = ctx1.exports.ContainerProxy({ props: {} });
  assert(await (await proxy.fetch(new Request('http://dply.app.kv.internal/k'))).text() === 'kv', 'resource host routed');
  assert(await (await proxy.fetch(new Request('http://example.com/'))).text() === 'internet', 'other hosts reach the internet');

  // 6. Workers: startWorker replaces a stale one, and a current one is left alone.
  const c6 = new MockContainer({ app: 'img@new' }, 'current');
  const ctx6 = makeCtx('worker-0', c6);
  const w = new App(ctx6, env);
  await settle(ctx6);
  await w.startWorker('worker-0');
  assert(c6.started.length === 0, 'a current worker is not restarted');

  // 7. A stale worker gets one SIGTERM and is not restarted in the same call.
  const c7 = new MockContainer({ app: 'img@new' }, 'old');
  c7.signal = function (n) { this.signals.push(n); }; // the job is still running
  const ctx7 = makeCtx('worker-0', c7);
  const w7 = new App(ctx7, env);
  await settle(ctx7);
  await w7.startWorker('worker-0');
  await w7.startWorker('worker-0');
  assert(c7.signals.length === 1 && c7.signals[0] === 15 && c7.started.length === 0 && c7.running, 'stale worker drained, not killed');
  // 9. dply's uptime check (x-dply-uptime + DPLY_UPTIME_TOKEN): asleep is
  //    answered without waking; awake is proxied without counting as activity.
  const envU = { ...env, DPLY_UPTIME_TOKEN: 'up' };
  const c9 = new MockContainer({ app: 'img@new' });
  const ctx9 = makeCtx('instance-0', c9);
  const a9 = new App(ctx9, envU);
  await settle(ctx9);
  const asleep = await a9.fetch(new Request('https://example.test/', { headers: { 'x-dply-uptime': 'up' } }));
  assert(asleep.status === 204 && asleep.headers.get('x-dply-asleep') === '1' && c9.started.length === 0, 'asleep: answered, not woken');
  const forged = await a9.fetch(new Request('https://example.test/', { headers: { 'x-dply-uptime': 'nope' } }));
  assert(forged.status === 200 && c9.started.length === 1, 'a wrong token is an ordinary request');
  const before = a9.sleepAfterMs; const seen = a9.lastActivityAt;
  await new Promise((r) => setTimeout(r, 5));
  const awake = await a9.fetch(new Request('https://example.test/', { headers: { 'x-dply-uptime': 'up' } }));
  assert(awake.status === 200 && a9.sleepAfterMs === before && a9.lastActivityAt === seen, 'awake: checked without renewing the sleep timer');

  // 10. Rebuilt after eviction (a new object, same storage): the saved deadline
  //     holds, so the call that rebuilt it does not push sleep back.
  const c10 = new MockContainer({ app: 'img@new' }, 'current');
  const ctx10 = makeCtx('instance-0', c10);
  const first = new App(ctx10, env);
  await settle(ctx10);
  first.sleepAfterMs = Date.now() - 1000; await first.scheduleAlarm();
  const rebuilt = new App(ctx10, env);
  await settle(ctx10);
  assert(rebuilt.sleepAfterMs === first.sleepAfterMs, 'deadline restored, not renewed');
  await rebuilt.getState(); // an RPC from the Worker (instances list, uptime) is not activity
  await rebuilt.alarm();
  assert(!c10.running && c10.signals[0] === 15, 'slept on time after a rebuild');

  if (RELEASE_KEY) {
    // 8. Release bundle: first start from the image, then a snapshot; the next start uses it.
    const c8 = new MockContainer({ app: 'img@new' });
    const ctx8 = makeCtx('instance-0', c8);
    const a8 = new App(ctx8, env);
    await settle(ctx8);
    await a8.fetch(new Request('https://example.test/'));
    assert(c8.started[0].image === 'img@new' && c8.started[0].env.DPLY_RELEASE === RELEASE_KEY, 'first start from the image, with DPLY_RELEASE');
    await settle(ctx8);
    const saved = await ctx8.storage.get('dply:snapshot');
    assert(saved && saved.build === BUILD_ID && saved.snapshot.id === 'snap-1', 'snapshot saved for this build');
    c8.signal(15); // slept
    const a8b = new App(ctx8, env);
    await settle(ctx8);
    await a8b.fetch(new Request('https://example.test/'));
    assert(c8.started[1].containerSnapshot?.id === 'snap-1' && !c8.started[1].image, 'woke from the snapshot');
    assert(c8.snapshots === 1, 'no second snapshot for the same build');
    // An unusable snapshot falls back to the image and is forgotten.
    c8.signal(15); c8.badSnapshot = true;
    const a8c = new App(ctx8, env);
    await settle(ctx8);
    await a8c.fetch(new Request('https://example.test/'));
    assert(c8.started[2].image === 'img@new', 'falls back to the image');
  }
  return 'ok';
}
