/**
 * The list of sites this token can see — Edge sites, the only kind dply hosts.
 *
 * Normalized rows so every caller (`dply sites`, the link picker, the site
 * resolver) works in one shape. A failed fetch yields no rows: a token without
 * `edge.read` gets an empty list and a "refresh permissions" nudge, not a crash.
 */
import { matchRows } from './pick.mjs';

/** @typedef {{ id: string, name: string, kind: 'edge', status: string, url: string, hint: string, raw: Record<string, any> }} SiteRow */

/**
 * @param {import('./api.mjs').ApiClient} client
 * @returns {Promise<SiteRow[]>}
 */
export async function fetchAllSites(client) {
  let rows;
  try {
    rows = (await client.get('/edge/sites'))?.data ?? [];
  } catch {
    rows = [];
  }

  return rows.map(toEdgeRow).sort((a, b) => a.name.localeCompare(b.name));
}

/**
 * @param {SiteRow[]} rows
 * @param {string} needle
 * @returns {SiteRow[]}
 */
export function matchSites(rows, needle) {
  if (/^[0-9A-Za-z]{26}$/.test(needle.trim())) {
    return rows.filter((row) => row.id === needle.trim());
  }

  return matchRows(rows, needle, (row) => row.name);
}

/** @param {Record<string, any>} row */
function toEdgeRow(row) {
  return {
    id: String(row.id),
    name: String(row.name ?? row.id),
    kind: /** @type {'edge'} */ ('edge'),
    status: String(row.status ?? '—'),
    url: String(row.live_url ?? row.hostname ?? '—'),
    hint: [row.runtime_mode, row.is_preview ? 'preview' : ''].filter(Boolean).join(' · '),
    raw: row,
  };
}
