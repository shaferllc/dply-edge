import { ApiClient } from './api.mjs';
import { execFile } from 'node:child_process';
import { access, readFile } from 'node:fs/promises';
import { promisify } from 'node:util';
import {
  resolveLoginBaseUrl,
  defaultBaseUrl,
  deleteGlobalConfig,
  readGlobalConfig,
  readSiteLink,
  resolveContext,
  writeGlobalConfig,
  writeSiteLink,
} from './config.mjs';
import { c, info, ok, printJson, printKeyValues, printTable, warn } from './print.mjs';
import {
  deployFollowIntervalMs,
  deployFollowRequested,
  followEdgeDeployment,
  TERMINAL_EDGE_DEPLOY_STATUSES,
} from './deploy-follow.mjs';

const execFileAsync = promisify(execFile);
const CONFIG_CANDIDATES = ['dply.yaml', 'dply.yml', 'dply.json'];

/**
 * Saves a token + base URL to ~/.dply/config.json.
 *
 * Two flows:
 *
 * 1. Device flow (default — no flags). Calls POST /auth/device/start,
 *    prints the short user_code, opens the verification URL in the
 *    user's default browser, and polls /auth/device/poll until the
 *    user approves on the web. Matches the GitHub CLI / Vercel CLI /
 *    Stripe CLI UX.
 *
 * 2. Token paste (--token <plaintext>). Still supported for CI and
 *    headless setups where opening a browser isn't possible.
 *
 * Flags:
 *   --token    API token plaintext (skip the browser-approval flow)
 *   --base-url Instance URL (defaults to env DPLY_API_BASE_URL or the public cloud)
 *   --no-open  Don't try to open the browser, just print the URL
 */
export async function login(args, flags) {
  const baseUrl = await resolveLoginBaseUrl(flags);
  const explicitToken = flags.token || flags.t;
  const enterShell = flags['no-shell'] !== true;

  if (explicitToken) {
    await loginWithToken({ baseUrl, token: explicitToken, enterShell });

    return;
  }

  await loginWithDeviceFlow({ baseUrl, openBrowser: flags['no-open'] !== true, enterShell, mode: 'login' });
}

/**
 * Re-run device-flow approval to mint a new CLI token with updated scopes.
 * Keeps the same base URL unless --base-url is passed. Revokes the previous
 * CLI session when the new token has account.write (use --keep-old to skip).
 *
 * @param {string[]} _args
 * @param {Record<string, unknown>} flags
 */
export async function refreshAuth(_args, flags) {
  const cfg = await readGlobalConfig();
  if (!cfg?.token) {
    throw fail('Not logged in. Run `dply login` first.', 2);
  }

  const baseUrl = await resolveLoginBaseUrl(flags);
  const keepOld = flags['keep-old'] === true;

  /** @type {string[]} */
  let oldAbilities = [];
  let oldSessionId = null;

  try {
    const client = new ApiClient({ baseUrl, token: cfg.token });
    const account = (await client.get('/account'))?.data ?? {};
    oldAbilities = account.token?.abilities ?? [];
    oldSessionId = account.token?.id ?? null;
  } catch {
    warn('Could not read the current session — continuing with refresh.');
  }

  info('');
  info(c.bold('Refresh CLI permissions'));
  info(c.dim('Approve scopes in your browser. This replaces the token saved on this machine.'));
  if (oldAbilities.length > 0) {
    info('');
    info(c.dim(`Current scopes (${oldAbilities.length}): ${oldAbilities.join(', ')}`));
  }

  await loginWithDeviceFlow({
    baseUrl,
    openBrowser: flags['no-open'] !== true,
    enterShell: false,
    mode: 'refresh',
  });

  const newCfg = await readGlobalConfig();
  if (!newCfg?.token) {
    return;
  }

  /** @type {string[]} */
  let newAbilities = [];
  try {
    const client = new ApiClient({ baseUrl, token: newCfg.token });
    newAbilities = (await client.get('/account'))?.data?.token?.abilities ?? [];
  } catch {
    // non-fatal
  }

  printAbilityDiff(oldAbilities, newAbilities);

  if (!keepOld && oldSessionId) {
    await revokeSupersededSession({ baseUrl, newToken: newCfg.token, oldSessionId });
  } else if (keepOld && oldSessionId) {
    info(c.dim('Previous CLI session kept (--keep-old). Revoke stale sessions from Profile → CLI.'));
  }

  ok('CLI permissions refreshed.');
}

