import * as commands from './commands.mjs';
import * as billingCommands from './billing-commands.mjs';
import * as dataCommands from './data-commands.mjs';
import * as accountCommands from './account-commands.mjs';
import * as notificationsCommands from './notifications-commands.mjs';
import * as updateCommands from './update-command.mjs';
import * as instanceCommands from './instance-commands.mjs';
import { expandArgv, shortcutCommandLines } from './shortcuts.mjs';
import { linkedSiteProduct } from './site-context.mjs';
import { c, info } from './print.mjs';

const TOP_LEVEL = {
  login: { handler: commands.login, summary: 'Browser login, then drop into interactive shell.' },
  refresh: { handler: commands.refreshAuth, summary: 'Re-approve CLI scopes for more permissions (device flow).' },
  auth: { handler: runAuth, summary: 'CLI authentication (refresh scopes).' },
  logout: { handler: accountCommands.accountLogout, summary: 'Remove the saved token (alias for account logout).' },
  menu: { handler: commands.menu, summary: 'Browse commands with numbered menus (no memorization required).' },
  shell: { handler: commands.shell, summary: 'Interactive mode (same as bare `dply` on a TTY).' },
  whoami: { handler: commands.whoami, summary: 'Show account + session (alias for account show).' },
  account: { handler: runAccount, summary: 'Profile, orgs, CLI sessions (show, orgs, sessions, revoke).' },
  billing: { handler: runBilling, summary: 'Plan estimate, breakdown, invoices (org admin).' },
  db: { handler: runDb, summary: 'D1 databases: list | query <database> "<sql>".' },
  queues: { handler: runQueues, summary: "Queues: list | send <queue> '<json>'." },
  use: { handler: instanceCommands.useCommand, summary: 'Switch which dply instance the CLI talks to (list, <name>, <url>, forget).' },
  sites: { handler: commands.sites, summary: 'List your Edge sites (name filter).' },
  link: { handler: commands.link, summary: 'Link this folder to an existing Edge site (.dply/site.json).' },
  deploy: { handler: runLinkedDeploy, summary: 'Deploy the linked Edge site (or --site <id>).' },
  notifications: { handler: notificationsCommands.notificationsCommand, summary: 'Channels + event routing for a site.' },
  notify: { handler: notificationsCommands.notificationsCommand, summary: 'Alias for `notifications`.' },
  update: { handler: updateCommands.updateCommand, summary: 'Install the CLI build your instance is serving (--check).' },
};

const EDGE_COMMANDS = {
  deploy: { handler: commands.deploy, summary: 'Queue a deploy (--commit / --branch / --prod).' },
  deployments: { handler: commands.deployments, summary: 'List recent deployments.' },
  status: { handler: commands.edgeStatus, summary: 'Edge site + latest deployment (--wait to block).' },
  lint: { handler: commands.lint, summary: 'Validate dply.yaml in cwd (--path).' },
  open: { handler: commands.open, summary: 'Open live URL (--dashboard for workspace).' },
  rollback: { handler: commands.rollback, summary: 'Re-point production at a prior deployment.' },
  promote: { handler: commands.promote, summary: 'Promote a preview to production.' },
  previews: { handler: commands.previews, summary: 'list | create [--commit|--branch] [--wait] | rm <id>' },
  domains: { handler: commands.domains, summary: 'list | add <host> | verify <host> | rm <host>' },
  aliases: { handler: commands.aliases, summary: 'List per-deploy stable URLs.' },
  purge: { handler: commands.purge, summary: 'Purge edge cache by tag (--tag X).' },
  usage: { handler: commands.usage, summary: 'Show traffic / billing usage.' },
  logs: { handler: commands.logs, summary: 'Tail request logs (--interval ms, --window s, --once).' },
  env: { handler: commands.env, summary: 'list | set KEY=val | rm KEY | push --file .env | pull' },
};

const ACCOUNT_SUBCOMMANDS = ['show', 'orgs', 'sessions', 'refresh', 'revoke', 'logout', 'help'];
const BILLING_LINES = ['billing show', 'billing breakdown', 'billing invoices', 'billing help'];

