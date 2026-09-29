import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import http from 'node:http';
import { describe, it } from 'node:test';
import { promisify } from 'node:util';

const run = promisify(execFile);

describe('dply kv', () => {
  it('reads keys, puts with ttl and metadata, and keeps slashes in key paths', async () => {
    const seen = [];
    const server = http.createServer((req, res) => {
      let body = '';
      req.on('data', (chunk) => { body += chunk; });
      req.on('end', () => {
        seen.push(`${req.method} ${req.url} ${body}`);
        res.setHeader('content-type', 'application/json');
        if (req.url.startsWith('/api/v1/edge/kv/cache/keys?')) {
          return res.end(JSON.stringify({ data: [{ name: 'user:1', expiration: null, metadata: { v: 1 } }], cursor: 'next' }));
        }
        if (req.method === 'GET') return res.end(JSON.stringify({ data: { key: 'a/b', value: 'hello', encoding: 'utf-8', metadata: null } }));
        return res.end(JSON.stringify({ data: {} }));
      });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    const env = { ...process.env, DPLY_TOKEN: 't', DPLY_API_BASE_URL: `http://127.0.0.1:${server.address().port}`, NO_COLOR: '1', HOME: '/tmp/dply-kv-test' };
    const dply = (...args) => run('node', ['bin/dply.mjs', 'kv', ...args], { env });

    try {
      const keys = await dply('keys', 'cache', '--prefix', 'user:');
      assert.match(keys.stdout, /user:1/);
      assert.match(keys.stdout + keys.stderr, /--cursor next/);
      assert.equal((await dply('get', 'cache', 'a/b')).stdout, 'hello\n');
      await dply('put', 'cache', 'a/b', 'hi', 'there', '--ttl', '120', '--metadata', '{"by":"ci"}');
      await dply('delete', 'cache', 'a/b');
    } finally {
      server.close();
    }

    assert.ok(seen.includes('GET /api/v1/edge/kv/cache/keys?prefix=user%3A '));
    assert.ok(seen.includes('GET /api/v1/edge/kv/cache/keys/a/b '));
    assert.ok(seen.includes('PUT /api/v1/edge/kv/cache/keys/a/b {"value":"hi there","ttl":120,"metadata":{"by":"ci"}}'));
    assert.ok(seen.includes('DELETE /api/v1/edge/kv/cache/keys/a/b '));
  });
});