async function loginWithToken({ baseUrl, token, enterShell = true }) {
  const probe = new ApiClient({ baseUrl, token });

  try {
    await probe.get('/edge/sites');
  } catch (err) {
    throw fail(`Token verification failed: ${err.message}`, err.status ?? 1);
  }

  await writeGlobalConfig({ token, baseUrl });
  await finalizeLogin({ baseUrl, enterShell });
}

/**
 * @param {{ baseUrl: string, openBrowser?: boolean, enterShell?: boolean, mode?: 'login' | 'refresh' }} opts
 */
async function loginWithDeviceFlow({ baseUrl, openBrowser, enterShell = true, mode = 'login' }) {
  // /auth/device/start is unauthenticated — pass an empty bearer.
  const anonymous = new ApiClient({ baseUrl, token: '' });
  let started;
  try {
    started = await anonymous.post('/auth/device/start', {});
  } catch (err) {
    const hint =
      baseUrl === defaultBaseUrl()
        ? ' Check APP_URL / reinstall the CLI from your instance if this host is wrong.'
        : ' Check the URL is reachable and APP_URL matches your browser host (local .test HTTPS needs Dply Local CA at ~/.dpl/certs/ca.pem).';
    throw fail(`Could not start device login at ${baseUrl}: ${err.message}.${hint}`, err.status ?? 1);
  }

  const {
    device_code,
    user_code,
    verification_uri,
    verification_uri_complete,
    expires_in,
    interval,
  } = started ?? {};

  if (!device_code || !user_code) {
    throw fail('Device-login response was missing device_code / user_code.', 1);
  }

  const pollIntervalMs = Math.max(1000, Number.parseInt(interval ?? '2', 10) * 1000);
  const expiresAt = Date.now() + Math.max(60, Number.parseInt(expires_in ?? '900', 10)) * 1000;

  info('');
  if (mode === 'refresh') {
    info(`${c.bold('Open this URL to approve updated CLI scopes:')}`);
  } else {
    info(`${c.bold('Open this URL to approve the CLI:')}`);
  }
  info(`  ${c.cyan(verification_uri_complete || verification_uri)}`);
  info('');
  info(`${c.bold('Confirm the code shown matches:')}`);
  info(`  ${c.bold(c.cyan(user_code))}`);
  info('');
  info(c.dim(`Waiting for approval… (expires in ${Math.round((expiresAt - Date.now()) / 1000)}s)`));

  if (openBrowser) {
    try {
      await openInBrowser(verification_uri_complete || verification_uri);
    } catch {
      // If the browser fails to open we still have the printed URL —
      // don't fail the login flow over a display-only convenience.
    }
  }

  // Best-effort cleanup: if the user hits ^C, ask the server to drop
  // the row instead of waiting for the 15-minute TTL.
  const onSigint = () => {
    info('');
    warn(mode === 'refresh'
      ? 'Refresh cancelled. Re-run `dply auth refresh` to try again.'
      : 'Login cancelled. Re-run `dply login` to start over.');
    process.exit(2);
  };
  process.on('SIGINT', onSigint);

  try {
    while (Date.now() < expiresAt) {
      let response;
      try {
        response = await anonymous.post('/auth/device/poll', { device_code });
      } catch (err) {
        // 429 / transient network — back off one interval and retry.
        warn(`Poll error: ${err.message} — retrying`);
        await sleep(pollIntervalMs);
        continue;
      }

      const status = response?.status ?? 'pending';
      if (status === 'authorized') {
        const token = response.token;
        if (!token) {
          throw fail('Server marked the code authorized but returned no token.', 1);
        }
        await writeGlobalConfig({ token, baseUrl });
        if (mode === 'refresh') {
          ok(`New CLI token saved for ${c.cyan(baseUrl)}.`);

          return;
        }
        await finalizeLogin({ baseUrl, enterShell });

        return;
      }
      if (status === 'denied') {
        throw fail(
          mode === 'refresh'
            ? 'Refresh denied in the browser. Re-run `dply auth refresh` to try again.'
            : 'Login denied in the browser. Re-run `dply login` to try again.',
          2,
        );
      }
      if (status === 'expired') {
        throw fail(
          mode === 'refresh'
            ? 'Refresh code expired. Re-run `dply auth refresh` to start over.'
            : 'Login code expired. Re-run `dply login` to start over.',
          2,
        );
      }

      await sleep(pollIntervalMs);
    }

    throw fail(
      mode === 'refresh'
        ? 'Refresh timed out before approval. Re-run `dply auth refresh` to start over.'
        : 'Login timed out before approval. Re-run `dply login` to start over.',
      2,
    );
  } finally {
    process.off('SIGINT', onSigint);
  }
}

/**
 * @param {string[]} before
 * @param {string[]} after
 */