/**
 * @param {string[]} argv
 * @returns {Promise<number | void>}
 */
export async function run(argv) {
  argv = expandArgv(argv);

  if (argv.length === 0) {
    if (process.stdin.isTTY && process.stdout.isTTY) {
      const { enterInteractiveShell } = await import('./shell.mjs');

      return enterInteractiveShell();
    }

    return printTopLevelHelp();
  }

  if (argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
    return printTopLevelHelp();
  }
  if (argv[0] === 'ls') {
    return printCommandList(argv[1]);
  }
  if (argv[0] === '--version' || argv[0] === '-V') {
    // Read, not hardcoded: `dply update` compares this against what the
    // instance serves, so a stale literal here would report a false match.
    info(`dply CLI ${await updateCommands.localVersion()}`);

    return 0;
  }

  const [command, ...rest] = argv;

  if (command === 'edge') {
    return runEdge(rest);
  }

  if (command === 'auth' || command === 'account' || command === 'billing') {
    return TOP_LEVEL[command].handler(rest);
  }

  const entry = TOP_LEVEL[command];
  if (!entry) {
    throw unknown(command);
  }

  const { args, flags } = parse(rest);

  return entry.handler(args, flags);
}

async function runAuth(argv) {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h' || argv[0] === 'help') {
    info(`${c.bold('dply auth')} — CLI authentication`);
    info('');
    info(`  ${'refresh'.padEnd(12)} ${c.dim('Re-approve scopes in the browser for more permissions')}`);
    info('');
    info(c.dim('Shortcut: `dply refresh` · `dply account refresh`'));

    return 0;
  }

  const [sub, ...rest] = argv;

  if (sub === 'refresh') {
    const { args, flags } = parse(rest);

    return commands.refreshAuth(args, flags);
  }

  throw unknown(`auth ${sub}`);
}

/**
 * Top-level `dply deploy`: the linked Edge site, or the one --site /
 * $DPLY_EDGE_SITE names. There is no create-site API, so an unlinked folder
 * is pointed at the dashboard rather than set up here.
 */
async function runLinkedDeploy(args, flags) {
  if (flags.site || process.env.DPLY_EDGE_SITE) {
    return commands.deploy(args, flags);
  }

  const product = await linkedSiteProduct();

  if (product === 'edge') {
    return commands.deploy(args, flags);
  }

  const err = new Error(
    product
      ? `This folder is linked to a ${product} site, which dply no longer hosts. Re-link it to an Edge site with \`dply link\`.`
      : 'This folder is not linked to a site. Create the site in the dashboard, then `dply link` (or pass --site <id>).',
  );
  err.exitCode = 2;

  throw err;
}

async function runAccount(argv) {
  const { args, flags } = parse(argv);

  return accountCommands.accountCommand(args.length ? args : ['show'], flags);
}

async function runDb(argv) {
  const { args, flags } = parse(argv);

  return dataCommands.dbCommand(args, flags);
}

async function runQueues(argv) {
  const { args, flags } = parse(argv);

  return dataCommands.queuesCommand(args, flags);
}

async function runBilling(argv) {
  const { args, flags } = parse(argv);

  return billingCommands.billingCommand(args.length ? args : ['help'], flags);
}

async function runEdge(argv) {
  if (argv.length === 0 || argv[0] === '--help' || argv[0] === '-h') {
    return printEdgeHelp();
  }
  const [sub, ...rest] = argv;
  const entry = EDGE_COMMANDS[sub];
  if (!entry) {
    throw unknown(`edge ${sub}`);
  }
  const { args, flags } = parse(rest);

  return entry.handler(args, flags);
}

/**
 * Tiny argv parser. Supports:
 *   --flag value          → flags.flag = value
 *   --flag=value          → flags.flag = value
 *   --flag                → flags.flag = true
 *   -x value              → flags.x = value
 *   non-dash token        → args.push(token)
 *
 * No deps, no surprises. If you need richer parsing later, swap in
 * mri or minimist — the shape (args[], flags{}) stays the same.
 *
 * @param {string[]} tokens
 */
