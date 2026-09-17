import {
  applyHtmlAddons,
  runEarlyAddons,
  waitingRoomAdmitCookie,
  type FormsConfig,
  type RateLimitConfig,
  type SnippetsConfig,
  type TagsConfig,
  type TurnstileConfig,
  type WaitingRoomConfig,
} from './addons';
import { handleAccessGate } from './auth';
import type { AccessGateConfig } from './auth';
import { injectRumScript, shouldInjectRum, VITALS_BEACON_PATH } from './rum';

/**
 * In-repo dply.yaml redirect rule. Worker matches by glob ("*" wildcard,
 * `:splat` substitution in the destination) before R2 lookup so the
 * 30x is the very first thing the visitor sees.
 */
/**
 * Cloudflare Access service-token pair. Present only when the site's origin
 * sits behind Access — typically a Cloudflare Tunnel hostname, which has no
 * public IP but is still reachable by anyone who learns the hostname.
 */
export interface OriginAccessToken {
  id: string;
  secret: string;
}

export interface RepoRedirect {
  from: string;
  to: string;
  status: number;
}

/**
 * In-repo rewrite rule. When the destination is a path (`/foo`) the
 * Worker rewrites the lookup key before hitting R2. When it's an
 * absolute URL the Worker proxies to that origin (uses the same auth
 * secret as a configured hybrid origin so locked-down APIs still get
 * the shared-secret header).
 */
export interface RepoRewrite {
  from: string;
  to: string;
}

/**
 * Header rule layered onto the final response when `for` matches the
 * request path. Multiple matching rules are merged in declaration
 * order; later rules win on duplicate header names.
 */
export interface RepoHeaderRule {
  for: string;
  values: Record<string, string>;
}

export interface HostMapEntry {
  storage_prefix: string;
  deployment_id: string;
  site_id: string;
  organization_id: string;
  spa_fallback: boolean;
  headers?: Record<string, string>;
  /**
   * "static" (default), "hybrid", or "ssr". When ssr, the platform
   * Worker dispatches every request (after redirects) to the
   * per-deployment Worker named by `ssr_worker_script`.
   */
  runtime_mode?: 'static' | 'hybrid' | 'ssr' | 'container';
  /** Dispatch namespace script name (Phase 4b). Required when runtime_mode=ssr. */
  ssr_worker_script?: string;
  /**
   * Per-deployment middleware script in the dispatch namespace (P10a).
   * When set, the platform Worker dispatches to it before redirects /
   * rewrites / R2 / origin. The script default-exports `{ fetch }` and
   * either short-circuits the request (returning the final response) or
   * returns 204 + `X-Dply-Middleware: continue` to let the platform
   * Worker keep handling the original request.
   */
  middleware_worker_script?: string;
  /**
   * Split-traffic (A/B) override (P10d). When present + the visitor
   * gets bucketed into variant B, the Worker swaps `storage_prefix`
   * for `split.preview_storage_prefix` so the request resolves
   * against the preview's R2 assets. Variant choice is persisted in
   * `sticky_cookie` (when set) so the visitor sees a consistent
   * variant on subsequent requests.
   */
  split?: {
    preview_storage_prefix: string;
    preview_deployment_id?: string;
    percentage: number;
    sticky_cookie?: string | null;
  };
  /** Per-deploy redirects sourced from dply.yaml (P6). */
  repo_redirects?: RepoRedirect[];
  /** Per-deploy rewrites sourced from dply.yaml (P6). */
  repo_rewrites?: RepoRewrite[];
  /** Per-deploy header rules sourced from dply.yaml (P6). */
  repo_header_rules?: RepoHeaderRule[];
  origin_url?: string;
  origin_routes?: string[];
  /**
   * Shared secret the Worker attaches as `X-Dply-Origin-Auth` on every
   * proxied request. Origin apps SHOULD reject requests without a matching
   * value so anything that bypasses the Edge (resolving the origin URL
   * directly) does not return real content.
   */
  origin_auth_secret?: string;
  /** Cloudflare Access service token for an Access-protected origin. */
  origin_access_client_id?: string;
  origin_access_client_secret?: string;
  /**
   * HTML body the Worker returns when the origin proxy fails (5xx or
   * timeout) after one retry. If unset, a built-in default page is used.
   * Status is 503 with `Retry-After: 30` either way.
   */
  origin_failover_html?: string;
  /**
   * HMAC signing secret for /_dply/image requests. When present, the
   * Worker enforces `sig=` query param matching HMAC-SHA256(secret,
   * canonical params). Empty/missing disables image optimization for
   * this site.
   */
  image_signing_secret?: string;
  /**
   * Hostnames the image optimizer is allowed to fetch source images
   * from. Sources outside this list are rejected with 403 to prevent
   * the Worker from being used as an open image proxy.
   */
  image_allowed_hosts?: string[];
  /**
   * When true, the Worker injects the dply preview-comment widget
   * script before `</body>` on HTML responses for *preview* sites.
   * Requires `comment_widget_token` and `comment_widget_api_base` to
   * be set; the Worker bails (no injection) if either is missing.
   */
  comment_widget_enabled?: boolean;
  /** Per-parent HMAC token the widget includes in API calls for auth. */
  comment_widget_token?: string;
  /** dply backend base URL the widget POSTs/GETs comments against. */
  comment_widget_api_base?: string;
  /**
   * True when this site is a preview deploy (has a preview_parent).
   * Set by the publisher so the Worker can gate behavior to previews
   * without an extra lookup. The comment widget only renders on
   * preview hostnames so production traffic stays clean.
   */
  is_preview?: boolean;
  /**
   * True for the site's live delivery hostname and verified custom
   * domains. Preview deploy hostnames and per-deploy aliases set this
   * false so preview protection can gate them without touching prod.
   */
  is_production?: boolean;
  /** Preview protection rule copied from the parent Edge site. */
  access_gate?: AccessGateConfig;
  /**
   * Custom HTML body returned with status 404 when no asset/route matches.
   * When unset, the Worker falls back to a minimal built-in 404 page.
   */
  error_404_html?: string;
  /**
   * Custom HTML body returned with status 500 when origin / SSR returns 5xx
   * after retry. Hybrid sites with `origin_failover_html` set keep using
   * that for origin proxy errors (more specific); this covers static and
   * non-origin failure modes.
   */
  error_500_html?: string;
  /**
   * Custom HTML body returned with status 503 when `maintenance_mode` is
   * on. When unset, the Worker uses a built-in default.
   */
  maintenance_html?: string;
  /**
   * When true, the Worker short-circuits every request (except the
   * RUM beacon) with status 503 + `Retry-After: 120` and the
   * `maintenance_html` body. Use to take a site offline without
   * redeploying.
   */
  maintenance_mode?: boolean;
  /**
   * Geo-block firewall (P55). When `firewall_country_mode` is "allow"
   * only the listed country codes (ISO 3166 alpha-2, uppercase) are
   * permitted; when "block" the listed codes are rejected. Country
   * is sourced from `request.cf.country` (Cloudflare-provided). If
   * the country is unknown ("XX"/"T1") and mode is allow, request is
   * denied (fail-closed). All denials return 403.
   */
  firewall_country_mode?: 'allow' | 'block';
  firewall_countries?: string[];
  /**
   * Skew protection (P51). Storage prefixes of the most recent
   * superseded deploys, newest first. When the current deploy returns
   * a 404 for a request that looks like a hashed asset URL (extension
   * other than .html), the Worker walks this list and serves the
   * object from the first matching prior deploy. This keeps old tabs
   * functional during/after a deploy that re-hashed chunk URLs.
   */
  recent_storage_prefixes?: string[];
  /** Managed-delivery product add-ons (bot / rate limit / forms / …). */
  turnstile?: TurnstileConfig;
  rate_limit?: RateLimitConfig;
  forms?: FormsConfig;
  waiting_room?: WaitingRoomConfig;
  snippets?: SnippetsConfig;
  tags?: TagsConfig;
}

function looksLikeAssetPath(requestPath: string): boolean {
  // Strip query already done upstream; we just need the extension.
  const lastDot = requestPath.lastIndexOf('.');
  if (lastDot < 0) return false;
  const ext = requestPath.slice(lastDot + 1).toLowerCase();
  if (ext === '' || ext === 'html' || ext === 'htm') return false;
  // Sanity: extensions are <=8 chars in practice (e.g. "woff2", "webp").
  // Anything longer is probably part of a path segment, not an extension.
  return ext.length <= 8 && /^[a-z0-9]+$/.test(ext);
}

