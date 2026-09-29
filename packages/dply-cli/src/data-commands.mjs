import { requireClient } from './server-context.mjs';
import { c, info, ok, printJson, printTable } from './print.mjs';

/**
 * dply db list | query <database> "<sql>"
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function dbCommand(args, flags) {
  const [sub, name, ...rest] = args;
  const client = await requireClient(flags);

  if (!sub || sub === 'list') {
    const rows = (await client.get('/edge/databases'))?.data ?? [];
    if (flags.json) return printJson(rows);
    if (rows.length === 0) return info(c.dim('No databases. Create one under Projects → Databases.'));
    return printTable(['NAME', 'ID', 'CREATED'], rows.map((db) => [db.name, db.cloudflare_id, db.created_at ?? '']));
  }

  if (sub === 'query') {
    const sql = rest.join(' ').trim();
    if (!name || !sql) throw usage('dply db query <database> "<sql>"');
    const statements = (await client.post(`/edge/databases/${encodeURIComponent(name)}/query`, { sql }))?.data ?? [];
    if (flags.json) return printJson(statements);
    for (const statement of statements) {
      const results = statement.results ?? [];
      if (results.length === 0) {
        info(c.dim(`${statement.meta?.changes ?? 0} change(s), ${statement.meta?.rows_read ?? 0} row(s) read`));
        continue;
      }
      const columns = Object.keys(results[0]);
      printTable(columns.map((col) => col.toUpperCase()), results.map((row) => columns.map((col) => formatCell(row[col]))));
    }
    return;
  }

  throw usage('dply db list | query <database> "<sql>"');
}

/**
 * dply queues list | send <queue> '<json>'
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function queuesCommand(args, flags) {
  const [sub, name, ...rest] = args;
  const client = await requireClient(flags);

  if (!sub || sub === 'list') {
    const rows = (await client.get('/edge/queues'))?.data ?? [];
    if (flags.json) return printJson(rows);
    if (rows.length === 0) return info(c.dim('No queues. Create one under Projects → Queues.'));
    return printTable(['NAME', 'CLOUDFLARE NAME'], rows.map((q) => [q.name, q.cloudflare_name]));
  }

  if (sub === 'send') {
    const raw = rest.join(' ').trim();
    if (!name || !raw) throw usage(`dply queues send <queue> '{"hello":"world"}'`);
    let body;
    try {
      body = JSON.parse(raw);
    } catch {
      body = raw;
    }
    await client.post(`/edge/queues/${encodeURIComponent(name)}/messages`, { body });
    return ok(`Message sent to ${name}.`);
  }

  throw usage(`dply queues list | send <queue> '<json>'`);
}

const KV_USAGE = 'dply kv list | keys <store> [--prefix p] [--cursor c] | get <store> <key> | put <store> <key> <value> [--ttl s | --expires-at unix] [--metadata json] | delete <store> <key>';

/**
 * dply kv — admin access to key-value stores. The API allows 60 calls a
 * minute per organization; apps use their internal host or binding.
 *
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function kvCommand(args, flags) {
  const [sub, store, key, ...rest] = args;
  const client = await requireClient(flags);
  const keyPath = (name) => `/edge/kv/${encodeURIComponent(store)}/keys/${String(name).split('/').map(encodeURIComponent).join('/')}`;

  if (!sub || sub === 'list') {
    const rows = (await client.get('/edge/kv'))?.data ?? [];
    if (flags.json) return printJson(rows);
    if (rows.length === 0) return info(c.dim('No key-value stores. Add one to an app under Add resource → Key-value store.'));
    return printTable(['NAME', 'ID'], rows.map((row) => [row.name, row.id]));
  }

  if (sub === 'keys') {
    if (!store) throw usage(KV_USAGE);
    const query = new URLSearchParams();
    if (typeof flags.prefix === 'string') query.set('prefix', flags.prefix);
    if (typeof flags.cursor === 'string') query.set('cursor', flags.cursor);
    const qs = query.toString();
    const page = await client.get(`/edge/kv/${encodeURIComponent(store)}/keys${qs ? `?${qs}` : ''}`);
    if (flags.json) return printJson(page);
    const rows = page?.data ?? [];
    if (rows.length === 0) info(c.dim('No keys.'));
    else printTable(['KEY', 'EXPIRES', 'METADATA'], rows.map((row) => [row.name, row.expiration ? new Date(row.expiration * 1000).toISOString() : '', formatCell(row.metadata)]));
    if (page?.cursor) info(c.dim(`More keys: --cursor ${page.cursor}`));
    return;
  }

  if (sub === 'get') {
    if (!store || !key) throw usage(KV_USAGE);
    const row = (await client.get(keyPath(key)))?.data;
    if (flags.json) return printJson(row);
    process.stdout.write(row.encoding === 'base64' ? Buffer.from(row.value, 'base64') : `${row.value}\n`);
    return;
  }

  if (sub === 'put') {
    const value = rest.join(' ');
    if (!store || !key || rest.length === 0) throw usage(KV_USAGE);
    const body = { value };
    if (flags.ttl !== undefined) body.ttl = Number(flags.ttl);
    if (flags['expires-at'] !== undefined) body.expires_at = Number(flags['expires-at']);
    if (typeof flags.metadata === 'string') {
      try {
        body.metadata = JSON.parse(flags.metadata);
      } catch {
        throw usage('--metadata must be JSON, e.g. --metadata \'{"by":"ci"}\'');
      }
    }
    await client.put(keyPath(key), body);
    return ok(`Saved ${key}.`);
  }

  if (sub === 'delete') {
    if (!store || !key) throw usage(KV_USAGE);
    await client.delete(keyPath(key));
    return ok(`Deleted ${key}.`);
  }

  throw usage(KV_USAGE);
}

function formatCell(value) {
  if (value === null || value === undefined) return '';
  return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

function usage(text) {
  const err = new Error(`Usage: ${text}`);
  err.exitCode = 2;
  return err;
}
