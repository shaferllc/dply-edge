// Run by EdgeContainerDeployTest: the Worker's vector REST block (prepended to
// this file by the test) behind a local HTTP server with an in-memory
// Vectorize binding, called with raw requests and, when @upstash/vector is
// importable, with the real SDK. Prints "ok" or the first mismatch.
import http from 'node:http';

let metered = 0;
function dplyMetered(kind, binding) { return { query: async (...a) => { metered++; return binding.query(...a); } }; }
const store = new Map();
const fake = {
  async describe() { return { dimensions: 3, vectorCount: store.size, config: { dimensions: 3, metric: 'cosine' } }; },
  async upsert(vs) { for (const v of vs) store.set(v.id, structuredClone(v)); return { mutationId: 'm' }; },
  async getByIds(ids) { return ids.filter((id) => store.has(id)).map((id) => store.get(id)); },
  async deleteByIds(ids) { for (const id of ids) store.delete(id); return { mutationId: 'm' }; },
  async query(vec, o) {
    const cos = (a, b) => a.reduce((s, x, i) => s + x * b[i], 0) / (Math.hypot(...a) * Math.hypot(...b));
    const matches = [...store.values()].filter((v) => (v.namespace || undefined) === o.namespace)
      .map((v) => ({ id: v.id, score: cos(vec, v.values), ...(o.returnValues ? { values: v.values } : {}), ...(o.returnMetadata !== 'none' && v.metadata ? { metadata: v.metadata } : {}) }))
      .sort((a, b) => b.score - a.score).slice(0, o.topK);
    return { matches, count: matches.length };
  },
};
const env = { DOCS: fake, DPLY_VECTOR_TOKEN_DOCS: 'dvx_test' };
const server = http.createServer(async (req, res) => {
  let body = ''; for await (const c of req) body += c;
  const url = new URL(req.url, 'http://x');
  const reply = await vectorRestFetch(new Request(url, { method: req.method, headers: req.headers, body: req.method === 'GET' ? undefined : body }), env, {}, url);
  if (!reply) { res.writeHead(404); res.end(); return; }
  res.writeHead(reply.status, { 'content-type': 'application/json' }); res.end(await reply.text());
});
await new Promise((r) => server.listen(0, '127.0.0.1', r));
const base = `http://127.0.0.1:${server.address().port}/_vector/DOCS`;
const call = async (cmd, body, token = 'dvx_test') => { const r = await fetch(`${base}/${cmd}`, { method: 'POST', headers: { authorization: `Bearer ${token}` }, body: JSON.stringify(body) }); return [r.status, await r.json()]; };
const fail = (what, got) => { console.log(`FAIL ${what}: ${JSON.stringify(got)}`); server.close(); process.exit(1); };
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);

let [s, j] = await call('info', {}, 'nope'); if (s !== 401) fail('bad token', [s, j]);
[s, j] = await call('upsert', [{ id: 'a', vector: [1, 0, 0], metadata: { t: 'a' } }, { id: 'b', vector: [0, 1, 0] }]); if (!same(j, { result: 'Success' })) fail('upsert', j);
[s, j] = await call('upsert', { id: 'c', vector: [1, 0] }); if (s !== 400) fail('wrong dimensions refused', j);
[s, j] = await call('query', { vector: [0.9, 0.1, 0], topK: 1, includeMetadata: true }); if (j.result?.[0]?.id !== 'a' || j.result[0].metadata?.t !== 'a') fail('query', j);
if (metered !== 1) fail('queries go through the meter', metered);
[s, j] = await call('fetch', { ids: ['a', 'zz'], includeVectors: true }); if (!same(j.result, [{ id: 'a', vector: [1, 0, 0] }, null])) fail('fetch', j);
[s, j] = await call('upsert/ns1', { id: 'n', vector: [0, 0, 1] });
[s, j] = await call('query/ns1', { vector: [0, 0, 1], topK: 5 }); if (!same(j.result.map((m) => m.id), ['n'])) fail('namespaces', j);
[s, j] = await call('delete', { ids: ['b', 'missing'] }); if (!same(j, { result: { deleted: 1 } })) fail('delete', j);
[s, j] = await call('info', {}); if (j.result?.dimension !== 3 || j.result.similarityFunction !== 'COSINE') fail('info', j);
for (const cmd of ['upsert-data', 'query-data', 'range', 'reset']) { [s, j] = await call(cmd, {}); if (s !== 400) fail(`${cmd} refused`, [s, j]); }
[s, j] = await call('query', { vector: [1, 0, 0], topK: 1, filter: "t = 'a'" }); if (s !== 400) fail('filters refused', j);

// The real SDK, when it is installed next to this script.
let Index = null;
try { ({ Index } = await import('@upstash/vector')); } catch { Index = null; }
if (Index) {
  const index = new Index({ url: base, token: 'dvx_test', retry: false });
  if ((await index.upsert({ id: 'sdk', vector: [0, 1, 1], metadata: { from: 'sdk' } })) !== 'Success') fail('sdk upsert', null);
  const matches = await index.query({ vector: [0, 1, 1], topK: 1, includeMetadata: true });
  if (matches[0]?.id !== 'sdk' || matches[0].metadata?.from !== 'sdk') fail('sdk query', matches);
  const fetched = await index.fetch(['sdk', 'nope']);
  if (fetched[0]?.id !== 'sdk' || fetched[1] !== null) fail('sdk fetch', fetched);
  if ((await index.delete('sdk')).deleted !== 1) fail('sdk delete', null);
  if ((await index.info()).dimension !== 3) fail('sdk info', null);
  let refused = false; try { await index.reset(); } catch (e) { refused = /not supported/.test(String(e)); }
  if (!refused) fail('sdk reset refused', null);
  console.log('sdk ok');
}
server.close();
console.log('ok');
