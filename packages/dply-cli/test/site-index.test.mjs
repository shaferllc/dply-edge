import assert from 'node:assert/strict';
import test from 'node:test';
import { fetchAllSites, matchSites } from '../src/site-index.mjs';

/** Minimal ApiClient stand-in: path → rows, or a throw for "no scope". */
function fakeClient(byPath) {
  return {
    async get(path) {
      const value = byPath[path];
      if (value === undefined) {
        throw new Error(`403 for ${path}`);
      }

      return { data: value };
    },
  };
}

const client = () => fakeClient({
  '/edge/sites': [
    { id: 'b'.repeat(26), name: 'docs-site', status: 'live', hostname: 'docs.acme.com', runtime_mode: 'static' },
    { id: 'c'.repeat(26), name: 'checkout', status: 'live', live_url: 'https://checkout.acme.com', is_preview: true },
  ],
});

test('fetchAllSites lists Edge sites by name, normalized', async () => {
  const rows = await fetchAllSites(client());

  assert.deepEqual(rows.map((r) => `${r.kind}:${r.name}`), ['edge:checkout', 'edge:docs-site']);
  assert.equal(rows[0].url, 'https://checkout.acme.com');
  assert.equal(rows[0].hint, 'preview');
  assert.equal(rows[1].url, 'docs.acme.com');
});

test('fetchAllSites reads a token without edge.read as no sites', async () => {
  assert.deepEqual(await fetchAllSites(fakeClient({})), []);
});

test('matchSites takes an id verbatim and a name loosely', async () => {
  const rows = await fetchAllSites(client());

  assert.deepEqual(matchSites(rows, 'c'.repeat(26)).map((r) => r.name), ['checkout']);
  assert.deepEqual(matchSites(rows, 'check').map((r) => r.name), ['checkout']);
  assert.deepEqual(matchSites(rows, 'site').map((r) => r.name), ['docs-site']);
  assert.deepEqual(matchSites(rows, 'nope'), []);
});
