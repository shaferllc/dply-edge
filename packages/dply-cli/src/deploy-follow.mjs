import { c, info, ok, warn } from './print.mjs';

export const TERMINAL_EDGE_DEPLOY_STATUSES = new Set(['live', 'failed', 'superseded']);

/**
 * @param {import('./api.mjs').ApiClient} client
 * @param {string} siteId
 * @param {string} deploymentId
 * @param {{ intervalMs?: number }} [options]
 */
export async function followEdgeDeployment(client, siteId, deploymentId, options = {}) {
  const intervalMs = options.intervalMs ?? 2000;

  info(c.dim(`Waiting for Edge deployment ${deploymentId}…`));

  while (true) {
    const data = (await client.get(
      `/edge/sites/${encodeURIComponent(siteId)}/deployments/${encodeURIComponent(deploymentId)}`,
    ))?.data ?? {};

    if (data.status && TERMINAL_EDGE_DEPLOY_STATUSES.has(data.status)) {
      if (data.status === 'live') {
        ok(`Edge deployment ${c.cyan(String(deploymentId))} is live.`);
        if (data.published_at) {
          info(c.dim(`Published: ${data.published_at}`));
        }

        return data;
      }

      if (data.status === 'failed') {
        warn(`Edge deployment ${deploymentId} failed.`);
        if (data.failure_reason) {
          info(String(data.failure_reason));
        }

        const err = new Error('Edge deployment failed.');
        err.exitCode = 1;

        throw err;
      }

      warn(`Edge deployment ${deploymentId} finished with status ${data.status}.`);

      return data;
    }

    if (data.status) {
      info(c.dim(`  ${data.status}…`));
    }

    await sleep(intervalMs);
  }
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * @param {Record<string, unknown>} flags
 */
export function deployFollowRequested(flags) {
  return flags.follow === true || flags.wait === true || flags.w === true;
}

/**
 * @param {Record<string, unknown>} flags
 * @returns {number}
 */
export function deployFollowIntervalMs(flags) {
  const raw = flags.interval ?? flags.i ?? 2000;
  const parsed = Number.parseInt(String(raw), 10);

  return Number.isFinite(parsed) && parsed >= 500 ? parsed : 2000;
}
