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

function formatCell(value) {
  if (value === null || value === undefined) return '';
  return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

function usage(text) {
  const err = new Error(`Usage: ${text}`);
  err.exitCode = 2;
  return err;
}
