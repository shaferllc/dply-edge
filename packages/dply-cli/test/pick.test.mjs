import assert from 'node:assert/strict';
import test from 'node:test';
import { matchRows, pickRow } from '../src/pick.mjs';
import { expandArgv } from '../src/shortcuts.mjs';

const rows = [
  { id: '1', name: 'placeholder' },
  { id: '2', name: 'placeholder-staging' },
  { id: '3', name: 'shop' },
];

test('matchRows prefers an exact name over substring hits', () => {
  assert.deepEqual(matchRows(rows, 'placeholder').map((r) => r.id), ['1']);
  assert.deepEqual(matchRows(rows, 'place').map((r) => r.id), ['1', '2']);
  assert.deepEqual(matchRows(rows, 'SHOP').map((r) => r.id), ['3']);
  assert.deepEqual(matchRows(rows, '  '), []);
});

test('pickRow returns null off a TTY instead of blocking a pipeline', async () => {
  assert.equal(await pickRow(rows, { title: 'Which site?' }), null);
  assert.equal(await pickRow([], { title: 'Which site?' }), null);
});

test('expandArgv treats a colon as a space', () => {
  assert.deepEqual(expandArgv(['edge:status', '--wait']), ['edge', 'status', '--wait']);
  assert.deepEqual(expandArgv(['notifications:channels']), ['notifications', 'channels']);
  assert.deepEqual(expandArgv(['account:orgs']), ['account', 'orgs']);
  assert.deepEqual(expandArgv(['sites']), ['sites']);
});