function printAbilityDiff(before, after) {
  const added = after.filter((ability) => !before.includes(ability));
  const removed = before.filter((ability) => !after.includes(ability));

  if (added.length === 0 && removed.length === 0) {
    info('');
    info(c.dim('Scopes unchanged — pick additional permissions on the approval page next time.'));

    return;
  }

  info('');
  if (added.length > 0) {
    ok(`Added (${added.length}): ${added.join(', ')}`);
  }
  if (removed.length > 0) {
    warn(`Removed (${removed.length}): ${removed.join(', ')}`);
  }
}

/**
 * @param {{ baseUrl: string, newToken: string, oldSessionId: string }} opts
 */
async function revokeSupersededSession({ baseUrl, newToken, oldSessionId }) {
  const client = new ApiClient({ baseUrl, token: newToken });

  try {
    const response = await client.delete(`/account/sessions/${encodeURIComponent(oldSessionId)}`);
    if (response?.revoked_current) {
      info(c.dim('Previous session was already replaced.'));

      return;
    }
    info(c.dim('Previous CLI session revoked.'));
  } catch (err) {
    warn(`Could not revoke the previous CLI session: ${err.message}`);
    info(c.dim('Remove stale sessions from Profile → CLI if needed.'));
  }
}

/**
 * @param {{ baseUrl: string, enterShell?: boolean }} opts
 */
async function finalizeLogin({ baseUrl, enterShell = true }) {
  const cfg = await readGlobalConfig();
  ok(`Logged in to ${c.cyan(baseUrl)}.`);

  if (cfg?.token) {
    await printLoginSummary(baseUrl, cfg.token);
  }

  if (enterShell) {
    const { enterInteractiveShell } = await import('./shell.mjs');
    await enterInteractiveShell();
  }
}

/**
 * @param {string} baseUrl
 * @param {string} token
 */
async function printLoginSummary(baseUrl, token) {
  const client = new ApiClient({ baseUrl, token });

  info('');

  try {
    const sites = (await client.get('/edge/sites'))?.data ?? [];
    info(`${c.bold(String(sites.length))} edge site(s) visible`);
  } catch {
    // token may not include edge.read
  }

  info('');
  info(c.dim('Try: sites · link · deploy · account show'));
}

export async function shell() {
  const { enterInteractiveShell } = await import('./shell.mjs');
  await enterInteractiveShell();
}

export async function menu() {
  const { enterInteractiveMenu } = await import('./menus.mjs');
  await enterInteractiveMenu();
}

export async function whoami() {
  const cfg = await readGlobalConfig();
  if (!cfg?.token) {
    info(c.dim('Not logged in. Run `dply login`.'));

    return 1;
  }

  try {
    const { accountShow } = await import('./account-commands.mjs');

    return accountShow({});
  } catch (err) {
    if (err?.status !== 403 && err?.status !== 401) {
      throw err;
    }

    warn('Could not load account from API — showing local config only.');
    printKeyValues([
      ['Base URL', cfg.baseUrl],
      ['Token', `${cfg.token.slice(0, 6)}…${cfg.token.slice(-4)}`],
      ['Saved at', cfg.savedAt ?? '—'],
    ]);

    return 0;
  }
}

/** @deprecated Use accountLogout via `dply logout` / `dply account logout`. */
export async function logout() {
  const { accountLogout } = await import('./account-commands.mjs');

  return accountLogout();
}

/**
 * `dply link [<site-id>]` — write .dply/site.json so future commands
 * (including bare `dply deploy`) default to that Edge site.
 */
export async function link(args, _flags) {
  const ctx = await resolveContext();
  const api = new ApiClient(ctx);
  const { interactiveLinkSite, writeLinkRecord } = await import('./link-interactive.mjs');

  if (args.length === 0) {
    if (await interactiveLinkSite(api, ctx)) {
      return 0;
    }

    return sites([], {});
  }

  try {
    const response = await api.get(`/edge/sites/${encodeURIComponent(args[0])}`);
    await writeLinkRecord(ctx, response.data);
  } catch (err) {
    if (err?.status === 404) {
      throw fail(`Edge site ${args[0]} not found. Run \`dply link\` to list sites.`, 2);
    }

    throw err;
  }

  return 0;
}

/**
 * `dply sites [needle]` — the Edge sites this token can see; a positional
 * filters by name.
 *
 * @param {string[]} [args]
 * @param {Record<string, unknown>} [flags]
 */