export function parse(tokens) {
  const args = [];
  const flags = {};

  // Repeated flags collect into an array — `--header 'A: 1' --header 'B: 2'`
  // and `--param K=v --param J=w` are documented as repeatable, and last-wins
  // dropped every value but the last without saying so.
  const put = (name, value) => {
    if (! Object.hasOwn(flags, name)) {
      flags[name] = value;

      return;
    }

    flags[name] = Array.isArray(flags[name]) ? [...flags[name], value] : [flags[name], value];
  };

  for (let i = 0; i < tokens.length; i++) {
    const token = tokens[i];
    if (token.startsWith('--')) {
      const eq = token.indexOf('=');
      if (eq !== -1) {
        put(token.slice(2, eq), token.slice(eq + 1));

        continue;
      }
      const name = token.slice(2);
      const next = tokens[i + 1];
      if (next !== undefined && !next.startsWith('-')) {
        put(name, next);
        i++;
      } else {
        put(name, true);
      }

      continue;
    }
    if (token.startsWith('-') && token.length > 1) {
      const name = token.slice(1);
      const next = tokens[i + 1];
      if (next !== undefined && !next.startsWith('-')) {
        put(name, next);
        i++;
      } else {
        put(name, true);
      }

      continue;
    }
    args.push(token);
  }

  return { args, flags };
}

function printTopLevelHelp() {
  info(`${c.bold('dply')} — command-line interface for the dply Edge platform`);
  info('');
  info(c.bold('Usage:'));
  info('  dply <command> [args] [flags]');
  info('  dply edge <subcommand> [args] [flags]');
  info('');
  info(c.bold('Top-level:'));
  for (const [name, { summary }] of Object.entries(TOP_LEVEL)) {
    info(`  ${name.padEnd(14)} ${c.dim(summary)}`);
  }
  info('');
  info(c.bold('Account:'));
  info(`  ${'account show'.padEnd(18)} ${c.dim('Profile + org + CLI session')}`);
  info(`  ${'account orgs'.padEnd(18)} ${c.dim('List organizations')}`);
  info(`  ${'account sessions'.padEnd(18)} ${c.dim('List CLI sessions · revoke with account revoke')}`);
  info(`  ${'auth refresh'.padEnd(18)} ${c.dim('Re-approve scopes for more permissions')}`);
  info('');
  info(c.bold('Billing (org admin):'));
  info(`  ${'billing show'.padEnd(18)} ${c.dim('Plan + monthly estimate')}`);
  info(`  ${'billing breakdown'.padEnd(18)} ${c.dim('Line-item estimate')}`);
  info(`  ${'billing invoices'.padEnd(18)} ${c.dim('Stripe invoice history')}`);
  info('');
  info(c.bold('Data:'));
  info(`  ${'db list'.padEnd(18)} ${c.dim('D1 databases')}`);
  info(`  ${'db query <db> "sql"'.padEnd(18)} ${c.dim('Run SQL against a database')}`);
  info(`  ${'queues list'.padEnd(18)} ${c.dim('Cloudflare Queues')}`);
  info(`  ${"queues send <q> '{}'".padEnd(18)} ${c.dim('Send a JSON message')}`);
  info('');
  info(c.bold('Sites:'));
  info(`  ${'sites [name]'.padEnd(18)} ${c.dim('Edge sites this token can see')}`);
  info(`  ${'link [id]'.padEnd(18)} ${c.dim('Link this folder to a site (picker when omitted)')}`);
  info(`  ${'deploy'.padEnd(18)} ${c.dim('Deploy the linked site (--wait to block until live)')}`);
  info(`  ${'notifications'.padEnd(18)} ${c.dim('Channels, the event catalog, and what routes where')}`);
  info('');
  info(c.bold('Edge:'));
  for (const [name, { summary }] of Object.entries(EDGE_COMMANDS)) {
    info(`  edge ${name.padEnd(12)} ${c.dim(summary)}`);
  }
  info('');
  info(c.dim('New site? Create it in the dashboard, then `dply link` in your repo.'));
  info(c.dim('Site context: `--site <id>` · $DPLY_EDGE_SITE · `dply link`'));
  info(c.dim('Shortcuts: sites · deploy · me · r · `dply ls shortcuts`'));
  info(c.dim('Colon form: any `dply a b` also works as `dply a:b` — e.g. `dply edge:status`'));
  info(c.dim('Interactive mode: run `dply` with no args · `dply menu` · `dply ls` · `dply help`'));

  return 0;
}

