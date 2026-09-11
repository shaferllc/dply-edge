import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { ApiClient } from './api.mjs';
import { readGlobalConfig, resolveContext } from './config.mjs';
import { requireClient } from './server-context.mjs';
import { fetchEdgeSitesSafe, runSmartShellCommand } from './smart-shell.mjs';
import { expandArgv } from './shortcuts.mjs';
import { c, info, warn } from './print.mjs';

/** @type {Set<string>} */
const MENU_LABEL_STOP_WORDS = new Set(['a', 'an', 'the', 'to', 'from', 'in', 'on', 'for', 'and', 'or']);

/**
 * @typedef {object} MenuItem
 * @property {string} label
 * @property {string} [hint]
 * @property {string} [value]
 * @property {() => Promise<'back' | 'exit' | void>} [action]
 * @property {string} [submenu]
 * @property {string[]} [argv]
 * @property {string[]} [keywords]
 */

/**
 * Standalone menu session (`dply menu`).
 */
export async function enterInteractiveMenu() {
  if (!input.isTTY || !output.isTTY) {
    throw new Error('Interactive menu requires a TTY. Run `dply menu` in a terminal.');
  }

  const rl = readline.createInterface({ input, output, terminal: true });
  const { run } = await import('./cli.mjs');

  try {
    await runMenuSession(rl, run, 'root');
  } finally {
    rl.close();
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {string} [startMenu]
 */
export async function runMenuSession(rl, run, startMenu = 'root') {
  /** @type {string[]} */
  const stack = [startMenu];

  while (stack.length > 0) {
    const menuId = stack[stack.length - 1];
    const menu = await buildMenu(menuId, { rl, run });

    if (!menu) {
      stack.pop();
      continue;
    }

    const selected = await promptMenu(rl, { ...menu, run });

    if (!selected) {
      if (stack.length === 1) {
        return;
      }

      stack.pop();
      continue;
    }

    if (selected.submenu) {
      stack.push(selected.submenu);
      continue;
    }

    if (selected.argv) {
      try {
        await run(selected.argv);
      } catch (err) {
        warn(err?.message ?? String(err));
      }

      await pauseForMenu(rl);
      continue;
    }

    if (selected.action) {
      const result = await selected.action();

      if (result === 'exit') {
        return;
      }

      if (result === 'back') {
        stack.pop();
        continue;
      }

      await pauseForMenu(rl);
    }
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {{ title: string, subtitle?: string, items: MenuItem[], showBack?: boolean, run?: (argv: string[]) => Promise<number | void> }} options
 * @returns {Promise<MenuItem | null>}
 */
export async function promptMenu(rl, options) {
  const { title, subtitle, items, showBack = true, run } = options;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    info('');
    info(c.bold(title));
    if (subtitle) {
      info(c.dim(subtitle));
    }
    info('');

    items.forEach((item, index) => {
      const num = c.cyan(String(index + 1).padStart(2, ' '));
      const hint = item.hint ? c.dim(` — ${item.hint}`) : '';
      info(`  ${num}  ${item.label}${hint}`);
    });

    if (showBack) {
      info(`  ${c.dim(' b')}  ${c.dim('← Back')}`);
    }

    info(`  ${c.dim(' q')}  ${c.dim('Quit menu')}`);
    info('');
    info(c.dim('  Or type a name/shortcut (sites, refresh, me…) or any dply command'));
    info('');

    let answer;
    try {
      answer = (await rl.question(`${c.bold('Choose')}› `)).trim();
    } catch {
      return null;
    }

    const lowered = answer.toLowerCase();

    if (answer === '' || lowered === 'b' || lowered === 'back') {
      return null;
    }

    if (lowered === 'q' || lowered === 'quit' || lowered === 'exit') {
      return { label: 'quit', action: async () => 'exit' };
    }

    const index = Number.parseInt(answer, 10);
    if (Number.isFinite(index) && index >= 1 && index <= items.length) {
      return items[index - 1];
    }

    const matched = matchMenuItemByText(answer, items);
    if (matched) {
      return matched;
    }

    if (run && answer.trim() !== '') {
      await executeMenuCommand(rl, run, answer);
      continue;
    }

    warn(`Enter 1–${items.length}, a shortcut like "sites", or b/q.`);
  }
}

/**
 * @param {string} answer
 * @param {MenuItem[]} items
 * @returns {MenuItem | null}
 */
export function matchMenuItemByText(answer, items) {
  const normalized = normalizeMenuChoice(answer);

  /** @type {{ item: MenuItem, score: number }[]} */
  const matches = [];

  for (const item of items) {
    for (const alias of collectMenuItemAliases(item)) {
      if (alias === normalized) {
        matches.push({ item, score: 100 + alias.length });
      } else if (normalized.length >= 2 && alias.startsWith(normalized)) {
        matches.push({ item, score: 50 + alias.length });
      } else if (alias.length >= 3 && normalized.startsWith(alias)) {
        matches.push({ item, score: 40 + alias.length });
      }
    }
  }

  if (matches.length === 0) {
    return null;
  }

  matches.sort((a, b) => b.score - a.score);

  return matches[0].item;
}

/**
 * @param {MenuItem} item
 * @returns {string[]}
 */
export function collectMenuItemAliases(item) {
  /** @type {Set<string>} */
  const aliases = new Set();
  const label = item.label.toLowerCase();

  aliases.add(label);

  const words = label.split(/\s+/).filter((word) => !MENU_LABEL_STOP_WORDS.has(word));
  if (words.length > 1) {
    aliases.add(words.join(' '));
  }

  for (const word of words) {
    aliases.add(word);
  }

  if (item.submenu) {
    aliases.add(item.submenu.toLowerCase());
  }

  if (item.argv) {
    aliases.add(item.argv.join(' ').toLowerCase());
    for (const part of item.argv) {
      aliases.add(part.toLowerCase());
    }
  }

  if (item.keywords) {
    for (const keyword of item.keywords) {
      aliases.add(keyword.toLowerCase());
    }
  }

  return [...aliases];
}

/**
 * @param {string} text
 * @returns {string}
 */
function normalizeMenuChoice(text) {
  return text.trim().toLowerCase().replace(/\s+/g, ' ');
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {string} line
 */
async function executeMenuCommand(rl, run, line) {
  const normalized = normalizeMenuLine(line);
  const tokens = expandArgv(tokenizeMenuLine(normalized));

  if (tokens.length === 0) {
    return;
  }

  try {
    await runSmartShellCommand(rl, run, tokens);
  } catch (err) {
    warn(err?.message ?? String(err));
  }

  await pauseForMenu(rl);
}

/**
 * @param {string} line
 * @returns {string}
 */
function normalizeMenuLine(line) {
  const trimmed = line.trim();
  if (trimmed.toLowerCase() === 'dply') {
    return '';
  }

  if (/^dply\s+/i.test(trimmed)) {
    return trimmed.replace(/^dply\s+/i, '').trim();
  }

  return trimmed;
}

/**
 * @param {string} line
 * @returns {string[]}
 */
function tokenizeMenuLine(line) {
  const tokens = [];
  const re = /"([^"\\]*(?:\\.[^"\\]*)*)"|'([^'\\]*(?:\\.[^'\\]*)*)'|(\S+)/g;
  let match;
  while ((match = re.exec(line)) !== null) {
    tokens.push(match[1] ?? match[2] ?? match[3]);
  }

  return tokens;
}

/**
 * @param {import('node:readline/promises').Interface} rl
 */
async function pauseForMenu(rl) {
  info('');
  info(c.dim('Press Enter to continue…'));

  try {
    await rl.question('');
  } catch {
    // ignore
  }
}

/**
 * @param {string} menuId
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
async function buildMenu(menuId, ctx) {
  const cfg = await readGlobalConfig();
  const loggedIn = Boolean(cfg?.token);

  switch (menuId) {
    case 'root':
      return await buildRootMenu(loggedIn, cfg, ctx);
    case 'account':
      return buildAccountMenu(ctx);
    case 'billing':
      return buildBillingMenu();
    case 'edge':
      return await buildEdgeMenu(ctx);
    default:
      return null;
  }
}

/**
 * @param {boolean} loggedIn
 * @param {Awaited<ReturnType<typeof readGlobalConfig>>} cfg
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
async function buildRootMenu(loggedIn, cfg, ctx) {
  /** @type {MenuItem[]} */
  const items = [];

  if (loggedIn) {
    items.push(
      { label: 'Edge', hint: 'sites, deploy, logs', submenu: 'edge', keywords: ['sites'] },
      { label: 'Account', hint: 'profile, orgs, sessions', submenu: 'account' },
      { label: 'Billing', hint: 'plan, breakdown, invoices', submenu: 'billing' },
      { label: 'Command index', hint: 'full list of commands', argv: ['ls'], keywords: ['ls', 'commands'] },
      { label: 'Help', hint: 'detailed command reference', argv: ['help'], keywords: ['help', '?'] },
      {
        label: 'Quick tips',
        hint: 'show the welcome screen again',
        action: async () => {
          const { printShellGuide } = await import('./shell.mjs');
          await printShellGuide();
        },
      },
    );
  } else {
    items.push(
      {
        label: 'Sign in',
        hint: 'browser device-flow login',
        keywords: ['login', 'signin', 'sign-in'],
        action: async () => {
          await ctx.run(['login', '--no-shell']);
        },
      },
      { label: 'Help', hint: 'what you can do before signing in', argv: ['help'] },
      {
        label: 'Quick tips',
        hint: 'getting started',
        action: async () => {
          const { printShellGuide } = await import('./shell.mjs');
          await printShellGuide();
        },
      },
    );
  }

  const subtitle = loggedIn
    ? `Signed in · ${cfg?.baseUrl ?? '—'}`
    : 'Not signed in — start with Sign in';

  return {
    title: 'dply menu',
    subtitle,
    items,
    showBack: false,
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
function buildAccountMenu(ctx) {
  /** @type {MenuItem[]} */
  const items = [
    { label: 'Show profile', hint: 'user, org, token, abilities', argv: ['account', 'show'], keywords: ['profile', 'me', 'who', 'whoami', 'show'] },
    { label: 'Organizations', hint: 'orgs you belong to', argv: ['account', 'orgs'], keywords: ['orgs', 'organizations'] },
    { label: 'CLI sessions', hint: 'active tokens in this org', argv: ['account', 'sessions'], keywords: ['sessions', 'tokens'] },
    {
      label: 'Revoke a session',
      hint: 'pick from list',
      keywords: ['revoke'],
      action: async () => revokeSessionMenu(ctx),
    },
    { label: 'Refresh permissions', hint: 're-approve scopes in browser', argv: ['auth', 'refresh'], keywords: ['refresh', 'r', 'auth'] },
    { label: 'Sign out', hint: 'remove token from this machine', argv: ['logout'], keywords: ['logout', 'signout', 'sign-out'] },
    { label: 'Account help', argv: ['account', 'help'], keywords: ['help'] },
  ];

  return {
    title: 'Account',
    subtitle: 'Profile, organizations, and CLI sessions',
    items,
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
function buildBillingMenu(ctx) {
  return {
    title: 'Billing',
    subtitle: 'Org admin · requires billing.read scope',
    items: [
      { label: 'Plan summary', hint: 'current plan + estimate', argv: ['billing', 'show'] },
      { label: 'Cost breakdown', hint: 'line items', argv: ['billing', 'breakdown'] },
      { label: 'Invoices', hint: 'Stripe history', argv: ['billing', 'invoices'] },
      { label: 'Billing help', argv: ['billing', 'help'] },
    ],
  };
}

async function buildEdgeMenu(ctx) {
  const rows = await fetchEdgeSitesSafe();
  /** @type {MenuItem[]} */
  const items = [];

  if (rows.length === 0) {
    items.push({
      label: 'No Edge sites yet',
      hint: 'create one in the web app',
      argv: ['sites'],
    });
    items.push({
      label: 'Refresh permissions',
      hint: 'need edge scope?',
      argv: ['auth', 'refresh'],
    });
  } else {
    items.push({ label: 'List all sites', hint: `${rows.length} visible`, argv: ['sites'] });
    items.push({
      label: 'Link this repo to a site',
      hint: 'pick from list',
      action: async () => linkSiteMenu(ctx),
    });
    items.push({
      label: 'Deploy',
      hint: 'pick site · uses linked repo if set',
      action: async () => runWithEdgeSite(ctx, ['edge', 'deploy']),
    });
    items.push({
      label: 'Edge status',
      hint: 'latest deployment · --wait in shell',
      action: async () => runWithEdgeSite(ctx, ['edge', 'status']),
    });
    items.push({
      label: 'Recent deployments',
      action: async () => runWithEdgeSite(ctx, ['edge', 'deployments']),
    });
    items.push({
      label: 'Tail request logs',
      action: async () => runWithEdgeSite(ctx, ['edge', 'logs', '--once']),
    });
    items.push({
      label: 'Open live URL',
      action: async () => runWithEdgeSite(ctx, ['edge', 'open']),
    });
  }

  items.push({ label: 'Edge command help', argv: ['edge', '--help'] });

  return {
    title: 'Edge',
    subtitle: rows.length === 0 ? 'No Edge sites visible to this token' : 'Sites, deploys, and delivery',
    items,
  };
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 * @param {string[]} commandPrefix
 */
async function runWithEdgeSite(ctx, commandPrefix) {
  const siteId = await pickEdgeSite(ctx.rl);

  if (!siteId) {
    const linked = await tryLinkedSite();
    if (!linked) {
      warn('No site selected. Link a repo or pick a site from the list.');

      return;
    }

    await ctx.run([...commandPrefix, '--site', linked]);
    return;
  }

  await ctx.run([...commandPrefix, '--site', siteId]);
}

/**
 * @returns {Promise<string | null>}
 */
async function tryLinkedSite() {
  try {
    const ctx = await resolveContext();

    return ctx.siteId ?? null;
  } catch {
    return null;
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @returns {Promise<string | null>}
 */
async function pickEdgeSite(rl) {
  /** @type {Array<{ id: string, name: string, hostname?: string, status?: string }>} */
  let rows;

  try {
    const ctx = await resolveContext();
    const api = new ApiClient(ctx);
    const response = await api.get('/edge/sites');
    rows = response?.data ?? [];
  } catch (err) {
    warn(err?.message ?? String(err));

    return null;
  }

  if (rows.length === 0) {
    warn('No Edge sites visible to this token.');

    return null;
  }

  const choice = await promptMenu(rl, {
    title: 'Select an Edge site',
    items: rows.map((row) => ({
      label: row.name,
      hint: [row.hostname, row.status].filter(Boolean).join(' · '),
      value: row.id,
    })),
  });

  return choice?.value ?? null;
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
async function linkSiteMenu(ctx) {
  const siteId = await pickEdgeSite(ctx.rl);

  if (!siteId) {
    return;
  }

  await ctx.run(['link', siteId]);
}

/**
 * @param {{ rl: import('node:readline/promises').Interface, run: (argv: string[]) => Promise<number | void> }} ctx
 */
async function revokeSessionMenu(ctx) {
  /** @type {Array<{ id: string, user?: { email?: string, name?: string }, masked?: string, prefix?: string, is_current?: boolean }>} */
  let rows;

  try {
    const client = await requireClient({});
    rows = (await client.get('/account/sessions'))?.data ?? [];
  } catch (err) {
    warn(err?.message ?? String(err));

    return;
  }

  if (rows.length === 0) {
    warn('No CLI sessions to revoke.');

    return;
  }

  const choice = await promptMenu(ctx.rl, {
    title: 'Revoke a CLI session',
    subtitle: 'Revoking the current session signs you out on this machine',
    items: rows.map((row) => ({
      label: row.user?.email ?? row.user?.name ?? row.id,
      hint: [row.masked ?? row.prefix, row.is_current ? 'current' : ''].filter(Boolean).join(' · '),
      value: row.id,
    })),
  });

  if (!choice?.value) {
    return;
  }

  await ctx.run(['account', 'revoke', choice.value]);
}