export async function sites(args = [], flags = {}) {
  const ctx = await resolveContext();
  const api = new ApiClient(ctx);
  const { fetchAllSites, matchSites } = await import('./site-index.mjs');

  let rows = await fetchAllSites(api);
  const needle = args[0];

  if (needle) {
    rows = matchSites(rows, needle);
  }

  if (flags.json) {
    printJson(rows.map(({ raw, ...row }) => row));

    return 0;
  }

  if (rows.length === 0) {
    warn(needle ? `No site matched "${needle}".` : 'No sites visible to this token.');
    info(c.dim('Missing permissions? Try `dply auth refresh`.'));

    return 0;
  }

  printTable(
    ['id', 'name', 'status', 'url', 'runtime'],
    rows.map((row) => ({
      id: row.id,
      name: row.name,
      status: row.status,
      url: truncateCell(row.url, 44),
      runtime: row.hint || '—',
    })),
  );

  info('');
  info(c.dim('Link one: `dply link <id>` · per-site: `dply edge status --site <id>`'));

  return 0;
}

/**
 * @param {string} text
 * @param {number} max
 */
function truncateCell(text, max) {
  return text.length > max ? `${text.slice(0, max - 1)}…` : text;
}

export async function deploy(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const body = {};
  if (flags.commit) body.commit = String(flags.commit);
  if (flags.branch) body.branch = String(flags.branch);

  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/deployments`,
    body,
  );
  const d = response.data;
  ok(`Deployment queued: ${c.cyan(d.id)} (status ${d.status})`);
  printKeyValues([
    ['Commit', d.git_commit ?? '—'],
    ['Branch', d.git_branch ?? '—'],
    ['Storage prefix', d.storage_prefix ?? '—'],
  ]);

  if (deployFollowRequested(flags)) {
    await followEdgeDeployment(api, ctx.siteId, String(d.id), {
      intervalMs: deployFollowIntervalMs(flags),
    });
  }

  if (flags.prod) {
    const site = (await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}`))?.data;
    const liveUrl = site?.live_url;
    if (liveUrl) {
      ok(`Production URL: ${c.cyan(liveUrl)}`);
    } else {
      warn('Deploy queued, but no production URL is published yet.');
    }
  }
}

export async function deployments(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const limit = flags.limit ?? 20;
  const response = await api.get(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/deployments?limit=${encodeURIComponent(limit)}`,
  );
  printTable(
    ['id', 'status', 'git_commit', 'git_branch', 'published_at', 'aliases'],
    (response.data ?? []).map((d) => ({
      id: d.id,
      status: d.status,
      git_commit: d.git_commit ? d.git_commit.slice(0, 7) : '—',
      git_branch: d.git_branch ?? '—',
      published_at: d.published_at ?? '—',
      aliases: (d.aliases ?? []).length,
    })),
  );
}

export async function edgeStatus(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const site = (await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}`))?.data ?? {};
  const latest = (await api.get(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/deployments?limit=1`,
  ))?.data?.[0];

  if (flags.json) {
    printJson({ site, latest_deployment: latest ?? null });

    return;
  }

  info(c.bold(String(site.name ?? 'Edge site')));
  printKeyValues([
    ['ID', site.id ?? ctx.siteId],
    ['Hostname', site.hostname ?? '—'],
    ['Status', site.status ?? '—'],
    ['Live URL', site.live_url ?? '—'],
    ['Runtime', site.runtime_mode ?? '—'],
  ]);

  info('');
  info(c.bold('Latest deployment'));

  if (!latest) {
    warn('No deployments yet.');

    return;
  }

  printKeyValues([
    ['ID', latest.id ?? '—'],
    ['Status', latest.status ?? '—'],
    ['Commit', latest.git_commit ? String(latest.git_commit).slice(0, 7) : '—'],
    ['Branch', latest.git_branch ?? '—'],
    ['Published', latest.published_at ?? '—'],
  ]);

  if (latest.failure_reason) {
    info('');
    warn(String(latest.failure_reason));
  }

  if (
    deployFollowRequested(flags)
    && latest.id
    && latest.status
    && !TERMINAL_EDGE_DEPLOY_STATUSES.has(latest.status)
  ) {
    await followEdgeDeployment(api, ctx.siteId, String(latest.id), {
      intervalMs: deployFollowIntervalMs(flags),
    });
  } else if (latest.status && !TERMINAL_EDGE_DEPLOY_STATUSES.has(latest.status)) {
    info(c.dim('In progress — `dply edge status --wait` · or `dply deploy --wait`'));
  }

  return 0;
}

export async function rollback(args, flags) {
  const ctx = await requireSiteContext(flags);
  const deploymentId = args[0];
  if (!deploymentId) throw usageError('edge rollback <deployment-id>', 'Pass the deployment id to roll back to.');

  const api = new ApiClient(ctx);
  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/deployments/${encodeURIComponent(deploymentId)}/rollback`,
    {},
  );
  ok(`Rolled back. Deployment ${c.cyan(response.data.id)} is now live.`);
}

