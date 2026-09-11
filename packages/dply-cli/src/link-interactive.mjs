import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { writeSiteLink } from './config.mjs';
import { fetchAllSites } from './site-index.mjs';
import { c, info, ok, warn } from './print.mjs';

/**
 * @param {import('./api.mjs').ApiClient} api
 * @param {{ baseUrl: string }} ctx
 * @returns {Promise<boolean>}
 */
export async function interactiveLinkSite(api, ctx) {
  if (!input.isTTY || !output.isTTY) {
    return false;
  }

  const rows = await fetchAllSites(api);

  if (rows.length === 0) {
    return false;
  }

  info('');
  info(c.bold('Link this repo to a site'));
  info(c.dim('Pick a number · or cancel with Enter'));
  info('');

  rows.forEach((row, i) => {
    const hint = [row.url, row.status].filter(Boolean).join(' · ');
    info(`  ${c.cyan(String(i + 1).padStart(2, ' '))}  ${row.name}${hint ? c.dim(` — ${hint}`) : ''}`);
  });

  info('');

  const rl = readline.createInterface({ input, output, terminal: true });

  try {
    const answer = (await rl.question(`${c.bold('Choose')}› `)).trim();

    if (answer === '') {
      info(c.dim('Cancelled.'));

      return true;
    }

    const picked = rows[Number.parseInt(answer, 10) - 1];
    if (!picked || !/^\d+$/.test(answer)) {
      warn(`Enter a number 1–${rows.length}, or press Enter to cancel.`);

      return true;
    }

    await writeLinkRecord(ctx, picked.raw);
  } finally {
    rl.close();
  }

  return true;
}

/**
 * @param {{ baseUrl: string }} ctx
 * @param {Record<string, unknown>} site  an /edge/sites row
 */
export async function writeLinkRecord(ctx, site) {
  const path = await writeSiteLink({
    siteId: String(site.id),
    siteName: String(site.name ?? site.id),
    baseUrl: ctx.baseUrl,
    organizationId: site.organization_id != null ? String(site.organization_id) : undefined,
    product: 'edge',
    kind: 'edge',
  });
  ok(`Linked Edge site ${c.cyan(String(site.name ?? site.id))} (${site.id}) → ${c.dim(path)}`);
  info(c.dim('Deploy: `dply deploy` · more: `dply edge --help`'));

  return path;
}
