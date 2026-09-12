import assert from 'node:assert/strict';
import test from 'node:test';
import { expandArgv } from '../src/shortcuts.mjs';
import { normalizeShellLine } from '../src/shell.mjs';
import { matchMenuItemByText } from '../src/menus.mjs';

/** @type {import('../src/menus.mjs').MenuItem[]} */
const accountItems = [
  { label: 'Show profile', argv: ['account', 'show'], keywords: ['profile', 'me', 'who', 'whoami', 'show'] },
  { label: 'Organizations', argv: ['account', 'orgs'], keywords: ['orgs', 'organizations'] },
  { label: 'CLI sessions', argv: ['account', 'sessions'], keywords: ['sessions', 'tokens'] },
  { label: 'Refresh permissions', argv: ['auth', 'refresh'], keywords: ['refresh', 'r', 'auth'] },
];

test('expandArgv maps friendly shortcuts', () => {
  assert.deepEqual(expandArgv(['r']), ['refresh']);
  assert.deepEqual(expandArgv(['me']), ['whoami']);
  assert.deepEqual(expandArgv(['orgs']), ['account', 'orgs']);
  assert.deepEqual(expandArgv(['bill']), ['billing', 'show']);
  assert.deepEqual(expandArgv(['sites']), ['sites']);
  assert.deepEqual(expandArgv(['deploy']), ['deploy']);
  assert.deepEqual(expandArgv(['account']), ['account', 'show']);
});

test('expandArgv leaves removed product nouns alone so the router rejects them', () => {
  assert.deepEqual(expandArgv(['servers']), ['servers']);
  assert.deepEqual(expandArgv(['projects']), ['projects']);
});

test('normalizeShellLine strips pasted dply prefix', () => {
  assert.equal(normalizeShellLine('dply auth refresh'), 'auth refresh');
  assert.equal(normalizeShellLine('DPLY sites'), 'sites');
  assert.equal(normalizeShellLine('  dply  '), '');
  assert.equal(normalizeShellLine('sites'), 'sites');
});

test('matchMenuItemByText resolves shortcuts in menus', () => {
  assert.equal(matchMenuItemByText('sessions', accountItems)?.label, 'CLI sessions');
  assert.equal(matchMenuItemByText('me', accountItems)?.label, 'Show profile');
  assert.equal(matchMenuItemByText('r', accountItems)?.label, 'Refresh permissions');
  assert.equal(matchMenuItemByText('orgs', accountItems)?.label, 'Organizations');
});