const DEFAULT_ORIGIN_FAILOVER_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>503 — Origin temporarily unavailable</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f5ef; color: #1a1a1a; }
  @media (prefers-color-scheme: dark) { body { background: #111; color: #f6f5ef; } }
  main { max-width: 32rem; padding: 2rem; text-align: center; }
  h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
  p { margin: .25rem 0; color: #555; }
  @media (prefers-color-scheme: dark) { p { color: #999; } }
  code { font-family: ui-monospace, monospace; font-size: .85em; opacity: .7; }
</style>
</head>
<body>
<main>
  <h1>Service temporarily unavailable</h1>
  <p>The site origin did not respond. Refresh in a moment.</p>
  <p><code>HTTP 503 · dply edge</code></p>
</main>
</body>
</html>
`;

export interface Env {
  ARTIFACTS: R2Bucket;
  HOST_MAP: KVNamespace;
  /**
   * Optional read-through cache for hybrid origin GET responses. When
   * bound, the Worker stores 2xx GET responses with a Cache-Control
   * `s-maxage` (or `max-age` with `public`) into KV and serves them
   * from cache on subsequent matching requests until TTL expiry. When
   * unset, every dynamic request goes to the origin.
   */
  EDGE_CACHE?: KVNamespace;
  EDGE_ANALYTICS?: AnalyticsEngineDataset;
  /**
   * Workers for Platforms dispatch binding. When bound + a host map
   * entry sets runtime_mode=ssr + ssr_worker_script, the platform
   * Worker hands the request off to the per-deployment Worker living
   * in the namespace. Unbound = SSR sites cannot serve traffic (the
   * platform Worker returns 503 and surfaces a clear error).
   */
  DISPATCHER?: DispatchNamespace;
  ENVIRONMENT?: string;
  LOG_INGEST_BASE_URL?: string;
  LOG_INGEST_KEY?: string;
}

// Workers for Platforms binding shape. Cloudflare's official types
// expose it via @cloudflare/workers-types but only when the project
// opts into the workers-for-platforms entrypoint — declare locally
// so we don't drag the larger types surface in.
interface DispatchNamespace {
  get(scriptName: string): { fetch(request: Request, init?: RequestInit): Promise<Response> };
}

/** Path the image optimizer answers on. Reserved — sites cannot publish here. */
const EDGE_IMAGE_PATH = '/_dply/image';
/** Hard cap on Image Resizing width to avoid wasting CPU on huge requests. */
const EDGE_IMAGE_MAX_WIDTH = 4096;

/** Lower bound on s-maxage we'll honor — anything smaller round-trips. */
const EDGE_CACHE_MIN_TTL_SECONDS = 5;
/** Upper bound — KV TTL has a min of 60s and supports up to a year, but
 * we cap so misconfigured origins don't pin stale content forever. */
const EDGE_CACHE_MAX_TTL_SECONDS = 60 * 60 * 24; // 24h
/** KV value limit is 25 MB; cap entries to avoid silent write failures. */
const EDGE_CACHE_MAX_BODY_BYTES = 8 * 1024 * 1024; // 8 MB
/** Headers we never persist (per-connection / per-request metadata). */
const EDGE_CACHE_HOP_BY_HOP = new Set([
  'connection',
  'keep-alive',
  'proxy-authenticate',
  'proxy-authorization',
  'te',
  'trailer',
  'transfer-encoding',
  'upgrade',
  'set-cookie',
]);

export const SECURITY_HEADERS: Readonly<Record<string, string>> = {
  'X-Content-Type-Options': 'nosniff',
  'X-Frame-Options': 'SAMEORIGIN',
  'Referrer-Policy': 'strict-origin-when-cross-origin',
  'X-XSS-Protection': '0',
  'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
};

const MIME_BY_EXTENSION: Record<string, string> = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'application/javascript; charset=utf-8',
  '.mjs': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff': 'font/woff',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
  '.xml': 'application/xml; charset=utf-8',
  '.map': 'application/json; charset=utf-8',
};

export function normalizeRequestPath(pathname: string): string {
  let path = decodeURIComponent(pathname).replace(/^\/+/, '');

  if (path === '' || path.endsWith('/')) {
    path = `${path}index.html`.replace(/\/+/g, '/');
  }

  if (path.includes('..')) {
    throw new PathTraversalError();
  }

  return path;
}

export function buildObjectKey(storagePrefix: string, path: string): string {
  const prefix = storagePrefix.endsWith('/') ? storagePrefix : `${storagePrefix}/`;

  return `${prefix}${path}`.replace(/\/{2,}/g, '/');
}

export function isImmutableAsset(path: string): boolean {
  if (path === 'index.html' || path.endsWith('/index.html')) {
    return false;
  }

  return /\.[a-f0-9]{8,}\.[a-z0-9]+$/i.test(path);
}

export function cacheControlForPath(path: string): string {
  if (path === 'index.html' || path.endsWith('/index.html')) {
    return 'public, max-age=0, must-revalidate';
  }

  if (isImmutableAsset(path)) {
    return 'public, max-age=31536000, immutable';
  }

  return 'public, max-age=3600';
}

export function contentTypeForPath(path: string): string | undefined {
  const extension = path.slice(path.lastIndexOf('.')).toLowerCase();

  return MIME_BY_EXTENSION[extension];
}

/**
 * Glob-style match for a single rule's `from`. Supports a trailing `*`
 * (prefix match) or a bare `*` segment (matches anything). The captured
 * splat (the text matched by `*`) is returned so destinations can use
 * `:splat` substitution Netlify-style.
 *
 * Returns null when the rule doesn't match.
 */
export function matchRepoRule(requestPath: string, from: string): string | null {
  const normalizedPath = `/${requestPath}`.replace(/\/+/g, '/');
  const normalizedFrom = from.startsWith('/') ? from : `/${from}`;

  if (normalizedFrom.endsWith('*')) {
    const prefix = normalizedFrom.slice(0, -1);
    if (normalizedPath === prefix || normalizedPath.startsWith(prefix)) {
      return normalizedPath.slice(prefix.length);
    }

    return null;
  }

  if (normalizedPath === normalizedFrom || normalizedPath === `${normalizedFrom}/`) {
    return '';
  }

  return null;
}

export function applySplat(destination: string, splat: string): string {
  return destination.replaceAll(':splat', splat);
}

export function pathMatchesOriginRoute(path: string, routes: string[]): boolean {
  const normalizedPath = `/${path}`.replace(/\/+/g, '/');

  for (const route of routes) {
    const normalizedRoute = route.startsWith('/') ? route : `/${route}`;

    if (normalizedRoute.endsWith('*')) {
      const prefix = normalizedRoute.slice(0, -1);
      if (normalizedPath.startsWith(prefix)) {
        return true;
      }

      continue;
    }

    if (normalizedPath === normalizedRoute || normalizedPath === `${normalizedRoute}/`) {
      return true;
    }
  }

  return false;
}

export class PathTraversalError extends Error {
  constructor() {
    super('Path traversal is not allowed.');
    this.name = 'PathTraversalError';
  }
}

export async function handleRequest(
  request: Request,
  env: Env,
  ctx?: ExecutionContext,
): Promise<Response> {
  const started = Date.now();
  const url = new URL(request.url);
  const hostname = url.hostname;
  let hostEntry = await env.HOST_MAP.get<HostMapEntry>(hostname, 'json');

  if (!hostEntry?.storage_prefix) {
    return notFound('Host not configured.', undefined);
  }

  try {
    return await handleRequestInner(request, env, ctx, started, url, hostEntry);
  } catch (err) {
    return internalServerError(hostEntry, err);
  }
}

async function handleRequestInner(
  request: Request,
  env: Env,
  ctx: ExecutionContext | undefined,
  started: number,
  url: URL,
  hostEntryInitial: HostMapEntry,
): Promise<Response> {
  let hostEntry = hostEntryInitial;

  // Maintenance short-circuit (P52). Run before everything else so an
  // operator can take the site offline even when the access gate is
  // open. The RUM beacon still works so any tabs already loaded keep
  // reporting vitals.
  if (hostEntry.maintenance_mode && url.pathname !== VITALS_BEACON_PATH) {
    return maintenanceResponse(hostEntry);
  }

  // Geo-block firewall (P55). Run after maintenance (so an operator
  // can take the site offline globally) but before everything else.
  // Skip the vitals beacon so already-loaded tabs in blocked regions
  // can still report final metrics before they navigate away.
  if (hostEntry.firewall_country_mode && url.pathname !== VITALS_BEACON_PATH) {
    const decision = checkGeoFirewall(request, hostEntry);
    if (decision) return decision;
  }

  // Product add-ons (waiting room, forms POST, rate limits) — managed
  // dply_edge only. Skip vitals/image paths so beacons stay healthy.
  if (
    url.pathname !== VITALS_BEACON_PATH &&
    url.pathname !== EDGE_IMAGE_PATH
  ) {
    const addonEarly = await runEarlyAddons(request, url.pathname, hostEntry);
    if (addonEarly) {
      return addonEarly;
    }
  }

  // Split-traffic (P10d) — pick a variant before everything else so
  // middleware + redirects + R2 lookup all see the variant's
  // storage_prefix. Returns the (possibly mutated) hostEntry +
  // a function the caller uses to stamp the sticky cookie on the
  // outgoing response.
  const split = applySplitTrafficVariant(request, hostEntry);
  hostEntry = split.hostEntry;
  const stampVariantCookie = split.stampCookie;

  const accessResponse = await handleAccessGate(request, url, hostEntry);
  if (accessResponse) {
    return accessResponse;
  }

  if (url.pathname === VITALS_BEACON_PATH) {
    return handleVitalsBeacon(request, env, ctx, hostEntry, url);
  }

  if (url.pathname === EDGE_IMAGE_PATH) {
    const response = await handleEdgeImage(request, url, hostEntry);
    recordRequest(ctx, env, request, response, hostEntry, url, EDGE_IMAGE_PATH, started, 'image');

    return response;
  }

  let requestPath: string;

  try {
    requestPath = normalizeRequestPath(url.pathname);
  } catch (error) {
    if (error instanceof PathTraversalError) {
      return new Response('Bad Request', { status: 400 });
    }

    throw error;
  }

  // Per-site middleware (P10a) — for static + hybrid sites only.
  // Runs before redirects/rewrites/R2 so it can short-circuit (auth
  // gates, A/B routing, custom 404s) or pass through (returning
  // 204 + X-Dply-Middleware: continue) to let the rest of the
  // pipeline keep handling the request.
  if (hostEntry.middleware_worker_script && hostEntry.runtime_mode !== 'ssr') {
    const mwResult = await runMiddleware(request, env, hostEntry);
    if (mwResult.kind === 'short-circuit') {
      const finalResponse = stampVariantCookie(applyRepoHeaderRules(mwResult.response, requestPath, hostEntry));
      recordRequest(ctx, env, request, finalResponse, hostEntry, url, requestPath, started, 'middleware');

      return finalResponse;
    }
    // pass-through — keep handling the original request below.
  }

  // dply.yaml redirects run first so the visitor sees a clean 30x
  // before any R2 / origin work happens.
  const redirectResponse = matchRepoRedirect(requestPath, hostEntry);
  if (redirectResponse) {
    const stamped = stampVariantCookie(redirectResponse);
    recordRequest(ctx, env, request, stamped, hostEntry, url, requestPath, started, 'redirect');

    return stamped;
  }

  // SSR: hand the request to the per-deployment Worker living in the
  // dispatch namespace. The dispatched script owns asset lookup and
  // SSR rendering — the platform Worker just provides identity +
  // observability hooks. Falls through to a clear 503 when either
  // the dispatch binding is missing or the script name isn't set,
  // rather than silently serving a wrong response.
  // Container sites (PHP / Rails) dispatch the same way: the per-site script
  // in the namespace fronts the app container.
  if (hostEntry.runtime_mode === 'ssr' || hostEntry.runtime_mode === 'container') {
    const ssrResponse = await dispatchSsrRequest(request, env, hostEntry);
    const finalResponse = stampVariantCookie(applyRepoHeaderRules(ssrResponse, requestPath, hostEntry));
    recordRequest(ctx, env, request, finalResponse, hostEntry, url, requestPath, started, hostEntry.runtime_mode === 'container' ? 'container' : 'ssr');

    return finalResponse;
  }

  // dply.yaml rewrites: path-form rewrites update the lookup key;
  // URL-form rewrites proxy to an external origin (re-using the
  // hybrid origin proxy + failover machinery so behavior is consistent).
  const rewriteMatch = matchRepoRewrite(requestPath, hostEntry);
  if (rewriteMatch) {
    if (rewriteMatch.kind === 'path') {
      try {
        requestPath = normalizeRequestPath(rewriteMatch.target);
      } catch (error) {
        if (error instanceof PathTraversalError) {
          return new Response('Bad Request', { status: 400 });
        }

        throw error;
      }
    } else {
      const response = await proxyToOriginWithFailover(
        request,
        rewriteMatch.target,
        hostEntry.origin_auth_secret,
        hostEntry.origin_failover_html,
        originAccessToken(hostEntry),
      );
      const finalResponse = stampVariantCookie(applyRepoHeaderRules(response, requestPath, hostEntry));
      recordRequest(ctx, env, request, finalResponse, hostEntry, url, requestPath, started, 'rewrite-proxy');

      return finalResponse;
    }
  }

  const objectKey = buildObjectKey(hostEntry.storage_prefix, requestPath);
  let object = await env.ARTIFACTS.get(objectKey);

  // Skew protection (P51). When the requested path looks like a
  // hashed asset (has an extension other than .html), fall back
  // through recent superseded deploys' R2 prefixes. Old tabs that
  // loaded HTML from a prior deploy stay functional because their
  // chunk URLs still resolve. Limited to non-HTML extensions so we
  // never serve a stale page in place of a fresh one.
  if (!object && hostEntry.recent_storage_prefixes?.length && looksLikeAssetPath(requestPath)) {
    for (const prefix of hostEntry.recent_storage_prefixes) {
      const fallback = await env.ARTIFACTS.get(buildObjectKey(prefix, requestPath));
      if (fallback) {
        object = fallback;
        break;
      }
    }
  }

  // Hybrid: match origin routes before SPA fallback so /api/* and
  // /_next/data/* reach the SSR origin even when index.html exists in R2.
  if (!object && hostEntry.origin_url && hostEntry.origin_routes?.length) {
    if (pathMatchesOriginRoute(requestPath, hostEntry.origin_routes)) {
      const cached = await readEdgeCache(env, hostEntry, request);
      if (cached) {
        if (cached.stale) {
          // Stale-while-revalidate: serve stale immediately, kick off
          // a background refetch that updates the cache for the next
          // request. ctx.waitUntil keeps the Worker alive past the
          // response so the revalidation completes.
          ctx?.waitUntil(revalidateEdgeCache(env, hostEntry, request));
          recordRequest(ctx, env, request, cached.response, hostEntry, url, requestPath, started, 'cache-stale');
        } else {
          recordRequest(ctx, env, request, cached.response, hostEntry, url, requestPath, started, 'cache-hit');
        }

        return cached.response;
      }

      const response = await proxyToOriginWithFailover(
        request,
        hostEntry.origin_url,
        hostEntry.origin_auth_secret,
        hostEntry.origin_failover_html,
        originAccessToken(hostEntry),
      );

      // Tee the body into the cache without blocking the response. The
      // Workers runtime keeps the write alive via ctx.waitUntil.
      const [forClient, forCache] = teeIfCacheable(response, request);
      if (forCache) {
        ctx?.waitUntil(writeEdgeCache(env, hostEntry, request, forCache));
      }

      recordRequest(ctx, env, request, forClient, hostEntry, url, requestPath, started, 'cache-miss');

      return forClient;
    }
  }

  if (!object && hostEntry.spa_fallback && requestPath !== 'index.html') {
    const fallbackKey = buildObjectKey(hostEntry.storage_prefix, 'index.html');
    const fallbackObject = await env.ARTIFACTS.get(fallbackKey);
    if (fallbackObject) {
      object = fallbackObject;
      requestPath = 'index.html';
    }
  }

  if (!object) {
    const response = notFound('Object not found.', hostEntry);
    recordRequest(ctx, env, request, response, hostEntry, url, requestPath, started, 'miss');

    return response;
  }

  const headers = new Headers();
  object.writeHttpMetadata(headers);

  if (!headers.has('Content-Type')) {
    const inferred = contentTypeForPath(requestPath);

    if (inferred) {
      headers.set('Content-Type', inferred);
    }
  }

  headers.set('Cache-Control', cacheControlForPath(requestPath));
  headers.set('X-Dply-Deployment-Id', hostEntry.deployment_id);

  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    headers.set(name, value);
  }

  for (const [name, value] of Object.entries(hostEntry.headers ?? {})) {
    headers.set(name, value);
  }

  const hasIngest = Boolean(env.LOG_INGEST_BASE_URL && env.LOG_INGEST_KEY && hostEntry.site_id);
  const contentType = headers.get('Content-Type') ?? '';
  const isHtml = contentType.includes('text/html') || requestPath.endsWith('.html') || requestPath === 'index.html';

  let response: Response;

  const shouldInjectComments = isHtml
    && hostEntry.is_preview === true
    && hostEntry.comment_widget_enabled === true;

  const hasHtmlAddons = Boolean(
    hostEntry.snippets?.enabled ||
      hostEntry.tags?.enabled ||
      hostEntry.turnstile?.enabled,
  );

  if (isHtml && (shouldInjectRum(requestPath, hasIngest) || shouldInjectComments || hasHtmlAddons)) {
    let html = await object.text();
    if (shouldInjectRum(requestPath, hasIngest)) {
      html = injectRumScript(html);
    }
    if (shouldInjectComments) {
      html = injectCommentWidget(html, hostEntry);
    }
    if (hasHtmlAddons) {
      html = applyHtmlAddons(html, '/' + requestPath.replace(/^\/+/, ''), hostEntry);
    }
    headers.delete('Content-Length');
    response = new Response(html, { status: 200, headers });
  } else {
    response = new Response(object.body, { status: 200, headers });
  }

  response = applyRepoHeaderRules(response, requestPath, hostEntry);
  response = stampVariantCookie(response);
  const wrCookie = waitingRoomAdmitCookie(request, hostEntry.waiting_room);
  if (wrCookie) {
    const stamped = new Response(response.body, response);
    stamped.headers.append('Set-Cookie', wrCookie);
    response = stamped;
  }

  recordRequest(ctx, env, request, response, hostEntry, url, requestPath, started, 'hit');

  return response;
}

type MiddlewareResult =
  | { kind: 'short-circuit'; response: Response }
  | { kind: 'pass-through' };

async function runMiddleware(
  request: Request,
  env: Env,
  hostEntry: HostMapEntry,
): Promise<MiddlewareResult> {
  if (!env.DISPATCHER) {
    // No dispatch binding — log + pass through. Better to serve the
    // page without middleware than to fail the entire site.
    return { kind: 'pass-through' };
  }
  const scriptName = (hostEntry.middleware_worker_script ?? '').trim();
  if (scriptName === '') {
    return { kind: 'pass-through' };
  }

  try {
    const upstream = env.DISPATCHER.get(scriptName);
    const upstreamRequest = new Request(request);
    upstreamRequest.headers.set('X-Dply-Deployment-Id', hostEntry.deployment_id);
    upstreamRequest.headers.set('X-Dply-Site-Id', hostEntry.site_id);

    const response = await upstream.fetch(upstreamRequest);
    if (response.status === 204 && response.headers.get('X-Dply-Middleware') === 'continue') {
      return { kind: 'pass-through' };
    }

    const headers = new Headers(response.headers);
    for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
      if (!headers.has(name)) headers.set(name, value);
    }
    headers.set('X-Dply-Middleware', 'handled');

    return {
      kind: 'short-circuit',
      response: new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers,
      }),
    };
  } catch {
    // Middleware crashed — pass through to preserve visitor experience.
    return { kind: 'pass-through' };
  }
}

/**
 * P10d split-traffic: decide which storage prefix to use for this
 * request and (when sticky) what cookie to stamp on the response.
 * No-op when the host entry has no split config.
 */
function applySplitTrafficVariant(
  request: Request,
  hostEntry: HostMapEntry,
): { hostEntry: HostMapEntry; stampCookie: (response: Response) => Response } {
  const noop = (response: Response) => response;
  const split = hostEntry.split;
  if (!split || !split.preview_storage_prefix || split.percentage <= 0 || split.percentage >= 100) {
    return { hostEntry, stampCookie: noop };
  }

  const cookieName = split.sticky_cookie ?? '';
  const incomingVariant = cookieName !== '' ? readCookie(request, cookieName) : null;

  let variant: 'A' | 'B';
  if (incomingVariant === 'A' || incomingVariant === 'B') {
    variant = incomingVariant;
  } else {
    const bucket = bucketRequest(request);
    variant = bucket < split.percentage ? 'B' : 'A';
  }

  const swapped: HostMapEntry = variant === 'B'
    ? { ...hostEntry, storage_prefix: split.preview_storage_prefix, deployment_id: split.preview_deployment_id ?? hostEntry.deployment_id }
    : hostEntry;

  const stampCookie = cookieName === '' || incomingVariant === variant
    ? noop
    : (response: Response): Response => {
        const headers = new Headers(response.headers);
        headers.append(
          'Set-Cookie',
          `${cookieName}=${variant}; Path=/; SameSite=Lax; Max-Age=86400`,
        );
        headers.append('X-Dply-Edge-Variant', variant);

        return new Response(response.body, {
          status: response.status,
          statusText: response.statusText,
          headers,
        });
      };

  return { hostEntry: swapped, stampCookie };
}

function readCookie(request: Request, name: string): string | null {
  const header = request.headers.get('Cookie');
  if (!header) return null;
  const needle = `${name}=`;
  for (const part of header.split(';')) {
    const trimmed = part.trim();
    if (trimmed.startsWith(needle)) {
      return trimmed.slice(needle.length);
    }
  }

  return null;
}

function bucketRequest(request: Request): number {
  // Deterministic 0-99 bucket from the visitor's IP + UA so anonymous
  // visitors get a consistent first-visit variant even before the
  // sticky cookie is set.
  const seed =
    (request.headers.get('CF-Connecting-IP') ?? request.headers.get('X-Forwarded-For') ?? '') +
    '|' +
    (request.headers.get('User-Agent') ?? '');
  let hash = 5381;
  for (let i = 0; i < seed.length; i++) {
    hash = ((hash << 5) + hash) ^ seed.charCodeAt(i);
  }

  return Math.abs(hash) % 100;
}

async function dispatchSsrRequest(
  request: Request,
  env: Env,
  hostEntry: HostMapEntry,
): Promise<Response> {
  if (!env.DISPATCHER) {
    return ssrUnavailable('Worker dispatch binding missing — platform Worker was deployed without DISPATCHER.');
  }
  const scriptName = (hostEntry.ssr_worker_script ?? '').trim();
  if (scriptName === '') {
    return ssrUnavailable('SSR deployment is missing a worker script name.');
  }

  try {
    const upstream = env.DISPATCHER.get(scriptName);
    const upstreamRequest = new Request(request);
    upstreamRequest.headers.set('X-Dply-Deployment-Id', hostEntry.deployment_id);
    upstreamRequest.headers.set('X-Dply-Site-Id', hostEntry.site_id);

    return await upstream.fetch(upstreamRequest);
  } catch (err) {
    return ssrUnavailable(
      err instanceof Error ? err.message : 'Dispatch namespace returned an error.',
    );
  }
}

function ssrUnavailable(detail: string): Response {
  const headers = new Headers({
    'Content-Type': 'text/plain; charset=utf-8',
    'Cache-Control': 'no-store',
    'Retry-After': '30',
    'X-Dply-SSR-Status': 'unavailable',
  });
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    headers.set(name, value);
  }

  return new Response('Service temporarily unavailable — SSR worker not reachable.\n\n' + detail, {
    status: 503,
    headers,
  });
}

function matchRepoRedirect(requestPath: string, hostEntry: HostMapEntry): Response | null {
  const rules = hostEntry.repo_redirects ?? [];
  for (const rule of rules) {
    const splat = matchRepoRule(requestPath, rule.from);
    if (splat === null) continue;
    const location = applySplat(rule.to, splat);
    const status = rule.status >= 300 && rule.status < 400 ? rule.status : 301;
    const headers = new Headers({
      Location: location,
      'Cache-Control': 'no-cache',
      'X-Dply-Repo-Redirect': '1',
    });
    for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
      headers.set(name, value);
    }

    return new Response(null, { status, headers });
  }

  return null;
}

interface RepoRewriteMatch {
  kind: 'path' | 'url';
  target: string;
}

function matchRepoRewrite(requestPath: string, hostEntry: HostMapEntry): RepoRewriteMatch | null {
  const rules = hostEntry.repo_rewrites ?? [];
  for (const rule of rules) {
    const splat = matchRepoRule(requestPath, rule.from);
    if (splat === null) continue;
    const target = applySplat(rule.to, splat);
    const kind: 'path' | 'url' = /^https?:\/\//i.test(target) ? 'url' : 'path';

    return { kind, target };
  }

  return null;
}

function applyRepoHeaderRules(
  response: Response,
  requestPath: string,
  hostEntry: HostMapEntry,
): Response {
  const rules = hostEntry.repo_header_rules ?? [];
  if (rules.length === 0) return response;

  const matched = rules.filter((rule) => matchRepoRule(requestPath, rule.for) !== null);
  if (matched.length === 0) return response;

  const headers = new Headers(response.headers);
  for (const rule of matched) {
    for (const [name, value] of Object.entries(rule.values)) {
      headers.set(name, value);
    }
  }

  return new Response(response.body, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}

async function handleVitalsBeacon(
  request: Request,
  env: Env,
  ctx: ExecutionContext | undefined,
  hostEntry: HostMapEntry,
  url: URL,
): Promise<Response> {
  if (request.method !== 'POST') {
    return new Response('Method Not Allowed', { status: 405, headers: SECURITY_HEADERS });
  }

  const task = () => reportVitals(env, request, hostEntry, url);

  if (ctx) {
    ctx.waitUntil(task());
  } else {
    void task();
  }

  return new Response(null, { status: 204, headers: SECURITY_HEADERS });
}

async function reportVitals(
  env: Env,
  request: Request,
  hostEntry: HostMapEntry,
  url: URL,
): Promise<void> {
  const baseUrl = (env.LOG_INGEST_BASE_URL ?? '').replace(/\/+$/, '');
  const ingestKey = env.LOG_INGEST_KEY ?? '';

  if (baseUrl === '' || ingestKey === '' || !hostEntry.site_id) {
    return;
  }

  let body: Record<string, unknown>;

  try {
    body = (await request.json()) as Record<string, unknown>;
  } catch {
    return;
  }

  const payload = JSON.stringify({
    deployment_id: hostEntry.deployment_id,
    hostname: url.hostname,
    path: typeof body.path === 'string' ? body.path : '/',
    lcp_ms: body.lcp_ms ?? null,
    cls: body.cls ?? null,
    inp_ms: body.inp_ms ?? null,
    fcp_ms: body.fcp_ms ?? null,
    ttfb_ms: body.ttfb_ms ?? null,
    country: request.headers.get('CF-IPCountry') ?? '',
    occurred_at: new Date().toISOString(),
  });

  const signature = await hmacSha256Hex(`${hostEntry.site_id}.${payload}`, ingestKey);

  try {
    await fetch(`${baseUrl}/hooks/edge/${hostEntry.site_id}/vitals`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Dply-Signature': signature,
      },
      body: payload,
    });
  } catch {
    // Fire-and-forget vitals ingest.
  }
}

function recordRequest(
  ctx: ExecutionContext | undefined,
  env: Env,
  request: Request,
  response: Response,
  hostEntry: HostMapEntry,
  url: URL,
  requestPath: string,
  started: number,
  cacheStatus: string,
): void {
  const durationMs = Date.now() - started;
  const task = () => reportRequest(env, request, response, hostEntry, url, requestPath, durationMs, cacheStatus);

  if (ctx) {
    ctx.waitUntil(task());

    return;
  }

  void task();
}

async function reportRequest(
  env: Env,
  request: Request,
  response: Response,
  hostEntry: HostMapEntry,
  url: URL,
  requestPath: string,
  durationMs: number,
  cacheStatus: string,
): Promise<void> {
  const status = response.status;
  const bytesHeader = response.headers.get('Content-Length');
  const bytes = bytesHeader ? Number.parseInt(bytesHeader, 10) : 0;

  if (env.EDGE_ANALYTICS) {
    try {
      env.EDGE_ANALYTICS.writeDataPoint({
        blobs: [
          hostEntry.site_id ?? '',
          url.hostname,
          request.method,
          url.pathname,
          cacheStatus,
        ],
        doubles: [status, durationMs, Number.isFinite(bytes) ? bytes : 0],
        indexes: [hostEntry.site_id ?? url.hostname],
      });
    } catch {
      // Non-fatal — delivery must not fail when analytics errors.
    }
  }

  const baseUrl = (env.LOG_INGEST_BASE_URL ?? '').replace(/\/+$/, '');
  const ingestKey = env.LOG_INGEST_KEY ?? '';

  if (baseUrl === '' || ingestKey === '' || !hostEntry.site_id) {
    return;
  }

  const payload = JSON.stringify({
    deployment_id: hostEntry.deployment_id,
    hostname: url.hostname,
    method: request.method,
    path: url.pathname === '' ? '/' : url.pathname,
    status,
    duration_ms: durationMs,
    bytes_egress: Number.isFinite(bytes) ? bytes : 0,
    country: request.headers.get('CF-IPCountry') ?? '',
    cache_status: cacheStatus,
    referrer: request.headers.get('Referer') ?? '',
    user_agent: request.headers.get('User-Agent') ?? '',
    occurred_at: new Date().toISOString(),
  });

  const signature = await hmacSha256Hex(`${hostEntry.site_id}.${payload}`, ingestKey);

  try {
    await fetch(`${baseUrl}/hooks/edge/${hostEntry.site_id}/log`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Dply-Signature': signature,
      },
      body: payload,
    });
  } catch {
    // Fire-and-forget ingest.
  }
}

async function hmacSha256Hex(message: string, secret: string): Promise<string> {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const signature = await crypto.subtle.sign('HMAC', key, enc.encode(message));

  return [...new Uint8Array(signature)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

/**
 * Wraps proxyToOrigin with one automatic retry on a network error or 5xx
 * and a configurable failover HTML response when both attempts fail.
 * GET / HEAD requests are safe to retry; for non-idempotent methods we
 * only retry on a true network error (no response received) so we don't
 * double-submit a POST.
 *
 * Streaming support matrix (see docs/edge-hybrid-streaming.md):
 *   - SSE (text/event-stream): works via `upstream.body` passthrough — no
 *     special path needed; Cloudflare Workers stream the ReadableStream
 *     end-to-end.
 *   - Chunked HTTP responses: same path as SSE.
 *   - WebSockets: routed through proxyWebSocket() below — Worker forwards
 *     the Upgrade handshake and pipes both directions.
 */
/**
 * Both halves of the service token or nothing — a lone client id would make
 * the origin reject every request with a confusing Access error rather than
 * failing closed at publish time.
 */
function originAccessToken(hostEntry: HostMapEntry): OriginAccessToken | undefined {
  const id = hostEntry.origin_access_client_id;
  const secret = hostEntry.origin_access_client_secret;

  return id && secret ? { id, secret } : undefined;
}

async function proxyToOriginWithFailover(
  request: Request,
  originUrl: string,
  authSecret?: string,
  failoverHtml?: string,
  access?: OriginAccessToken,
): Promise<Response> {
  // WebSocket upgrades cannot be retried — once a socket pair is created,
  // it's owned by the runtime and either succeeds or fails. Skip the
  // failover wrapper entirely for these.
  if (isWebSocketUpgrade(request)) {
    return proxyWebSocket(request, originUrl, authSecret, access);
  }

  const method = request.method.toUpperCase();
  const idempotent = method === 'GET' || method === 'HEAD' || method === 'OPTIONS';

  let response: Response | null = null;
  let networkError: unknown = null;

  // Clone before the first attempt so we can replay the body on retry.
  const replayable = request.clone();

  try {
    response = await proxyToOrigin(request, originUrl, authSecret, access);
  } catch (err) {
    networkError = err;
  }

  const firstAttemptFailed =
    networkError !== null || (response !== null && response.status >= 500);
  const safeToRetry = networkError !== null || idempotent;

  if (firstAttemptFailed && safeToRetry) {
    try {
      response = await proxyToOrigin(replayable, originUrl, authSecret, access);
      networkError = null;
    } catch (err) {
      networkError = err;
    }
  }

  if (networkError !== null || (response !== null && response.status >= 500)) {
    return failoverResponse(failoverHtml);
  }

  return response as Response;
}

function failoverResponse(failoverHtml?: string): Response {
  const headers = new Headers({
    'Content-Type': 'text/html; charset=utf-8',
    'Cache-Control': 'no-store',
    'Retry-After': '30',
    'X-Dply-Edge-Failover': '1',
  });
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    headers.set(name, value);
  }

  return new Response(failoverHtml && failoverHtml.trim() !== '' ? failoverHtml : DEFAULT_ORIGIN_FAILOVER_HTML, {
    status: 503,
    statusText: 'Service Unavailable',
    headers,
  });
}

async function proxyToOrigin(
  request: Request,
  originUrl: string,
  authSecret?: string,
  access?: OriginAccessToken,
): Promise<Response> {
  const target = new URL(request.url);
  const origin = new URL(originUrl);
  target.protocol = origin.protocol;
  target.hostname = origin.hostname;
  target.port = origin.port;

  // Attach the shared secret so the origin can reject anything that
  // bypasses the Edge. Strip any value the client tried to inject
  // first — origin should never trust a client-supplied auth header.
  const upstreamRequest = new Request(target.toString(), request);
  upstreamRequest.headers.delete('X-Dply-Origin-Auth');
  if (authSecret) {
    upstreamRequest.headers.set('X-Dply-Origin-Auth', authSecret);
  }

  // Cloudflare Access service token, for an origin published through a
  // Tunnel and locked behind Access. Same rule as above: drop whatever the
  // client sent before setting ours, so these can never be spoofed inward.
  upstreamRequest.headers.delete('CF-Access-Client-Id');
  upstreamRequest.headers.delete('CF-Access-Client-Secret');
  if (access) {
    upstreamRequest.headers.set('CF-Access-Client-Id', access.id);
    upstreamRequest.headers.set('CF-Access-Client-Secret', access.secret);
  }

  const upstream = await fetch(upstreamRequest);

  const headers = new Headers(upstream.headers);
  // Cloudflare may strip Cache-Tag on subrequests — preserve for B2 indexing.
  const cacheTag = upstream.headers.get('Cache-Tag');
  if (cacheTag && !headers.has('X-Dply-Cache-Tag')) {
    headers.set('X-Dply-Cache-Tag', cacheTag);
  }
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    headers.set(name, value);
  }
  headers.set('X-Dply-Origin-Proxy', '1');

  return new Response(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers,
  });
}

/**
 * Cache key for a GET request. Includes the site id (so two sites can't
 * collide if a future operator binds the same KV namespace across
 * deployments), the path, and the sorted query string. Vary handling is
 * deliberately skipped in v1 — origins that vary by Accept-Language or
 * cookie should set Cache-Control: private to skip caching entirely.
 */
function edgeCacheKey(hostEntry: HostMapEntry, request: Request): string {
  const url = new URL(request.url);
  const params = [...url.searchParams.entries()].sort(([a], [b]) => a.localeCompare(b));
  const qs = params.length === 0
    ? ''
    : '?' + params.map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v)}`).join('&');

  return `edge_cache:${hostEntry.site_id}:${url.pathname}${qs}`;
}

interface CachedEntry {
  status: number;
  headers: Record<string, string>;
  body: string; // base64
  stored_at: number;
  /** Epoch ms — entry is fresh while now < expires_at. */
  expires_at: number;
  /** Epoch ms — entry can be served stale while expires_at <= now < swr_until. */
  swr_until: number;
}

interface CacheReadResult {
  response: Response;
  /** True when the entry is past `expires_at` but inside the SWR window. */
  stale: boolean;
  /** Original cache entry (only set when stale, to drive revalidation). */
  entry?: CachedEntry;
}

async function readEdgeCache(
  env: Env,
  hostEntry: HostMapEntry,
  request: Request,
): Promise<CacheReadResult | null> {
  if (!env.EDGE_CACHE) return null;
  if (request.method.toUpperCase() !== 'GET') return null;

  const key = edgeCacheKey(hostEntry, request);
  const raw = await env.EDGE_CACHE.get(key, { type: 'json' });
  if (!raw) return null;

  const entry = raw as CachedEntry;
  const now = Date.now();
  const isStale = now >= entry.expires_at;
  if (isStale && now >= entry.swr_until) {
    // Past the SWR window — treat as miss; the natural TTL will reap it.
    return null;
  }

  const headers = new Headers(entry.headers);
  headers.set('X-Dply-Edge-Cache', isStale ? 'STALE' : 'HIT');
  headers.set('Age', String(Math.max(0, Math.floor((now - entry.stored_at) / 1000))));

  // Body was stored as base64 so binary responses round-trip cleanly.
  const bin = atob(entry.body);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);

  return {
    response: new Response(bytes, {
      status: entry.status,
      headers,
    }),
    stale: isStale,
    entry: isStale ? entry : undefined,
  };
}

/**
 * Returns a tuple of (responseForClient, responseForCache). If the
 * response is not cacheable, the second slot is null. Caller is
 * responsible for writing the cache response into KV (typically via
 * ctx.waitUntil so it doesn't block the client).
 */
function teeIfCacheable(
  response: Response,
  request: Request,
): [Response, Response | null] {
  if (request.method.toUpperCase() !== 'GET') return [response, null];
  if (response.status !== 200) return [response, null];
  if (response.headers.has('Set-Cookie')) return [response, null];
  if (resolveCacheFreshness(response.headers) === null) return [response, null];
  if (!response.body) return [response, null];

  // tee() lets us read the body twice — once to send to the client,
  // once to persist into KV.
  const [a, b] = response.body.tee();

  return [
    new Response(a, { status: response.status, headers: response.headers }),
    new Response(b, { status: response.status, headers: response.headers }),
  ];
}

async function writeEdgeCache(
  env: Env,
  hostEntry: HostMapEntry,
  request: Request,
  response: Response,
): Promise<void> {
  if (!env.EDGE_CACHE) return;
  const freshness = resolveCacheFreshness(response.headers);
  if (freshness === null) return;

  const buf = await response.arrayBuffer();
  if (buf.byteLength > EDGE_CACHE_MAX_BODY_BYTES) return;

  const persistedHeaders: Record<string, string> = {};
  response.headers.forEach((value, key) => {
    if (!EDGE_CACHE_HOP_BY_HOP.has(key.toLowerCase())) {
      persistedHeaders[key] = value;
    }
  });

  // Encode as base64 for KV JSON storage so binary content (images,
  // wasm, etc.) doesn't get mangled by UTF-8 round-tripping.
  const bytes = new Uint8Array(buf);
  let binary = '';
  for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
  const body = btoa(binary);

  const key = edgeCacheKey(hostEntry, request);
  const now = Date.now();
  const expiresAt = now + freshness.ttl * 1000;
  const swrUntil = expiresAt + freshness.swr * 1000;
  // KV expirationTtl needs to cover the full SWR window so the entry
  // can still be read once stale. KV minimum is 60s.
  const expirationTtl = Math.max(60, freshness.ttl + freshness.swr);

  const entry: CachedEntry = {
    status: response.status,
    headers: persistedHeaders,
    body,
    stored_at: now,
    expires_at: expiresAt,
    swr_until: swrUntil,
  };

  await env.EDGE_CACHE.put(key, JSON.stringify(entry), { expirationTtl });

  // Index by Cache-Tag for B2 — purge-by-tag from the dashboard / API
  // reads these indexes to know which entries to delete. Each tag maps
  // to the *latest* cache key; on subsequent writes with the same tag,
  // we overwrite. This is approximate (older entries with the same tag
  // remain until natural TTL expiry) but avoids unbounded index growth.
  // Per-site prefix keeps tags scoped — two sites can use the same tag
  // string without colliding.
  const tags = cacheTagsFromHeaders(response.headers);
  for (const tag of tags) {
    await env.EDGE_CACHE.put(edgeCacheTagKey(hostEntry, tag), key, { expirationTtl });
  }
}

/** Tags for B2 purge — `Cache-Tag` (standard) and `X-Dply-Cache-Tag` (survives CF origin fetch). */
function cacheTagsFromHeaders(headers: Headers): string[] {
  const combined = [
    headers.get('Cache-Tag'),
    headers.get('X-Dply-Cache-Tag'),
  ]
    .filter((value): value is string => value !== null && value.trim() !== '')
    .join(',');

  return parseCacheTags(combined === '' ? null : combined);
}

function parseCacheTags(header: string | null): string[] {
  if (!header) return [];

  return header
    .split(',')
    .map((part) => part.trim())
    .filter((tag) => tag !== '' && tag.length <= 128 && /^[A-Za-z0-9._-]+$/.test(tag));
}

function edgeCacheTagKey(hostEntry: HostMapEntry, tag: string): string {
  return `edge_cache_tag:${hostEntry.site_id}:${tag}`;
}

/**
 * Pulls a freshness window from Cache-Control. Honors `s-maxage` first
 * (Edge-specific), then falls back to `max-age` when `public` is set.
 * `stale-while-revalidate=N` extends the entry past `ttl` by N more
 * seconds during which a stale hit serves immediately + revalidates
 * in the background.
 *
 * Returns null when the response is uncacheable (no-store, private,
 * no max-age).
 */
function resolveCacheFreshness(headers: Headers): { ttl: number; swr: number } | null {
  const cc = headers.get('Cache-Control');
  if (!cc) return null;

  const directives = new Map<string, string | true>();
  for (const part of cc.split(',')) {
    const trimmed = part.trim();
    if (!trimmed) continue;
    const eq = trimmed.indexOf('=');
    if (eq === -1) {
      directives.set(trimmed.toLowerCase(), true);
    } else {
      directives.set(trimmed.slice(0, eq).toLowerCase().trim(), trimmed.slice(eq + 1).trim());
    }
  }

  if (directives.has('no-store') || directives.has('private')) return null;

  let ttlRaw: number | null = null;
  const sMax = directives.get('s-maxage');
  if (typeof sMax === 'string') {
    const n = parseInt(sMax, 10);
    if (Number.isFinite(n) && n > 0) ttlRaw = n;
  }
  if (ttlRaw === null && directives.has('public')) {
    const max = directives.get('max-age');
    if (typeof max === 'string') {
      const n = parseInt(max, 10);
      if (Number.isFinite(n) && n > 0) ttlRaw = n;
    }
  }
  if (ttlRaw === null) return null;

  const ttl = clampTtl(ttlRaw);
  if (ttl === null) return null;

  let swr = 0;
  const swrRaw = directives.get('stale-while-revalidate');
  if (typeof swrRaw === 'string') {
    const n = parseInt(swrRaw, 10);
    if (Number.isFinite(n) && n > 0) {
      swr = Math.min(n, EDGE_CACHE_MAX_TTL_SECONDS);
    }
  }

  return { ttl, swr };
}

function clampTtl(seconds: number): number | null {
  if (seconds < EDGE_CACHE_MIN_TTL_SECONDS) return null;

  return Math.min(seconds, EDGE_CACHE_MAX_TTL_SECONDS);
}

/**
 * Background revalidation for SWR — re-fetch the origin and overwrite
 * the cache entry so the next request gets a fresh hit. Errors are
 * swallowed; the stale entry continues serving until SWR expires.
 */
async function revalidateEdgeCache(
  env: Env,
  hostEntry: HostMapEntry,
  request: Request,
): Promise<void> {
  if (!hostEntry.origin_url) return;

  try {
    const response = await proxyToOrigin(
      request,
      hostEntry.origin_url,
      hostEntry.origin_auth_secret,
      originAccessToken(hostEntry),
    );
    if (response.status !== 200) return;
    const [, forCache] = teeIfCacheable(response, request);
    if (forCache) {
      await writeEdgeCache(env, hostEntry, request, forCache);
    }
  } catch {
    // Stale-serve survives errors — natural SWR expiry will reap it.
  }
}

/**
 * Image optimization via Cloudflare Image Resizing.
 *
 * GET /_dply/image?url=<src>&w=<width>&q=<quality>&fmt=<format>&sig=<hmac>
 *
 * - `url` (required): absolute source URL; host must be in `image_allowed_hosts`.
 * - `w` (optional): width in pixels, 1..EDGE_IMAGE_MAX_WIDTH.
 * - `q` (optional): quality 1..100 (defaults to Cloudflare's automatic).
 * - `fmt` (optional): one of `auto|avif|webp|jpeg|png` (defaults to `auto`).
 * - `sig` (required when `image_signing_secret` is set): hex HMAC-SHA256
 *   of the canonical query string (sorted, lowercased keys, sig excluded).
 *
 * Returns the resized image with strong cache headers so the response
 * itself caches at the edge via the Cloudflare cache layer (separate
 * from the EDGE_CACHE KV used for origin proxy responses).
 */
async function handleEdgeImage(
  request: Request,
  url: URL,
  hostEntry: HostMapEntry,
): Promise<Response> {
  if (request.method.toUpperCase() !== 'GET') {
    return new Response('Method Not Allowed', { status: 405, headers: { Allow: 'GET' } });
  }

  const secret = hostEntry.image_signing_secret ?? '';
  if (secret === '') {
    return new Response('Image optimization not enabled for this site.', { status: 404 });
  }

  const source = url.searchParams.get('url') ?? '';
  const sig = url.searchParams.get('sig') ?? '';
  if (source === '' || sig === '') {
    return new Response('Missing url or sig parameter.', { status: 400 });
  }

  // Canonical signing payload: every param except sig, sorted by key.
  const canonical = [...url.searchParams.entries()]
    .filter(([k]) => k !== 'sig')
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([k, v]) => `${k.toLowerCase()}=${v}`)
    .join('&');

  const expected = await hmacSha256Hex(canonical, secret);
  if (!timingSafeEqual(expected, sig.toLowerCase())) {
    return new Response('Bad signature.', { status: 403 });
  }

  let sourceUrl: URL;
  try {
    sourceUrl = new URL(source);
  } catch {
    return new Response('Invalid source URL.', { status: 400 });
  }
  if (sourceUrl.protocol !== 'https:' && sourceUrl.protocol !== 'http:') {
    return new Response('Source URL must be http(s).', { status: 400 });
  }

  const allowedHosts = hostEntry.image_allowed_hosts ?? [];
  if (allowedHosts.length === 0 || !allowedHosts.includes(sourceUrl.hostname)) {
    return new Response('Source host is not in the allow list.', { status: 403 });
  }

  const widthParam = url.searchParams.get('w');
  const width = widthParam ? Math.min(EDGE_IMAGE_MAX_WIDTH, Math.max(1, parseInt(widthParam, 10) || 0)) : undefined;
  const qualityParam = url.searchParams.get('q');
  const quality = qualityParam ? Math.min(100, Math.max(1, parseInt(qualityParam, 10) || 0)) : undefined;
  const fmtParam = (url.searchParams.get('fmt') ?? 'auto').toLowerCase();
  const validFmts = ['auto', 'avif', 'webp', 'jpeg', 'png'] as const;
  type ImageFormat = (typeof validFmts)[number];
  const format: ImageFormat = (validFmts as readonly string[]).includes(fmtParam) ? (fmtParam as ImageFormat) : 'auto';

  const imageOpts: Record<string, unknown> = { format };
  if (width !== undefined) imageOpts.width = width;
  if (quality !== undefined) imageOpts.quality = quality;

  // Cloudflare Image Resizing: pass cf.image on the fetch. Errors fall
  // through to a generic 502 — Image Resizing returns its own 4xx on
  // bad input (oversized source, unsupported MIME, etc.).
  let upstream: Response;
  try {
    upstream = await fetch(sourceUrl.toString(), {
      cf: { image: imageOpts } as unknown as RequestInitCfProperties,
    });
  } catch {
    return new Response('Image fetch failed.', { status: 502 });
  }
  if (!upstream.ok) {
    return new Response('Image source returned ' + upstream.status, { status: 502 });
  }

  const headers = new Headers(upstream.headers);
  for (const [name, value] of Object.entries(SECURITY_HEADERS)) {
    headers.set(name, value);
  }
  // Resized images are stable for a given URL — long browser cache + Cloudflare cache.
  headers.set('Cache-Control', 'public, max-age=86400, s-maxage=86400, immutable');
  headers.set('X-Dply-Edge-Image', '1');

  return new Response(upstream.body, {
    status: upstream.status,
    statusText: upstream.statusText,
    headers,
  });
}

function timingSafeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);

  return diff === 0;
}