export async function promote(args, flags) {
  const ctx = await requireSiteContext(flags);
  const previewId = args[0];
  if (!previewId) throw usageError('edge promote <preview-site-id>', 'Pass the preview site id to promote.');

  const api = new ApiClient(ctx);
  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/previews/${encodeURIComponent(previewId)}/promote`,
    {},
  );
  ok(`Preview promoted to production. New deployment: ${c.cyan(response.data.id)}`);
}

export async function previews(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);

  const sub = args[0] ?? 'list';
  if (sub === 'list') {
    const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/previews`);
    printTable(
      ['id', 'name', 'hostname', 'status'],
      (response.data ?? []).map((p) => ({
        id: p.id,
        name: p.name,
        hostname: p.hostname,
        status: p.status,
      })),
    );

    return;
  }

  if (sub === 'create') {
    let commit = flags.commit ? String(flags.commit).trim() : '';
    let branch = flags.branch ? String(flags.branch).trim() : '';

    if (!commit) {
      const site = (await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}`))?.data ?? {};
      branch = branch || String(site.branch || 'main').trim() || 'main';
      const repo = String(site.repository || '').trim();
      if (!repo || !repo.includes('/')) {
        throw usageError(
          'edge previews create --commit <sha>',
          'Pass --commit <sha>, or ensure the Edge site has a GitHub repository so --branch can resolve HEAD.',
        );
      }
      commit = await resolveGithubCommitSha(repo, branch);
      info(c.dim(`Resolved ${repo}@${branch} → ${commit.slice(0, 7)}`));
    }

    const response = await api.post(
      `/edge/sites/${encodeURIComponent(ctx.siteId)}/previews`,
      {
        commit,
        branch: branch || undefined,
      },
    );
    const hostname = response.data?.hostname ?? response.data?.live_url ?? '—';
    const previewId = response.data?.id ?? '—';
    ok(`Preview created: ${c.cyan(hostname)} (${previewId})`);

    if (flags.wait || flags.follow) {
      return waitForEdgePreview(api, ctx.siteId, previewId, flags);
    }

    info(c.dim(`Watch: dply edge previews create --site ${ctx.siteId} --commit ${commit.slice(0, 7)} --wait`));
    info(c.dim(`Or:    dply edge status --site ${previewId} --wait`));

    return;
  }

  if (sub === 'rm' || sub === 'destroy') {
    const id = args[1];
    if (!id) throw usageError('edge previews rm <preview-id>', 'Pass the preview site id to tear down.');
    await api.delete(`/edge/sites/${encodeURIComponent(ctx.siteId)}/previews/${encodeURIComponent(id)}`);
    ok('Teardown queued.');

    return;
  }

  throw usageError('edge previews', `Unknown subcommand "${sub}". Use list, create, or rm.`);
}

export async function domains(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const sub = args[0] ?? 'list';

  if (sub === 'list') {
    const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/domains`);
    printTable(
      ['hostname', 'mode', 'dns_status', 'cname_target', 'verified_at'],
      response.data ?? [],
    );

    return;
  }

  if (sub === 'add') {
    const hostname = args[1];
    if (!hostname) throw usageError('edge domains add <hostname>', 'Pass the hostname to attach.');
    const response = await api.post(
      `/edge/sites/${encodeURIComponent(ctx.siteId)}/domains`,
      { hostname },
    );
    ok(`Domain attached. Add the CNAME below and run \`dply edge domains verify ${hostname}\`.`);
    printTable(['name', 'type', 'value', 'status'], response.data ?? []);

    return;
  }

  if (sub === 'verify') {
    const hostname = args[1];
    if (!hostname) throw usageError('edge domains verify <hostname>', 'Pass the hostname.');
    const response = await api.post(
      `/edge/sites/${encodeURIComponent(ctx.siteId)}/domains/${encodeURIComponent(hostname)}/verify`,
      {},
    );
    const status = response?.data?.dns_status ?? 'unknown';
    if (status === 'ready') ok(`${hostname} → ${c.green('ready')}`);
    else warn(`${hostname} → ${status} (${response?.data?.error ?? 'still propagating'})`);

    return;
  }

  if (sub === 'rm') {
    const hostname = args[1];
    if (!hostname) throw usageError('edge domains rm <hostname>', 'Pass the hostname to detach.');
    await api.delete(`/edge/sites/${encodeURIComponent(ctx.siteId)}/domains/${encodeURIComponent(hostname)}`);
    ok(`${hostname} detached.`);

    return;
  }

  throw usageError('edge domains', `Unknown subcommand "${sub}". Use list, add, verify, or rm.`);
}

