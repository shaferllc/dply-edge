import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { readGlobalConfig } from './config.mjs';
import { completeCommandLine } from './cli.mjs';
import { runMenuSession } from './menus.mjs';
import { expandArgv } from './shortcuts.mjs';
import { runSmartShellCommand } from './smart-shell.mjs';
import { c, info, ok, warn } from './print.mjs';

/**
 * Interactive REPL — default when you run bare `dply` on a TTY.
 */
export async function enterInteractiveShell() {
  if (!input.isTTY || !output.isTTY) {
    return;
  }

  const { run } = await import('./cli.mjs');
  const rl = readline.createInterface({
    input,
    output,
    terminal: true,
    completer: completeCommandLine,
  });

  await printShellGuide();

  try {
    // eslint-disable-next-line no-constant-condition
    while (true) {
      let line;
      try {
        line = normalizeShellLine((await rl.question(`${c.bold('dply')}› `)).trim());
      } catch {
        break;
      }

      if (line === '') {
        await runMenuSession(rl, run, 'root');
        continue;
      }

      if (line === 'menu' || line === 'm') {
        await runMenuSession(rl, run, 'root');
        continue;
      }

      if (line === 'exit' || line === 'quit' || line === 'q') {
        info(c.dim('Bye.'));
        break;
      }

      if (line === 'guide') {
        await printShellGuide();
        continue;
      }

      if (line === 'help' || line === '?') {
        await run(['help']);
        continue;
      }

      if (line === 'ls') {
        await run(['ls']);
        continue;
      }

      if (line.startsWith('help ')) {
        const topic = line.slice(5).trim().toLowerCase();
        if (topic === 'account') {
          await run(['account', 'help']);
        } else if (topic === 'billing') {
          await run(['billing', 'help']);
        } else if (topic === 'edge') {
          await run(['edge', '--help']);
        } else if (topic === 'notifications') {
          await run(['notifications', 'help']);
        } else if (topic === 'shortcuts') {
          await run(['ls', 'shortcuts']);
        } else {
          warn(`No help topic "${topic}". Try: help account · help edge · help shortcuts`);
        }

        continue;
      }

      const tokens = expandArgv(tokenizeLine(line));
      if (tokens.length === 0) {
        continue;
      }

      await runSmartShellCommand(rl, run, tokens);
    }
  } finally {
    rl.close();
  }
}

export async function printShellGuide() {
  const cfg = await readGlobalConfig();

  info('');
  info(`${c.bold('dply')} ${c.dim('interactive CLI')}`);
  info(c.dim('  Enter / menu   browse actions — type numbers or commands in menus'));
  info(c.dim('  Tab            complete commands'));
  info(c.dim('  ls             command index'));
  info(c.dim('  help           detailed help · help account/edge/notifications'));
  info(c.dim('  guide          show this screen again'));
  info(c.dim('  exit           leave interactive mode'));
  info(c.dim('  Paste `dply …` commands — the leading `dply` is ignored here'));
  info(c.dim('  Shortcuts: sites · deploy · me · r · ls shortcuts'));
  info('');

  if (!cfg?.token) {
    warn('Not signed in on this machine.');
    info('');
    info(c.bold('Start here'));
    info(`  ${c.cyan('Press Enter')}     ${c.dim('open the menu → Sign in')}`);
    info(`  ${c.cyan('login')}           ${c.dim('browser device-flow sign-in')}`);
    info(`  ${c.cyan('login --base-url URL')}  ${c.dim('if auto-detect picks the wrong host')}`);
  } else {
    ok(`Signed in · ${cfg.baseUrl}`);
    info('');
    info(c.bold('Browse or type'));
    info(`  ${c.cyan('menu')} / ${c.cyan('Enter')}   ${c.dim('numbered menus — no memorization')}`);
    info(c.bold('Shortcuts'));
    info(`  ${c.cyan('sites')}              ${c.dim('Edge sites')}`);
    info(`  ${c.cyan('link')}               ${c.dim('link this folder to a site')}`);
    info(`  ${c.cyan('deploy')}             ${c.dim('deploy the linked site')}`);
    info(`  ${c.cyan('me')} / ${c.cyan('who')}        ${c.dim('account profile')}`);
    info(`  ${c.cyan('r')}                 ${c.dim('refresh CLI permissions')}`);
    info('');
    info(c.dim('Type the start of a command and press Tab to complete.'));
  }

  info('');
}

/**
 * Inside the interactive shell the prompt is already `dply›`, so pasted
 * full invocations like `dply auth refresh` should work without retyping.
 *
 * @param {string} line
 * @returns {string}
 */
export function normalizeShellLine(line) {
  const trimmed = line.trim();
  if (trimmed === '' || trimmed.toLowerCase() === 'dply') {
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
function tokenizeLine(line) {
  const tokens = [];
  const re = /"([^"\\]*(?:\\.[^"\\]*)*)"|'([^'\\]*(?:\\.[^'\\]*)*)'|(\S+)/g;
  let match;
  while ((match = re.exec(line)) !== null) {
    tokens.push(match[1] ?? match[2] ?? match[3]);
  }

  return tokens;
}
