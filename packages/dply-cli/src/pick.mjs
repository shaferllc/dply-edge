/**
 * The shared "which one?" prompt.
 *
 * Any site-scoped command that cannot work out its target falls through to
 * this instead of erroring: `dply notifications` becomes list → pick a
 * number → run. Non-TTY (CI, pipes)
 * returns null so the caller throws its usual message and exit code — a
 * prompt that blocks a pipeline would be worse than the error.
 */
import * as readline from 'node:readline/promises';
import { stdin as input, stdout as output } from 'node:process';
import { c, info, warn } from './print.mjs';

export function isInteractive() {
  return Boolean(input.isTTY && output.isTTY);
}

/**
 * @template {Record<string, any>} T
 * @param {T[]} rows
 * @param {{ title: string, label?: (row: T) => string, hint?: (row: T) => string }} options
 * @returns {Promise<T | null>}
 */
export async function pickRow(rows, options) {
  const { title, label = defaultLabel, hint = () => '' } = options;

  if (! isInteractive() || rows.length === 0) {
    return null;
  }

  if (rows.length === 1) {
    info(c.dim(`Using ${label(rows[0])} — the only one visible.`));

    return rows[0];
  }

  info('');
  info(c.bold(title));
  info(c.dim('Pick a number, or type a name · Enter to cancel'));
  info('');

  rows.forEach((row, index) => {
    const suffix = hint(row) ? c.dim(` — ${hint(row)}`) : '';
    info(`  ${c.cyan(String(index + 1).padStart(2, ' '))}  ${label(row)}${suffix}`);
  });

  info('');

  const rl = readline.createInterface({ input, output, terminal: true });

  try {
    const answer = (await rl.question(`${c.bold('Choose')}› `)).trim();

    if (answer === '') {
      return null;
    }

    const index = Number.parseInt(answer, 10);
    if (Number.isFinite(index) && index >= 1 && index <= rows.length) {
      return rows[index - 1];
    }

    const matched = matchRows(rows, answer, label);
    if (matched.length === 1) {
      return matched[0];
    }

    warn(matched.length > 1
      ? `"${answer}" matches ${matched.length} of them — pick a number.`
      : `Enter 1–${rows.length}, or part of a name.`);

    return null;
  } finally {
    rl.close();
  }
}

/**
 * Exact label match wins; otherwise every substring hit.
 *
 * @template {Record<string, any>} T
 * @param {T[]} rows
 * @param {string} needle
 * @param {(row: T) => string} [label]
 * @returns {T[]}
 */
export function matchRows(rows, needle, label = defaultLabel) {
  const wanted = needle.trim().toLowerCase();

  if (! wanted) {
    return [];
  }

  const exact = rows.filter((row) => label(row).toLowerCase() === wanted);

  return exact.length > 0 ? exact : rows.filter((row) => label(row).toLowerCase().includes(wanted));
}

/**
 * @param {Record<string, any>} row
 */
function defaultLabel(row) {
  return String(row?.name ?? row?.id ?? '');
}