/**
 * Inject the dply preview-comment widget on a preview hostname. The
 * injected `<script>` tag contains a self-contained IIFE that renders
 * a floating button + sidebar, fetches existing comments, and POSTs
 * new ones to the dply backend with the per-parent widget token in
 * `X-Dply-Preview-Widget`.
 *
 * Bails (returns html unchanged) when either the token or the API
 * base is missing — both come from KV via the publisher.
 */
function injectCommentWidget(html: string, hostEntry: HostMapEntry): string {
  const token = (hostEntry.comment_widget_token ?? '').trim();
  const apiBase = (hostEntry.comment_widget_api_base ?? '').trim();
  if (token === '' || apiBase === '') return html;

  const config = JSON.stringify({
    siteId: hostEntry.site_id ?? '',
    deploymentId: hostEntry.deployment_id ?? '',
    token,
    apiBase: apiBase.replace(/\/+$/, ''),
  });

  const tag =
    `<script data-dply-preview-widget="1">` +
    `(function(){if(window.__dplyPreviewWidget)return;window.__dplyPreviewWidget=1;` +
    `var C=${config};` +
    PREVIEW_WIDGET_SOURCE +
    `})();</script>`;

  const closing = '</body>';
  const idx = html.lastIndexOf(closing);
  if (idx === -1) {
    return html + tag;
  }

  return html.slice(0, idx) + tag + html.slice(idx);
}

