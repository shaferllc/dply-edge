import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import http from 'node:http';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { describe, it } from 'node:test';
import { promisify } from 'node:util';

const run = promisify(execFile);

describe('dply edge databases', () => {
  it('lists an app’s databases, queries one by name, and starts an export and a restore', async () => {
    const seen = [];
    const reports = { name: 'reports', engine: 'mysql', primary: false, env_prefix: 'REPORTS', size: '0.25', disk_gb: 1, last_backup_at: null, restore: { status: 'running', target: '2026-09-28T14:30:00Z' } };
    const server = http.createServer((req, res) => {
      let body = '';
      req.on('data', (chunk) => { body += chunk; });
      req.on('end', () => {
        seen.push(`${req.method} ${req.url} ${body}`);
        res.setHeader('content-type', 'application/json');
        const base = '/api/v1/edge/sites/site1/databases';
        if (req.url === base) return res.end(JSON.stringify({ data: [{ ...reports, name: 'main', engine: 'postgres', primary: true, env_prefix: '' }, reports] }));
        if (req.url === `${base}/reports/query`) return res.end(JSON.stringify({ data: { columns: ['n'], rows: [[1], [2]], truncated: false } }));
        return res.end(JSON.stringify({ data: reports }));
      });
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    // Site commands use the saved login (~/.dply/config.json), not DPLY_TOKEN.
    const home = await mkdtemp(join(tmpdir(), 'dply-databases-'));
    const baseUrl = `http://127.0.0.1:${server.address().port}`;
    await mkdir(join(home, '.dply'));
    await writeFile(join(home, '.dply', 'config.json'), JSON.stringify({ token: 't', baseUrl }));
    const env = { ...process.env, DPLY_EDGE_SITE: 'site1', NO_COLOR: '1', HOME: home };
    const dply = (...args) => run('node', ['bin/dply.mjs', 'edge', 'databases', ...args], { env });

    try {
      const list = await dply('list');
      assert.match(list.stdout, /main\s+postgres\s+DB_\* \(primary\)/);
      assert.match(list.stdout, /reports\s+mysql\s+REPORTS_\*/);
      const query = await dply('query', 'reports', 'select', 'n', 'from', 't');
      assert.match(query.stdout, /2 row\(s\)/);
      await dply('export', 'reports');
      assert.match((await dply('restore', 'reports', '2026-09-28 14:30')).stdout, /Restoring reports to 2026-09-28T14:30:00Z/);
    } finally {
      server.close();
    }

    assert.ok(seen.includes('POST /api/v1/edge/sites/site1/databases/reports/query {"sql":"select n from t"}'));
    assert.ok(seen.includes('POST /api/v1/edge/sites/site1/databases/reports/exports {}'));
    assert.ok(seen.includes('POST /api/v1/edge/sites/site1/databases/reports/restore {"at":"2026-09-28 14:30"}'));
  });
});
