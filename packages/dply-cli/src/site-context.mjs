import { readSiteLink } from './config.mjs';
import { pickRow } from './pick.mjs';
import { fetchAllSites, matchSites } from './site-index.mjs';

/**
 * Resolve a site by id or name: --site, a positional, $DPLY_SITE /
 * $DPLY_EDGE_SITE, or the linked folder — and on a TTY, a picker when none of
 * those says.
 *
 * @param {import('./api.mjs').ApiClient} client
 * @param {Record<string, unknown>} flags
 * @param {string|undefined} positional
 * @returns {Promise<string>}
 */
export async function resolveAnySiteId(client, flags, positional) {
  const candidate = String(flags.site || flags.s || positional || process.env.DPLY_SITE || process.env.DPLY_EDGE_SITE || '').trim()
    || (await readSiteLink())?.link?.siteId
    || '';

  if (/^[0-9A-Za-z]{26}$/.test(String(candidate))) {
    return String(candidate);
  }

  const rows = await fetchAllSites(client);

  if (! candidate) {
    const picked = await pickRow(rows, {
      title: 'Which site?',
      hint: (row) => [row.url, row.status].filter(Boolean).join(' · '),
    });

    if (picked?.id) {
      return String(picked.id);
    }

    throw cliError('No site specified. Pass --site <id-or-name>, set DPLY_EDGE_SITE, or link this repo with `dply link`.', 2);
  }

  const matches = matchSites(rows, String(candidate));

  if (matches.length === 1) {
    return matches[0].id;
  }

  if (matches.length > 1) {
    const picked = await pickRow(matches, {
      title: `Sites matching "${candidate}"`,
      hint: (row) => [row.url, row.status].filter(Boolean).join(' · '),
    });

    if (picked?.id) {
      return String(picked.id);
    }
  }

  throw cliError(`No site matched "${candidate}". Run \`dply sites\`.`, 2);
}

/**
 * What the linked folder points at: 'edge', null when unlinked, or the old
 * product name ('byo', 'serverless', 'cloud', …) for a folder linked by an
 * earlier CLI — the caller refuses those rather than sending a non-Edge site id
 * to the Edge API.
 *
 * @returns {Promise<string | null>}
 */
export async function linkedSiteProduct() {
  const link = await readSiteLink();

  if (!link?.link?.siteId) {
    return null;
  }

  return link.link.kind ?? link.link.product ?? 'edge';
}

/**
 * @param {string} message
 * @param {number} [exitCode]
 */
function cliError(message, exitCode = 2) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}
