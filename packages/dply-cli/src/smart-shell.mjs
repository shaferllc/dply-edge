import { requireClient } from './server-context.mjs';
import { expandArgv } from './shortcuts.mjs';
import { c, info, warn } from './print.mjs';

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {string[]} argv
 */
export async function runSmartShellCommand(rl, run, argv) {
  const expanded = expandArgv(argv);

  try {
    const handled = await maybeSmartPreflight(rl, run, expanded);

    if (!handled) {
      await run(expanded);
    }
  } catch (err) {
    await handleSmartShellError(rl, run, err, expanded);
  }
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {string[]} argv
 * @returns {Promise<boolean>}
 */
async function maybeSmartPreflight(rl, run, argv) {
  const [cmd] = argv;

  if (cmd === 'deploy' && argv.length === 1) {
    const { linkedSiteProduct } = await import('./site-context.mjs');
    const product = await linkedSiteProduct();

    if (!product) {
      warn('No linked site in this repo.');
      info(c.dim('Create the site in the dashboard, then run `dply link` from your project root'));

      return true;
    }
  }

  if (cmd === 'sites') {
    const rows = await fetchEdgeSitesSafe();

    if (rows.length === 0) {
      warn('No Edge sites visible to this token.');
      info(c.dim('Create an Edge site in the web app, or run `edge --help` for deploy commands.'));

      if (await confirm(rl, 'Refresh CLI permissions now?')) {
        await run(['auth', 'refresh']);
      }

      return true;
    }
  }

  return false;
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {(argv: string[]) => Promise<number | void>} run
 * @param {Error & { status?: number, exitCode?: number, message?: string }} err
 * @param {string[]} argv
 */
async function handleSmartShellError(rl, run, err, argv) {
  const message = err?.message ?? String(err);
  warn(message);

  if (err?.exitCode === 2 && /not logged in/i.test(message)) {
    if (await confirm(rl, 'Sign in with browser device flow?')) {
      await run(['login', '--no-shell']);
    }

    return;
  }

  if (err?.status === 403 || /forbidden|ability|scope|permission/i.test(message)) {
    info(c.dim('Try `r` or `auth refresh` to approve more scopes in the browser.'));

    if (await confirm(rl, 'Refresh permissions now?')) {
      await run(['auth', 'refresh']);
    }

    return;
  }

  printCommandHint(argv[0]);
}

/**
 * @param {import('node:readline/promises').Interface} rl
 * @param {string} prompt
 */
async function confirm(rl, prompt) {
  try {
    const answer = (await rl.question(`${c.bold(prompt)} ${c.dim('[y/N]')} `)).trim().toLowerCase();

    return answer === 'y' || answer === 'yes';
  } catch {
    return false;
  }
}

/**
 * @param {string | undefined} command
 */
function printCommandHint(command) {
  if (!command) {
    return;
  }

  /** @type {Record<string, string>} */
  const hints = {
    login: 'login',
    menu: 'menu · or press Enter',
    auth: 'auth refresh · r',
    refresh: 'auth refresh · r',
    r: 'auth refresh',
    sites: 'sites · edge deploy',
    account: 'me · account orgs',
    billing: 'bill · billing breakdown',
    edge: 'edge deploy · edge --help',
  };

  const hint = hints[command];
  if (hint) {
    info(c.dim(`Try: ${hint} · shortcuts: run ls shortcuts`));
  }
}

/**
 * Edge sites visible to this token; errors (no scope, not logged in) read as
 * none, so menus render with a "refresh permissions" nudge instead of throwing.
 *
 * @returns {Promise<Array<Record<string, any>>>}
 */
export async function fetchEdgeSitesSafe() {
  try {
    const client = await requireClient({});

    return (await client.get('/edge/sites'))?.data ?? [];
  } catch {
    return [];
  }
}