export async function aliases(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/aliases`);
  printTable(
    ['hostname', 'deployment_id', 'git_commit', 'git_branch', 'published_at'],
    (response.data ?? []).map((a) => ({
      ...a,
      git_commit: a.git_commit ? a.git_commit.slice(0, 7) : '—',
    })),
  );
}

export async function purge(args, flags) {
  const ctx = await requireSiteContext(flags);
  const tag = flags.tag;
  if (!tag) throw usageError('edge purge --tag <tag>', '--tag is required.');
  const api = new ApiClient(ctx);
  const response = await api.post(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/cache/purge`,
    { tag },
  );
  if (response.data?.ok) ok(`Purged ${response.data.purged_keys?.length ?? 0} cache entr(ies) for tag ${c.cyan(tag)}.`);
  else warn(response.data?.message ?? 'Purge returned no entries.');
}

export async function usage(args, flags) {
  const ctx = await requireSiteContext(flags);
  const days = flags.days ?? 30;
  const api = new ApiClient(ctx);
  const response = await api.get(
    `/edge/sites/${encodeURIComponent(ctx.siteId)}/usage?days=${encodeURIComponent(days)}`,
  );
  printJson(response.data);
}

export async function logs(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const intervalMs = Math.max(500, Math.min(60000, Number.parseInt(flags.interval ?? '1000', 10) || 1000));
  const sinceWindow = Math.max(1, Math.min(3600, Number.parseInt(flags.window ?? '60', 10) || 60));
  const oneShot = Boolean(flags.once);

  let cursor = new Date(Date.now() - sinceWindow * 1000).toISOString();
  let printedHeader = false;
  let aborted = false;

  const onExit = () => {
    if (aborted) return;
    aborted = true;
    info(c.dim('\n— tail stopped —'));
  };
  process.on('SIGINT', () => {
    onExit();
    process.exit(0);
  });

  while (! aborted) {
    let response;
    try {
      response = await api.get(
        `/edge/sites/${encodeURIComponent(ctx.siteId)}/logs?since=${encodeURIComponent(cursor)}&limit=200`,
      );
    } catch (err) {
      warn(`tail: ${err.message} — retrying in ${intervalMs}ms`);
      if (oneShot) return 1;
      await sleep(intervalMs);
      continue;
    }

    if (! printedHeader) {
      info(c.dim('time              method status   ms  cache         path'));
      printedHeader = true;
    }

    const rows = response.data ?? [];
    for (const row of rows) {
      const time = (row.occurred_at ?? '').slice(11, 19) || '--:--:--';
      const status = String(row.status ?? '—').padEnd(3);
      const statusColored = (row.status ?? 0) >= 500
        ? c.red(status)
        : (row.status ?? 0) >= 400 ? c.yellow(status) : c.green(status);
      const method = (row.method ?? 'GET').padEnd(6);
      const ms = String(row.duration_ms ?? 0).padStart(4);
      const cache = (row.cache_status ?? '—').padEnd(12);
      const path = row.path ?? '/';
      process.stdout.write(`${c.dim(time)}  ${method} ${statusColored}  ${c.dim(ms)}  ${c.dim(cache)}  ${path}\n`);
    }

    const meta = response.meta ?? {};
    if (meta.tail_cursor) cursor = meta.tail_cursor;

    if (oneShot) return 0;
    await sleep(intervalMs);
  }

  return 0;
}

/**
 * Validate dply.yaml / dply.json in cwd (or --path) against the same
 * rules the build runner uses on deploy.
 */
export async function lint(args, flags) {
  const ctx = await resolveContext();
  if (!ctx.token) {
    throw fail('Not logged in. Run `dply login --token …` first.', 2);
  }

  const configPath = flags.path ? String(flags.path) : await findRepoConfigFile();
  if (!configPath) {
    throw usageError(
      'edge lint',
      'No dply.yaml, dply.yml, or dply.json found in this directory. Pass --path to lint a specific file.',
    );
  }

  const content = await readFile(configPath, 'utf8');
  const api = new ApiClient(ctx);
  let result;
  try {
    const response = await api.post('/edge/lint', {
      path: configPath.split('/').pop(),
      content,
    });
    result = response.data;
  } catch (err) {
    if (err.status === 422 && err.body?.data) {
      result = err.body.data;
    } else {
      throw err;
    }
  }

  if (result.source_path) {
    info(`Linted ${c.cyan(result.source_path)}`);
  } else {
    info(c.dim('No config file (lint ok).'));
  }

  for (const warning of result.warnings ?? []) {
    warn(warning);
  }
  for (const error of result.errors ?? []) {
    warn(`${c.red('error')}: ${error}`);
  }

  if (result.summary) {
    printKeyValues([
      ['Redirects', String(result.summary.redirects ?? 0)],
      ['Rewrites', String(result.summary.rewrites ?? 0)],
      ['Header rules', String(result.summary.headers ?? 0)],
      ['Build keys', (result.summary.build_keys ?? []).join(', ') || '—'],
    ]);
  }

  if (!result.ok) {
    throw fail('Config lint failed.', 1);
  }

  ok('Config lint passed.');
}

