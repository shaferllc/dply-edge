import { deleteGlobalConfig, readGlobalConfig, readSiteLink } from './config.mjs';
import { requireClient } from './server-context.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function accountCommand(args, flags) {
  const sub = args[0];

  if (!sub || sub === '--help' || sub === '-h' || sub === 'help') {
    return printAccountHelp();
  }

  switch (sub) {
    case 'show':
      return accountShow(flags);
    case 'orgs':
    case 'organizations':
      return accountOrganizations(flags);
    case 'sessions':
      return accountSessions(flags);
    case 'refresh':
      return accountRefresh(flags);
    case 'revoke':
      return accountRevoke(args.slice(1), flags);
    case 'logout':
      return accountLogout();
    default:
      throw cliError(`Unknown account command: ${sub}. Run \`dply account help\`.`, 2);
  }
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function accountShow(flags) {
  const client = await requireClient(flags);
  const response = await client.get('/account');
  const data = response?.data ?? {};

  if (flags.json) {
    printJson(data);

    return;
  }

  const user = data.user ?? {};
  const org = data.organization ?? {};
  const token = data.token ?? {};
  const cfg = await readGlobalConfig();

  info(c.bold('Account'));
  printKeyValues([
    ['Name', user.name ?? '—'],
    ['Email', user.email ?? '—'],
    ['User ID', user.id ?? '—'],
  ]);

  info('');
  info(c.bold('Organization'));
  printKeyValues([
    ['Name', org.name ?? '—'],
    ['Role', org.role ?? '—'],
    ['Org ID', org.id ?? '—'],
  ]);

  info('');
  info(c.bold('CLI session'));
  printKeyValues([
    ['Token', token.masked ?? maskToken(cfg?.token)],
    ['Session ID', token.id ?? '—'],
    ['Last used', formatWhen(token.last_used_at)],
    ['Expires', formatWhen(token.expires_at)],
    ['Base URL', cfg?.baseUrl ?? '—'],
  ]);

  const abilities = token.abilities ?? [];
  if (abilities.length > 0) {
    info('');
    info(c.dim(`Abilities (${abilities.length}): ${abilities.join(', ')}`));
  }

  const linked = await readSiteLink();
  if (linked) {
    info('');
    info(c.bold('Linked repository'));
    printKeyValues([
      ['Root', linked.rootDir],
      ['Site ID', linked.link.siteId],
      ['Site name', linked.link.siteName ?? '—'],
    ]);
  }

  return 0;
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function accountOrganizations(flags) {
  const client = await requireClient(flags);
  const rows = (await client.get('/account/organizations'))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No organizations visible to this token.');

    return;
  }

  printTable(
    ['ID', 'Name', 'Role', 'Current'],
    rows.map((row) => [
      row.id,
      row.name,
      row.role ?? '—',
      row.is_current ? 'yes' : '',
    ]),
  );
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function accountRefresh(flags) {
  const { refreshAuth } = await import('./commands.mjs');

  return refreshAuth([], flags);
}

/**
 * @param {Record<string, unknown>} flags
 */
export async function accountSessions(flags) {
  const client = await requireClient(flags);
  const rows = (await client.get('/account/sessions'))?.data ?? [];

  if (flags.json) {
    printJson(rows);

    return;
  }

  if (rows.length === 0) {
    warn('No CLI sessions for this organization.');

    return;
  }

  printTable(
    ['ID', 'User', 'Prefix', 'Last used', 'Current'],
    rows.map((row) => [
      row.id,
      row.user?.email ?? row.user?.name ?? '—',
      row.masked ?? row.prefix ?? '—',
      formatWhen(row.last_used_at),
      row.is_current ? 'yes' : '',
    ]),
  );

  info('');
  info(c.dim('Revoke: dply account revoke <session-id>'));
}

/**
 * @param {string[]} args
 * @param {Record<string, unknown>} flags
 */
export async function accountRevoke(args, flags) {
  const sessionId = args[0];
  if (!sessionId) {
    throw cliError('Usage: dply account revoke <session-id>', 2);
  }

  const client = await requireClient(flags);
  const response = await client.delete(`/account/sessions/${encodeURIComponent(sessionId)}`);

  if (response?.revoked_current) {
    await deleteGlobalConfig();
    ok(response.message ?? 'Current CLI session revoked. Run `dply login` to reconnect.');

    return;
  }

  ok(response?.message ?? 'CLI session revoked.');
}

export async function accountLogout() {
  await deleteGlobalConfig();
  ok('Logged out. Token removed from ~/.dply/config.json.');
}

function printAccountHelp() {
  info(`${c.bold('dply account')} — profile, organizations, and CLI sessions`);
  info('');
  info(`  ${'show'.padEnd(14)} ${c.dim('Signed-in user, org, token, and abilities')}`);
  info(`  ${'orgs'.padEnd(14)} ${c.dim('Organizations this user belongs to')}`);
  info(`  ${'sessions'.padEnd(14)} ${c.dim('Active dply CLI sessions in this org')}`);
  info(`  ${'refresh'.padEnd(14)} ${c.dim('Re-approve scopes for more permissions')}`);
  info(`  ${'revoke'.padEnd(14)} ${c.dim('Revoke a CLI session by ID')}`);
  info(`  ${'logout'.padEnd(14)} ${c.dim('Remove the saved token from this machine')}`);
  info('');
  info(c.dim('Shortcuts: `dply whoami` → account show · `dply refresh` → account refresh · `dply logout` → account logout'));

  return 0;
}

/**
 * @param {string | undefined} token
 */
function maskToken(token) {
  if (!token) {
    return '—';
  }

  return `${token.slice(0, 6)}…${token.slice(-4)}`;
}

/**
 * @param {string | null | undefined} iso
 */
function formatWhen(iso) {
  if (!iso) {
    return '—';
  }

  try {
    return new Date(iso).toLocaleString();
  } catch {
    return iso;
  }
}

/**
 * @param {string} message
 * @param {number} [exitCode]
 */
function cliError(message, exitCode = 1) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}
