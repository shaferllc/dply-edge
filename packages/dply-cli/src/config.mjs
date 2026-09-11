import { readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { mkdir, readFile, writeFile, stat } from 'node:fs/promises';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const GLOBAL_CONFIG_DIR = join(homedir(), '.dply');
const GLOBAL_CONFIG_PATH = join(GLOBAL_CONFIG_DIR, 'config.json');
const LOCAL_LINK_FILE = '.dply/site.json';
const PUBLIC_CLOUD_URL = 'https://dply.io';

/**
 * Baked in when the CLI is packaged from a dply instance (/cli/dply-cli.tgz).
 * Local monorepo installs ship https://dplyi.test; production tarballs use APP_URL.
 *
 * @returns {string | null}
 */
function bundledInstanceBaseUrl() {
  try {
    const here = dirname(fileURLToPath(import.meta.url));
    const raw = readFileSync(join(here, 'instance-defaults.json'), 'utf8');
    const parsed = JSON.parse(raw);
    if (typeof parsed?.baseUrl === 'string' && parsed.baseUrl.trim() !== '') {
      return parsed.baseUrl.replace(/\/+$/, '');
    }
  } catch {
    // Optional file — npm registry installs may omit it.
  }

  return null;
}

/**
 * @returns {string}
 */
function fallbackBaseUrl() {
  return bundledInstanceBaseUrl() ?? PUBLIC_CLOUD_URL;
}

/**
 * Production default — sync. Prefer {@link resolveLoginBaseUrl} for login.
 *
 * @returns {string}
 */
export function defaultBaseUrl() {
  return (
    process.env.DPLY_API_BASE_URL ??
    process.env.DPLY_BASE_URL ??
    fallbackBaseUrl()
  ).replace(/\/+$/, '');
}

/**
 * Instance URL for `dply login`: flag → env → saved config → bundled → public cloud.
 *
 * @param {Record<string, unknown>} [flags]
 * @returns {Promise<string>}
 */
export async function resolveLoginBaseUrl(flags = {}) {
  const fromFlag = flags['base-url'] || flags.b;
  if (fromFlag) {
    return String(fromFlag).replace(/\/+$/, '');
  }

  const fromEnv = process.env.DPLY_API_BASE_URL || process.env.DPLY_BASE_URL;
  if (fromEnv) {
    return fromEnv.replace(/\/+$/, '');
  }

  const global = await readGlobalConfig();
  if (global?.baseUrl) {
    return global.baseUrl.replace(/\/+$/, '');
  }

  return fallbackBaseUrl();
}

/**
 * @typedef GlobalConfig
 * @property {string} token
 * @property {string} baseUrl
 * @property {string} [organizationId]
 * @property {string} [userEmail]
 * @property {string} [savedAt]
 */

/**
 * @typedef InstanceStore
 * @property {string} current  key of the active instance
 * @property {Record<string, GlobalConfig>} instances
 */

/**
 * A token is minted by, and only valid for, one dply instance — so switching
 * between a local install and the hosted one is not a URL swap, it is a swap of
 * URL *and* credential. The config therefore holds several instances at once
 * and remembers which is active, instead of one set of fields that each login
 * clobbered.
 *
 * Instances are keyed by hostname (`dply.test`, `dply.io`): no invented alias
 * to learn, and `dply use dply.io` reads as what it does.
 *
 * @param {string} baseUrl
 * @returns {string}
 */
export function instanceKey(baseUrl) {
  const trimmed = String(baseUrl || '').replace(/\/+$/, '');

  try {
    return new URL(trimmed).host.toLowerCase();
  } catch {
    return trimmed.replace(/^https?:\/\//i, '').replace(/\/.*$/, '').toLowerCase() || 'default';
  }
}

/**
 * Read the whole store, migrating the single-instance shape written by older
 * CLIs. That shape had no key, so it is filed under its own hostname and
 * becomes the active one — nobody is logged out by upgrading.
 *
 * @returns {Promise<InstanceStore>}
 */
export async function readInstanceStore() {
  let parsed = null;
  try {
    parsed = JSON.parse(await readFile(GLOBAL_CONFIG_PATH, 'utf8'));
  } catch (err) {
    if (err?.code !== 'ENOENT') throw err;
  }

  if (parsed && typeof parsed.instances === 'object' && parsed.instances !== null) {
    return { current: String(parsed.current ?? ''), instances: parsed.instances };
  }

  if (parsed?.token && parsed?.baseUrl) {
    const key = instanceKey(parsed.baseUrl);

    return { current: key, instances: { [key]: parsed } };
  }

  return { current: '', instances: {} };
}

/**
 * @param {InstanceStore} store
 */
export async function writeInstanceStore(store) {
  await mkdir(GLOBAL_CONFIG_DIR, { recursive: true, mode: 0o700 });
  await writeFile(
    GLOBAL_CONFIG_PATH,
    JSON.stringify({ current: store.current, instances: store.instances }, null, 2),
    { mode: 0o600 },
  );
}

/**
 * The active instance, in the flat shape every command already expects — so
 * multi-instance support cost the rest of the CLI no changes at all.
 *
 * @returns {Promise<GlobalConfig | null>}
 */
export async function readGlobalConfig() {
  const store = await readInstanceStore();
  const active = store.instances[store.current];

  if (active) {
    return active;
  }

  // No explicit current (or it points at a removed instance): fall back to the
  // only one there is, so a single-instance user never sees this concept.
  const keys = Object.keys(store.instances);

  return keys.length === 1 ? store.instances[keys[0]] : null;
}

/**
 * Save a credential set and make it active. Keyed by its own base URL, so
 * `dply login --base-url …` adds an instance rather than replacing the one you
 * were already signed in to.
 *
 * @param {GlobalConfig} cfg
 */
export async function writeGlobalConfig(cfg) {
  const store = await readInstanceStore();
  const key = instanceKey(cfg.baseUrl);

  store.instances[key] = { ...cfg, savedAt: new Date().toISOString() };
  store.current = key;

  await writeInstanceStore(store);
}

/**
 * Point subsequent commands at an already-saved instance.
 *
 * @param {string} key
 * @returns {Promise<GlobalConfig | null>} null when nothing is saved for it
 */
export async function useInstance(key) {
  const store = await readInstanceStore();
  const resolved = store.instances[key] ? key : instanceKey(key);

  if (! store.instances[resolved]) {
    return null;
  }

  store.current = resolved;
  await writeInstanceStore(store);

  return store.instances[resolved];
}

/**
 * @returns {Promise<Array<{ key: string, baseUrl: string, active: boolean, userEmail?: string, savedAt?: string }>>}
 */
export async function listInstances() {
  const store = await readInstanceStore();

  return Object.entries(store.instances).map(([key, cfg]) => ({
    key,
    baseUrl: String(cfg.baseUrl ?? ''),
    active: key === store.current,
    userEmail: cfg.userEmail,
    savedAt: cfg.savedAt,
  }));
}

/**
 * @param {string} key
 */
export async function forgetInstance(key) {
  const store = await readInstanceStore();
  const resolved = store.instances[key] ? key : instanceKey(key);

  if (! store.instances[resolved]) {
    return false;
  }

  delete store.instances[resolved];
  if (store.current === resolved) {
    store.current = Object.keys(store.instances)[0] ?? '';
  }

  await writeInstanceStore(store);

  return true;
}

export async function deleteGlobalConfig() {
  // Logging out of the instance you are on should not sign you out of the
  // others you have saved.
  const store = await readInstanceStore();

  if (store.current && store.instances[store.current]) {
    delete store.instances[store.current];
    store.current = Object.keys(store.instances)[0] ?? '';
    await writeInstanceStore(store);

    return;
  }

  await writeInstanceStore({ current: '', instances: {} });
}

/**
 * Walks upward from cwd looking for `.dply/site.json`. Returns the
 * parsed link plus the directory it was found in, or null when this
 * is not a linked repo.
 *
 * @returns {Promise<{ link: { siteId: string, baseUrl?: string, siteName?: string, organizationId?: string, product?: string, kind?: string }, rootDir: string } | null>}
 */
export async function readSiteLink(startDir = process.cwd()) {
  let current = resolve(startDir);

  while (true) {
    const candidate = join(current, LOCAL_LINK_FILE);
    try {
      await stat(candidate);
      const raw = await readFile(candidate, 'utf8');
      const link = JSON.parse(raw);

      return { link, rootDir: current };
    } catch (err) {
      if (err?.code !== 'ENOENT') throw err;
    }

    const parent = dirname(current);
    if (parent === current) return null;
    current = parent;
  }
}

/**
 * @param {{ siteId: string, baseUrl?: string, siteName?: string, organizationId?: string, product?: string, kind?: string }} link
 */
export async function writeSiteLink(link, rootDir = process.cwd()) {
  const path = join(rootDir, LOCAL_LINK_FILE);
  await mkdir(dirname(path), { recursive: true });
  await writeFile(
    path,
    JSON.stringify({ ...link, linkedAt: new Date().toISOString() }, null, 2),
  );

  return path;
}

/**
 * Resolve the (token, base URL, site ID) the current command should use.
 * Site ID resolution order: --site flag > DPLY_EDGE_SITE env > linked
 * repo. Base URL: link wins (so a linked repo is portable across
 * instances), then global config.
 *
 * @param {{ siteFlag?: string }} [opts]
 */
export async function resolveContext(opts = {}) {
  const store = await readInstanceStore();
  const active = await readGlobalConfig();

  let siteId = opts.siteFlag || process.env.DPLY_EDGE_SITE || null;
  let baseUrl = active?.baseUrl || defaultBaseUrl();

  const linkResult = await readSiteLink();
  if (linkResult) {
    siteId ??= linkResult.link.siteId;
    // A linked folder names the instance its site lives on, which is what
    // makes it portable: the same repo can be checked out on a machine
    // pointed somewhere else and still deploy to the right place.
    if (linkResult.link.baseUrl) baseUrl = linkResult.link.baseUrl;
  }

  // …and the credential has to follow the URL. A token is minted by one
  // instance and rejected by every other, so pairing the ACTIVE token with the
  // LINK's base URL — which is what this used to do — sends the wrong
  // credential the moment those two differ, and answers "Unauthorized" without
  // saying why.
  const key = instanceKey(baseUrl);
  const forBaseUrl = store.instances[key];
  const credentials = forBaseUrl ?? (instanceKey(active?.baseUrl ?? '') === key ? active : null);

  if (!credentials?.token) {
    if (!active?.token) {
      throw withCode(new Error('Not logged in. Run `dply login`.'), 'EAUTH', 2);
    }

    throw withCode(
      new Error(
        `This folder is linked to a site on ${key}, but you are not signed in to it.\n`
        + `You are currently on ${instanceKey(active.baseUrl)}.\n`
        + `Sign in with \`dply use ${baseUrl}\`, or re-link this folder with \`dply link\`.`,
      ),
      'EAUTH',
      2,
    );
  }

  return {
    token: credentials.token,
    baseUrl,
    siteId,
    link: linkResult,
    global: credentials,
  };
}

function withCode(err, code, exitCode) {
  err.code = code;
  err.exitCode = exitCode;

  return err;
}
