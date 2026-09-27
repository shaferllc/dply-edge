import assert from 'node:assert/strict';
import test from 'node:test';
import { mkdtempSync, writeFileSync, mkdirSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

/**
 * config.mjs resolves ~/.dply at import time, so each case gets its own HOME
 * and its own module instance. Nothing here touches the real config.
 */
async function withHome(seed) {
  const home = mkdtempSync(join(tmpdir(), 'dply-home-'));
  mkdirSync(join(home, '.dply'), { recursive: true });
  if (seed !== undefined) {
    writeFileSync(join(home, '.dply', 'config.json'), JSON.stringify(seed, null, 2));
  }
  process.env.HOME = home;

  // Cache-bust so the module re-reads the new HOME.
  const mod = await import(`../src/config.mjs?home=${encodeURIComponent(home)}`);

  return { mod, home, read: () => JSON.parse(readFileSync(join(home, '.dply', 'config.json'), 'utf8')) };
}

test('instanceKey names an instance by its host', async () => {
  const { mod } = await withHome();

  assert.equal(mod.instanceKey('https://dply.test'), 'dply.test');
  assert.equal(mod.instanceKey('https://dply.dev/'), 'dply.dev');
  assert.equal(mod.instanceKey('http://localhost:8000'), 'localhost:8000');
  assert.equal(mod.instanceKey('DPLY.TEST'), 'dply.test');
  assert.equal(mod.instanceKey(''), 'default');
});

test('a config written by an older CLI is adopted, not discarded', async () => {
  // The single-instance shape had no key at all. Upgrading must not log anyone
  // out, so it is filed under its own host and stays active.
  const { mod } = await withHome({ token: 'tok-legacy', baseUrl: 'https://dply.test' });

  const active = await mod.readGlobalConfig();

  assert.equal(active.token, 'tok-legacy');
  assert.equal(active.baseUrl, 'https://dply.test');

  const list = await mod.listInstances();
  assert.deepEqual(list.map((r) => r.key), ['dply.test']);
  assert.equal(list[0].active, true);
});

test('signing in to a second instance keeps the first', async () => {
  const { mod } = await withHome({ token: 'tok-local', baseUrl: 'https://dply.test' });

  await mod.writeGlobalConfig({ token: 'tok-live', baseUrl: 'https://dply.dev' });

  const list = await mod.listInstances();
  assert.deepEqual(list.map((r) => r.key).sort(), ['dply.dev', 'dply.test']);

  // The newly authenticated one becomes active.
  assert.equal((await mod.readGlobalConfig()).token, 'tok-live');
});

test('switching returns to the other credential, not just the other URL', async () => {
  const { mod } = await withHome({ token: 'tok-local', baseUrl: 'https://dply.test' });
  await mod.writeGlobalConfig({ token: 'tok-live', baseUrl: 'https://dply.dev' });

  const back = await mod.useInstance('dply.test');

  assert.equal(back.token, 'tok-local');
  assert.equal((await mod.readGlobalConfig()).token, 'tok-local');
});

test('switching accepts a full URL as well as a host', async () => {
  const { mod } = await withHome({ token: 'tok-local', baseUrl: 'https://dply.test' });
  await mod.writeGlobalConfig({ token: 'tok-live', baseUrl: 'https://dply.dev' });

  assert.equal((await mod.useInstance('https://dply.test/')).token, 'tok-local');
});

test('switching to an unknown instance reports rather than guesses', async () => {
  const { mod } = await withHome({ token: 'tok-local', baseUrl: 'https://dply.test' });

  assert.equal(await mod.useInstance('nope.example'), null);
  // And the active instance is untouched.
  assert.equal((await mod.readGlobalConfig()).token, 'tok-local');
});

test('logging out drops only the instance you are on', async () => {
  const { mod } = await withHome({ token: 'tok-local', baseUrl: 'https://dply.test' });
  await mod.writeGlobalConfig({ token: 'tok-live', baseUrl: 'https://dply.dev' });

  // Active is dply.dev at this point.
  await mod.deleteGlobalConfig();

  const list = await mod.listInstances();
  assert.deepEqual(list.map((r) => r.key), ['dply.test']);
  assert.equal((await mod.readGlobalConfig()).token, 'tok-local');
});

test('forgetting an instance removes it and reassigns active when needed', async () => {
  const { mod } = await withHome({ token: 'tok-local', baseUrl: 'https://dply.test' });
  await mod.writeGlobalConfig({ token: 'tok-live', baseUrl: 'https://dply.dev' });

  assert.equal(await mod.forgetInstance('dply.dev'), true);
  assert.equal(await mod.forgetInstance('dply.dev'), false);
  assert.equal((await mod.readGlobalConfig()).token, 'tok-local');
});

test('a single saved instance is used even with no explicit current', async () => {
  // Nobody with one instance should ever meet this concept.
  const { mod } = await withHome({ current: '', instances: { 'dply.test': { token: 't', baseUrl: 'https://dply.test' } } });

  assert.equal((await mod.readGlobalConfig()).token, 't');
});

test('the stored file keeps the multi-instance shape', async () => {
  const { mod, read } = await withHome();
  await mod.writeGlobalConfig({ token: 'tok', baseUrl: 'https://dply.dev' });

  const raw = read();
  assert.equal(raw.current, 'dply.dev');
  assert.equal(raw.instances['dply.dev'].token, 'tok');
  assert.ok(raw.instances['dply.dev'].savedAt);
});

test('a linked folder deploys with the credential for ITS instance, not the active one', async () => {
  const { mod, home } = await withHome({ token: 'tok-local', baseUrl: 'https://dply.test' });
  await mod.writeGlobalConfig({ token: 'tok-live', baseUrl: 'https://edge.dply.io' });

  // Active is edge.dply.io; the folder is linked to a site on dply.test.
  const repo = join(home, 'repo', '.dply');
  mkdirSync(repo, { recursive: true });
  writeFileSync(join(repo, 'site.json'), JSON.stringify({ siteId: 's1', baseUrl: 'https://dply.test' }));

  const cwd = process.cwd();
  process.chdir(join(home, 'repo'));
  try {
    const ctx = await mod.resolveContext();

    assert.equal(ctx.baseUrl, 'https://dply.test');
    // Sending tok-live here is what produced a bare "Unauthorized".
    assert.equal(ctx.token, 'tok-local');
  } finally {
    process.chdir(cwd);
  }
});

test('a link to an instance you are not signed in to says so, by name', async () => {
  const { mod, home } = await withHome({ token: 'tok-live', baseUrl: 'https://edge.dply.io' });

  const repo = join(home, 'repo', '.dply');
  mkdirSync(repo, { recursive: true });
  writeFileSync(join(repo, 'site.json'), JSON.stringify({ siteId: 's1', baseUrl: 'https://dply.test' }));

  const cwd = process.cwd();
  process.chdir(join(home, 'repo'));
  try {
    await assert.rejects(
      () => mod.resolveContext(),
      (err) => /linked to a site on dply\.test/.test(err.message)
        && /currently on edge\.dply\.io/.test(err.message)
        && /dply use https:\/\/dply\.test/.test(err.message),
    );
  } finally {
    process.chdir(cwd);
  }
});