/**
 * Self-contained widget. Reads `C` (config object set by the wrapping
 * IIFE), renders a floating bottom-right button, opens a sidebar with
 * the comment list + a new-comment form, and talks to dply.
 *
 * Style-wise: no external dependencies, no framework, scoped class
 * prefix (`dpc-`) so it does not collide with the host page. Sized to
 * fit comfortably in a single script tag (~5 KB minified).
 */
const PREVIEW_WIDGET_SOURCE = `
var H=function(t){var d=document.createElement('div');d.textContent=t;return d.innerHTML;};
var s=document.createElement('style');
s.textContent='.dpc-btn{position:fixed;right:16px;bottom:16px;z-index:2147483646;background:#1a1a1a;color:#fff;border:none;border-radius:9999px;padding:10px 14px;font:600 12px ui-sans-serif,system-ui,sans-serif;box-shadow:0 4px 14px rgba(0,0,0,.2);cursor:pointer}.dpc-btn:hover{opacity:.92}.dpc-panel{position:fixed;right:16px;bottom:64px;width:360px;max-height:70vh;z-index:2147483647;background:#fff;color:#1a1a1a;border:1px solid rgba(0,0,0,.1);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.18);display:none;font:13px ui-sans-serif,system-ui,sans-serif;overflow:hidden}.dpc-panel.dpc-open{display:flex;flex-direction:column}.dpc-h{padding:10px 14px;border-bottom:1px solid rgba(0,0,0,.08);display:flex;justify-content:space-between;align-items:center}.dpc-h b{font-size:13px}.dpc-h .dpc-x{background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:#666}.dpc-list{flex:1;overflow-y:auto;padding:8px 14px}.dpc-c{padding:8px 0;border-bottom:1px solid rgba(0,0,0,.06)}.dpc-c:last-child{border-bottom:none}.dpc-c .m{font-size:11px;color:#888;display:flex;gap:8px;align-items:baseline}.dpc-c .b{margin-top:4px;white-space:pre-line}.dpc-empty{color:#888;padding:24px 0;text-align:center;font-size:12px}.dpc-form{padding:10px 14px;border-top:1px solid rgba(0,0,0,.08);display:flex;flex-direction:column;gap:6px}.dpc-form input,.dpc-form textarea{font:inherit;color:inherit;border:1px solid rgba(0,0,0,.15);border-radius:6px;padding:6px 8px;background:#fafafa}.dpc-form textarea{resize:vertical;min-height:60px}.dpc-form button{background:#1a1a1a;color:#fff;border:none;border-radius:6px;padding:7px 10px;font:600 12px ui-sans-serif;cursor:pointer}.dpc-form button:disabled{opacity:.5;cursor:wait}@media(prefers-color-scheme:dark){.dpc-panel{background:#1a1a1a;color:#f6f5ef;border-color:rgba(255,255,255,.1)}.dpc-h{border-color:rgba(255,255,255,.08)}.dpc-c{border-color:rgba(255,255,255,.06)}.dpc-form{border-color:rgba(255,255,255,.08)}.dpc-form input,.dpc-form textarea{background:#0d0d0d;border-color:rgba(255,255,255,.15)}.dpc-empty,.dpc-c .m{color:#999}}';
document.head.appendChild(s);
var btn=document.createElement('button');btn.className='dpc-btn';btn.type='button';btn.textContent='\u{1F4AC} Comments';
var panel=document.createElement('div');panel.className='dpc-panel';
panel.innerHTML='<div class="dpc-h"><b>Preview comments</b><button class="dpc-x" type="button">&times;</button></div><div class="dpc-list"></div><form class="dpc-form"><input name="name" placeholder="Your name (optional)" /><textarea name="body" placeholder="Leave a comment…" required></textarea><button type="submit">Add comment</button></form>';
document.body.appendChild(btn);document.body.appendChild(panel);
var list=panel.querySelector('.dpc-list');
var form=panel.querySelector('.dpc-form');
var close=panel.querySelector('.dpc-x');
btn.onclick=function(){panel.classList.toggle('dpc-open');if(panel.classList.contains('dpc-open'))load();};
close.onclick=function(){panel.classList.remove('dpc-open');};
function load(){
 fetch(C.apiBase+'/api/edge/preview-comments/'+encodeURIComponent(C.siteId),{headers:{'X-Dply-Preview-Widget':C.token}})
  .then(function(r){return r.ok?r.json():{comments:[]};})
  .then(function(d){
   var cs=(d&&d.comments)||[];
   if(!cs.length){list.innerHTML='<div class="dpc-empty">No comments yet on this preview.</div>';return;}
   list.innerHTML=cs.map(function(c){
    var when=c.created_at?new Date(c.created_at).toLocaleString():'';
    return '<div class="dpc-c"><div class="m"><b>'+H(c.author||'Guest')+'</b><span>'+H(c.url_path||'/')+'</span><span>'+H(when)+'</span></div><div class="b">'+H(c.body)+'</div></div>';
   }).join('');
  })
  .catch(function(){list.innerHTML='<div class="dpc-empty">Could not load comments.</div>';});
}
form.onsubmit=function(e){
 e.preventDefault();
 var btn2=form.querySelector('button');btn2.disabled=true;
 var name=form.name.value;var body=form.body.value;
 fetch(C.apiBase+'/api/edge/preview-comments/'+encodeURIComponent(C.siteId),{
  method:'POST',
  headers:{'Content-Type':'application/json','X-Dply-Preview-Widget':C.token},
  body:JSON.stringify({author_label:name,body:body,url_path:location.pathname||'/',viewport_width:window.innerWidth})
 }).then(function(r){return r.ok?r.json():null;})
  .then(function(){form.body.value='';load();})
  .catch(function(){})
  .then(function(){btn2.disabled=false;});
};
`;

