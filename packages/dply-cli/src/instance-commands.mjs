/**
 * `dply use` — which dply instance the CLI talks to.
 *
 * This exists because a token is minted by, and valid only for, one instance.
 * Before this, pointing the CLI at a second install meant logging in again and
 * losing the first credential, so moving between a local instance and the
 * hosted one was a round trip every time. Now both are kept and switched
 * between.
 */
import { defaultBaseUrl, forgetInstance, instanceKey, listInstances, readGlobalConfig, useInstance } from './config.mjs';
import { c, info, ok, warn } from './print.mjs';
import { isInteractive, pickRow } from './pick.mjs';

/**
 * The hosted instance, when someone types `dply use live`.
 *
 * edge.dply.io is the public origin: app, docs, API and the install script.
 */
const HOSTED_URL = 'https://edge.dply.io';

const WELL_KNOWN = {
  live: HOSTED_URL,
  cloud: HOSTED_URL,
  prod: HOSTED_URL,
  production: HOSTED_URL,
  hosted: HOSTED_URL,
};

/**
 * @param {string[]} args
 * @param {Record<string, any>} flags
 */
export async function useCommand(args = [], flags = {}) {
  const [target, ...rest] = args;

  if (target === 'list' || flags.list) {
    return listCommand();
  }

  if (target === 'forget' || target === 'rm') {
    return forgetCommand(rest);
  }

  if (! target) {
    return switchInteractively(flags);
  }

  const wellKnown = WELL_KNOWN[String(target).toLowerCase()];
  const wanted = wellKnown ?? String(target);

  const switched = await useInstance(wanted);
  if (switched) {
    return announce(switched);
  }

  // Not saved yet. A URL is something we can sign in to; a bare name we have
  // never seen is a typo, and guessing a hostname from it would be worse.
  const looksLikeUrl = /^https?:\/\//i.test(wanted) || wanted.includes('.');
  if (! looksLikeUrl) {
    const known = (await listInstances()).map((row) => row.key);
    throw fail(
      `No saved instance called "${target}".`
      + (known.length ? `\nSaved: ${known.join(', ')}` : '')
      + '\nPass a URL to sign in to a new one, e.g. `dply use https://edge.dply.io`.',
      2,
    );
  }

  const baseUrl = /^https?:\/\//i.test(wanted) ? wanted.replace(/\/+$/, '') : `https://${wanted}`;

  info(`No saved session for ${c.bold(instanceKey(baseUrl))}.`);

  if (! isInteractive() && ! flags.login) {
    throw fail(`Run \`dply login --base-url ${baseUrl}\` to sign in to it.`, 2);
  }

  info(c.dim('Signing in there now — your other instances stay saved.'));

  const { login } = await import('./commands.mjs');
  await login([], { ...flags, 'base-url': baseUrl, 'no-shell': true });

  const now = await readGlobalConfig();
  if (now) {
    announce(now);
  }
}

async function switchInteractively(flags) {
  const rows = await listInstances();

  if (rows.length === 0) {
    throw fail('Not signed in to any instance yet. Run `dply login`, or `dply use live`.', 2);
  }

  if (! isInteractive()) {
    return listCommand();
  }

  const picked = await pickRow(rows.filter((row) => ! row.active), {
    title: 'Switch to which dply instance?',
    label: (row) => row.key,
    hint: (row) => [row.baseUrl, row.userEmail].filter(Boolean).join(' · '),
  });

  if (! picked) {
    return listCommand();
  }

  const switched = await useInstance(picked.key);
  if (switched) {
    announce(switched);
  }
}

async function listCommand() {
  const rows = await listInstances();

  if (rows.length === 0) {
    info('No saved instances. Run `dply login`.');

    return;
  }

  info('');
  for (const row of rows) {
    const marker = row.active ? c.green('●') : c.dim('○');
    const suffix = [row.baseUrl, row.userEmail].filter(Boolean).join(' · ');
    info(`  ${marker} ${row.active ? c.bold(row.key) : row.key}  ${c.dim(suffix)}`);
  }
  info('');
  info(c.dim('Switch with `dply use <name>` · add one with `dply use <url>`'));
}

async function forgetCommand(args) {
  const target = args[0];
  if (! target) {
    throw fail('Which instance? `dply use forget <name>`', 2);
  }

  const removed = await forgetInstance(String(target));
  if (! removed) {
    throw fail(`No saved instance called "${target}".`, 2);
  }

  ok(`Forgot ${target}.`);
  await listCommand();
}

function announce(cfg) {
  ok(`Now using ${c.bold(instanceKey(cfg.baseUrl))} ${c.dim(`(${cfg.baseUrl})`)}`);

  if (cfg.baseUrl !== defaultBaseUrl()) {
    info(c.dim('  Commands in this shell and every new one use it until you switch again.'));
  }
}

function fail(message, exitCode) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}