/**
 * All invokable command lines (for tab completion).
 *
 * @returns {string[]}
 */
export function allCommandLines() {
  /** @type {string[]} */
  const lines = ['ls', 'help', 'guide', 'edge', ...Object.keys(TOP_LEVEL)];

  for (const sub of ACCOUNT_SUBCOMMANDS) {
    lines.push(`account ${sub}`);
  }

  lines.push(...BILLING_LINES);
  lines.push('auth refresh', 'auth help');

  for (const name of notificationsCommands.NOTIFICATIONS_SUBCOMMANDS) {
    lines.push(`notifications ${name}`);
  }

  for (const name of Object.keys(EDGE_COMMANDS)) {
    lines.push(`edge ${name}`);
  }

  lines.push(...shortcutCommandLines());

  // Every two-word route also answers to `ns:sub`, so completion should offer it.
  for (const line of [...lines]) {
    const parts = line.split(' ');
    if (parts.length === 2 && ! parts[1].startsWith('-')) {
      lines.push(parts.join(':'));
    }
  }

  return [...new Set(lines)];
}

/**
 * readline tab completer.
 *
 * @param {string} line
 * @returns {[string[], string]}
 */
export function completeCommandLine(line) {
  const prefix = line.trimStart();
  const all = allCommandLines();
  const matches = all.filter((cmd) => cmd.startsWith(prefix));

  return [matches.length > 0 ? matches : all, prefix];
}

const LIST_SCOPES = ['top', 'account', 'billing', 'edge', 'notifications', 'shortcuts'];

/**
 * Compact command index (like `ls`).
 *
 * @param {string | undefined} scope  one of LIST_SCOPES
 */
function printCommandList(scope) {
  const normalized = scope?.toLowerCase();

  if (normalized && !LIST_SCOPES.includes(normalized)) {
    throw unknown(`ls ${scope}`);
  }

  const want = (name) => !normalized || normalized === name;
  /** @type {string[]} */
  const lines = [];

  if (want('top')) {
    lines.push('ls', 'help', 'guide', ...Object.keys(TOP_LEVEL), 'edge');
  }

  if (want('account')) {
    lines.push(...ACCOUNT_SUBCOMMANDS.map((sub) => `account ${sub}`));
  }

  if (want('billing')) {
    lines.push(...BILLING_LINES);
  }

  if (want('edge')) {
    lines.push(...Object.keys(EDGE_COMMANDS).map((name) => `edge ${name}`));
  }

  if (want('notifications')) {
    lines.push(...notificationsCommands.NOTIFICATIONS_SUBCOMMANDS.map((name) => `notifications ${name}`));
  }

  if (want('shortcuts')) {
    lines.push(...shortcutCommandLines());
  }

  info(c.bold('dply commands'));
  info('');
  for (const line of lines) {
    info(`  ${line}`);
  }
  info('');
  info(c.dim(`Scoped: ${LIST_SCOPES.map((s) => `dply ls ${s}`).join(' · ')}`));
  info(c.dim('Details: dply help'));

  return 0;
}

function printEdgeHelp() {
  info(`${c.bold('dply edge')} — Edge platform subcommands`);
  info('');
  for (const [name, { summary }] of Object.entries(EDGE_COMMANDS)) {
    info(`  ${name.padEnd(12)} ${c.dim(summary)}`);
  }

  return 0;
}

function unknown(command) {
  const err = new Error(`Unknown command: ${command}. Run \`dply ls\` or \`dply help\`.`);
  err.exitCode = 2;

  return err;
}