function isWebSocketUpgrade(request: Request): boolean {
  const upgrade = request.headers.get('Upgrade');

  return upgrade !== null && upgrade.toLowerCase() === 'websocket';
}

/**
 * WebSocket passthrough. The Worker forwards the upgrade request to the
 * origin and, if accepted, returns the upstream socket back to the client.
 * The auth secret is attached just like a normal request so origins can
 * enforce it for both HTTP and WebSocket traffic.
 *
 * Cloudflare Worker fetch() on a ws upgrade returns a Response with a
 * `webSocket` property when status is 101; we return that socket pair to
 * the client.
 */
async function proxyWebSocket(
  request: Request,
  originUrl: string,
  authSecret?: string,
  access?: OriginAccessToken,
): Promise<Response> {
  const target = new URL(request.url);
  const origin = new URL(originUrl);
  target.protocol = origin.protocol;
  target.hostname = origin.hostname;
  target.port = origin.port;

  const upstreamRequest = new Request(target.toString(), request);
  upstreamRequest.headers.delete('X-Dply-Origin-Auth');
  if (authSecret) {
    upstreamRequest.headers.set('X-Dply-Origin-Auth', authSecret);
  }

  upstreamRequest.headers.delete('CF-Access-Client-Id');
  upstreamRequest.headers.delete('CF-Access-Client-Secret');
  if (access) {
    upstreamRequest.headers.set('CF-Access-Client-Id', access.id);
    upstreamRequest.headers.set('CF-Access-Client-Secret', access.secret);
  }

  const upstream = await fetch(upstreamRequest);
  if (upstream.status !== 101 || !upstream.webSocket) {
    return new Response('WebSocket upgrade failed at origin.', {
      status: 502,
      headers: { 'X-Dply-Edge-WebSocket': 'origin-rejected' },
    });
  }

  upstream.webSocket.accept();

  return new Response(null, {
    status: 101,
    webSocket: upstream.webSocket,
  });
}