/**
 * Open the linked site's live URL (or dashboard with --dashboard).
 */
export async function open(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const site = (await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}`))?.data;
  const url = flags.dashboard ? site?.dashboard_url : site?.live_url;

  if (!url) {
    throw fail(flags.dashboard
      ? 'Could not resolve a dashboard URL for this site.'
      : 'No live URL published yet — deploy first with `dply edge deploy --prod`.', 1);
  }

  await openInBrowser(url);
  ok(`Opened ${c.cyan(url)}`);
}

/**
 * `dply edge env <subcommand>` — manage encrypted env vars on an Edge
 * site. Values are write-only; GET returns keys + updated_at only.
 *
 * Subcommands:
 *   list                     print all keys + updated_at
 *   set KEY=val [KEY=val…]   upsert one or more keys
 *   rm KEY [KEY…]            remove keys
 *   push --file PATH         bulk replace from dotenv-format file
 *   pull                     print all keys as dotenv comments (no values — GET is keys-only)
 */
export async function env(args, flags) {
  const ctx = await requireSiteContext(flags);
  const api = new ApiClient(ctx);
  const sub = args[0] ?? 'list';

  if (sub === 'list' || sub === 'pull') {
    const response = await api.get(`/edge/sites/${encodeURIComponent(ctx.siteId)}/env`);
    const rows = response.data ?? [];
    if (sub === 'pull') {
      info(c.dim(`# dply env vars for site ${ctx.siteId}`));
      info(c.dim('# Values are write-only via the API — set them with `dply edge env set KEY=value`.'));
      for (const row of rows) {
        info(`${row.key}=`);
      }

      return;
    }
    printTable(['key', 'updated_at'], rows.map((r) => ({
      key: r.key,
      updated_at: r.updated_at ?? '—',
    })));

    return;
  }

  if (sub === 'set') {
    const pairs = args.slice(1);
    if (pairs.length === 0) throw usageError('edge env set KEY=value [KEY=value …]', 'At least one KEY=value pair is required.');
    for (const pair of pairs) {
      const eq = pair.indexOf('=');
      if (eq <= 0) throw fail(`Invalid pair "${pair}" — expected KEY=value.`, 2);
      const key = pair.slice(0, eq);
      const value = pair.slice(eq + 1);
      await api.request(
        `/edge/sites/${encodeURIComponent(ctx.siteId)}/env/${encodeURIComponent(key)}`,
        { method: 'PATCH', body: { value } },
      );
      ok(`Set ${c.cyan(key)}`);
    }

    return;
  }

  if (sub === 'rm' || sub === 'remove' || sub === 'unset') {
    const keys = args.slice(1);
    if (keys.length === 0) throw usageError('edge env rm KEY [KEY …]', 'Pass at least one key.');
    for (const key of keys) {
      await api.delete(`/edge/sites/${encodeURIComponent(ctx.siteId)}/env/${encodeURIComponent(key)}`);
      ok(`Removed ${c.cyan(key)}`);
    }

    return;
  }

  if (sub === 'push') {
    const file = flags.file || flags.f;
    if (!file) throw usageError('edge env push --file PATH', 'Pass --file pointing at a dotenv-format file.');
    const raw = await readFile(file, 'utf8');
    const parsed = parseDotenv(raw);
    if (Object.keys(parsed).length === 0) {
      warn(`${file} produced no KEY=value pairs — nothing pushed.`);

      return;
    }
    await api.request(`/edge/sites/${encodeURIComponent(ctx.siteId)}/env`, {
      method: 'PUT',
      body: parsed,
    });
    ok(`Pushed ${Object.keys(parsed).length} key(s) from ${c.dim(file)}.`);

    return;
  }

  throw usageError('edge env', `Unknown subcommand "${sub}". Use list, set, rm, push, pull.`);
}

/**
 * Minimal dotenv parser. Honors `KEY=value`, double-quoted values
 * (with \\n / \\t escapes), single-quoted values (literal), and
 * #-prefixed comments. No variable expansion. Sufficient for env
 * files produced by `dply edge env pull` + standard `.env` workflows.
 */
