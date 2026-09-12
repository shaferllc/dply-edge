import { ApiClient } from './api.mjs';
import { defaultBaseUrl, readGlobalConfig } from './config.mjs';

/**
 * @param {Record<string, unknown>} [flags]
 */
export async function requireClient(flags = {}) {
  const cfg = await readGlobalConfig();
  const baseUrl = (
    flags['base-url'] ||
    flags.b ||
    process.env.DPLY_API_BASE_URL ||
    process.env.DPLY_BASE_URL ||
    process.env.DPLY_HOST ||
    cfg?.baseUrl ||
    defaultBaseUrl()
  ).replace(/\/+$/, '');
  // Env tokens let CI + the in-browser CLI console inject auth without
  // writing ~/.dply/config.json (DPLY_TOKEN is the historical name).
  const token =
    process.env.DPLY_TOKEN ||
    process.env.DPLY_API_TOKEN ||
    cfg?.token;

  if (!token) {
    const err = new Error('Not logged in. Run `dply login` first (or `dply login --token …` for CI).');
    err.exitCode = 2;

    throw err;
  }

  if (!baseUrl) {
    const err = new Error('No API base URL configured. Re-run `dply login --base-url https://your-instance`.');
    err.exitCode = 2;

    throw err;
  }

  return new ApiClient({ baseUrl, token });
}