function notFound(message: string, hostEntry: HostMapEntry | undefined): Response {
  const custom = hostEntry?.error_404_html;
  if (custom && custom.trim() !== '') {
    return new Response(custom, {
      status: 404,
      headers: {
        'Content-Type': 'text/html; charset=utf-8',
        ...SECURITY_HEADERS,
      },
    });
  }
  return new Response(message, {
    status: 404,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      ...SECURITY_HEADERS,
    },
  });
}

function internalServerError(hostEntry: HostMapEntry, err: unknown): Response {
  const detail = err instanceof Error ? err.message : String(err ?? 'unknown');
  console.error('edge-worker handleRequest threw', detail);
  const body = (hostEntry.error_500_html ?? '').trim() || `Internal Server Error\n${detail}`;
  const isHtml = (hostEntry.error_500_html ?? '').trim() !== '';
  return new Response(body, {
    status: 500,
    headers: {
      'Content-Type': isHtml ? 'text/html; charset=utf-8' : 'text/plain; charset=utf-8',
      'Cache-Control': 'no-store, max-age=0',
      ...SECURITY_HEADERS,
    },
  });
}

function checkGeoFirewall(request: Request, hostEntry: HostMapEntry): Response | null {
  const mode = hostEntry.firewall_country_mode;
  const list = hostEntry.firewall_countries ?? [];
  if (!mode || list.length === 0) return null;

  // request.cf.country is set by Cloudflare to the ISO-3166 alpha-2
  // country code, or "T1" / undefined when geo-location failed.
  const cf = (request as Request & { cf?: { country?: string } }).cf;
  const country = typeof cf?.country === 'string' ? cf.country.toUpperCase() : '';
  const allowed = list.includes(country);

  if (mode === 'allow' && !allowed) {
    return geoBlockedResponse(country);
  }
  if (mode === 'block' && allowed) {
    return geoBlockedResponse(country);
  }
  return null;
}