function parseDotenv(raw) {
  const out = {};
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (trimmed === '' || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq <= 0) continue;
    const key = trimmed.slice(0, eq).trim().replace(/^export\s+/, '');
    if (!/^[A-Z_][A-Z0-9_]*$/.test(key)) continue;
    let value = trimmed.slice(eq + 1).trim();
    if (value.startsWith('"') && value.endsWith('"') && value.length >= 2) {
      value = value.slice(1, -1).replace(/\\n/g, '\n').replace(/\\t/g, '\t').replace(/\\"/g, '"');
    } else if (value.startsWith("'") && value.endsWith("'") && value.length >= 2) {
      value = value.slice(1, -1);
    } else {
      // Strip inline #-comments only when preceded by whitespace.
      const hashIdx = value.indexOf(' #');
      if (hashIdx > -1) value = value.slice(0, hashIdx).trim();
    }
    out[key] = value;
  }

  return out;
}

async function findRepoConfigFile() {
  for (const candidate of CONFIG_CANDIDATES) {
    try {
      await access(candidate);
      return candidate;
    } catch {
      // try next candidate
    }
  }

  return null;
}

export async function openInBrowser(url) {
  const platform = process.platform;
  if (platform === 'darwin') {
    await execFileAsync('open', [url]);

    return;
  }
  if (platform === 'win32') {
    await execFileAsync('cmd', ['/c', 'start', '', url]);

    return;
  }
  await execFileAsync('xdg-open', [url]);
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Resolve branch tip SHA via the public GitHub API (works for public repos
 * without a token). Private repos need an explicit --commit.
 *
 * @param {string} repo owner/name
 * @param {string} branch
 * @returns {Promise<string>}
 */
async function resolveGithubCommitSha(repo, branch) {
  const url = `https://api.github.com/repos/${repo}/commits/${encodeURIComponent(branch)}`;
  const response = await fetch(url, {
    headers: {
      Accept: 'application/vnd.github+json',
      'User-Agent': 'dply-cli',
    },
  });
  if (!response.ok) {
    throw fail(
      `Could not resolve ${repo}@${branch} (${response.status}). Pass --commit <sha> explicitly.`,
      1,
    );
  }
  const body = await response.json();
  const sha = typeof body?.sha === 'string' ? body.sha : '';
  if (!/^[a-f0-9]{7,40}$/i.test(sha)) {
    throw fail(`GitHub returned an unexpected commit payload for ${repo}@${branch}.`, 1);
  }

  return sha.toLowerCase();
}

/**
 * Poll a preview site until its latest deployment is terminal.
 *
 * @param {ApiClient} api
 * @param {string} parentSiteId
 * @param {string} previewId
 * @param {Record<string, unknown>} flags
 */
async function waitForEdgePreview(api, _parentSiteId, previewId, flags) {
  const intervalMs = deployFollowIntervalMs(flags);
  const deadline = Date.now() + Math.max(60_000, Number(flags.timeout_ms || flags.timeoutMs || 900_000));

  info(c.dim(`Waiting for preview ${previewId}…`));

  while (Date.now() < deadline) {
    const latest = (await api.get(
      `/edge/sites/${encodeURIComponent(previewId)}/deployments?limit=1`,
    ))?.data?.[0];

    if (latest?.id) {
      if (!TERMINAL_EDGE_DEPLOY_STATUSES.has(latest.status)) {
        await followEdgeDeployment(api, previewId, String(latest.id), { intervalMs });
      }

      const site = (await api.get(`/edge/sites/${encodeURIComponent(previewId)}`))?.data ?? {};
      const again = (await api.get(
        `/edge/sites/${encodeURIComponent(previewId)}/deployments?limit=1`,
      ))?.data?.[0];
      const status = again?.status ?? latest.status;

      if (status === 'live') {
        ok(`Preview live: ${c.cyan(site.live_url || site.hostname || previewId)}`);

        return 0;
      }

      warn(again?.failure_reason || latest.failure_reason || `Preview ended with status ${status}`);

      return 1;
    }

    await sleep(intervalMs);
  }

  warn('Timed out waiting for preview build.');

  return 1;
}

async function requireSiteContext(flags) {
  const ctx = await resolveContext({ siteFlag: flags.site });
  if (!ctx.siteId) {
    throw fail(
      'No site specified. Pass --site <id>, set DPLY_EDGE_SITE, or run `dply link <id>` first.',
      2,
    );
  }

  return ctx;
}

function usageError(command, message) {
  return fail(`${message}\nusage: dply ${command}`, 2);
}

function fail(message, exitCode = 1) {
  const err = new Error(message);
  err.exitCode = exitCode;

  return err;
}