function geoBlockedResponse(country: string): Response {
  return new Response(`Forbidden — content is not available in this region (${country || 'unknown'}).`, {
    status: 403,
    headers: {
      'Content-Type': 'text/plain; charset=utf-8',
      'Cache-Control': 'no-store, max-age=0',
      ...SECURITY_HEADERS,
    },
  });
}

function maintenanceResponse(hostEntry: HostMapEntry): Response {
  const body = (hostEntry.maintenance_html ?? '').trim() || DEFAULT_MAINTENANCE_HTML;
  return new Response(body, {
    status: 503,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Retry-After': '120',
      'Cache-Control': 'no-store, max-age=0',
      ...SECURITY_HEADERS,
    },
  });
}

const DEFAULT_MAINTENANCE_HTML = `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>503 — Site under maintenance</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f5ef; color: #1a1a1a; }
  @media (prefers-color-scheme: dark) { body { background: #111; color: #f6f5ef; } }
  main { max-width: 32rem; padding: 2rem; text-align: center; }
  h1 { font-size: 1.5rem; margin: 0 0 .5rem; }
  p { margin: 0; color: #4b5563; }
  @media (prefers-color-scheme: dark) { p { color: #cbd5e1; } }
</style>
</head>
<body>
<main>
  <h1>We&rsquo;ll be right back.</h1>
  <p>This site is temporarily offline for maintenance. Please check back shortly.</p>
</main>
</body>
</html>`;
