import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  buildObjectKey,
  cacheControlForPath,
  handleRequest,
  isImmutableAsset,
  normalizeRequestPath,
  pathMatchesOriginRoute,
  type Env,
  type HostMapEntry,
} from './handler';

function createMockR2(objects: Record<string, { body: string; contentType?: string }>): R2Bucket {
  return {
    get: async (key: string) => {
      const object = objects[key];

      if (!object) {
        return null;
      }

      return {
        body: new ReadableStream({
          start(controller) {
            controller.enqueue(new TextEncoder().encode(object.body));
            controller.close();
          },
        }),
        text: async () => object.body,
        writeHttpMetadata(headers: Headers) {
          if (object.contentType) {
            headers.set('Content-Type', object.contentType);
          }
        },
      } as R2ObjectBody;
    },
  } as R2Bucket;
}

function createMockKv(entries: Record<string, HostMapEntry>): KVNamespace {
  return {
    get: async (key: string, type?: 'text' | 'json' | 'arrayBuffer' | 'stream') => {
      const entry = entries[key];

      if (!entry) {
        return null;
      }

      if (type === 'json') {
        return entry;
      }

      return JSON.stringify(entry);
    },
  } as KVNamespace;
}

describe('normalizeRequestPath', () => {
  it('maps root to index.html', () => {
    expect(normalizeRequestPath('/')).toBe('index.html');
  });

  it('maps directory paths to index.html', () => {
    expect(normalizeRequestPath('/assets/')).toBe('assets/index.html');
  });

  it('rejects path traversal', () => {
    expect(() => normalizeRequestPath('/../secret.txt')).toThrow();
  });
});

describe('buildObjectKey', () => {
  it('joins storage prefix and path', () => {
    expect(buildObjectKey('edge/site-1/deploy-9/', 'assets/app.abc12345.js')).toBe(
      'edge/site-1/deploy-9/assets/app.abc12345.js',
    );
  });
});

describe('cacheControlForPath', () => {
  it('uses short cache for index.html', () => {
    expect(cacheControlForPath('index.html')).toBe('public, max-age=0, must-revalidate');
  });

  it('uses immutable cache for hashed assets', () => {
    expect(isImmutableAsset('assets/app.abc12345.js')).toBe(true);
    expect(cacheControlForPath('assets/app.abc12345.js')).toBe('public, max-age=31536000, immutable');
  });

  it.each([
    'assets/index-BXa3Kq9z.js', // Vite 5 (base64url)
    'assets/index-B-x_3aQz.css', // Vite hash containing - and _
    'assets/logo-2c1a9b3e.svg', // Vite 4 (hex)
    'assets/chunk-ABCD2345.js', // esbuild
    '_next/static/chunks/main-3f9a1c2b4d5e6f70.js',
    '_next/static/chunks/pages/_app-0a1b2c3d4e5f6a7b.js',
    '_next/static/css/5c1b2a3d4e5f6a7b.css',
    '_next/static/abcBuildId/_buildManifest.js',
    '_astro/index.DkS8a3Qz.css',
    '_astro/hoisted.BfRxY2Kp.js',
    '_app/immutable/entry/start.BfZ3kQ2a.js',
    '_app/immutable/chunks/index.js',
    'docs/_astro/page.Cq1aB2cD.js',
  ])('treats %s as immutable', (path) => {
    expect(cacheControlForPath(path)).toBe('public, max-age=31536000, immutable');
  });

  it.each([
    'js/jquery-bootstrap.js',
    'images/hero-Homepage.png',
    'scripts/my-script-2024.js',
    'assets/app.js',
    'favicon.ico',
    'robots.txt',
    '_next/data/build/index.json',
    'index.html',
    'about/index.html',
  ])('keeps %s revalidating', (path) => {
    expect(isImmutableAsset(path)).toBe(false);
  });
});

describe('pathMatchesOriginRoute', () => {
  it('matches wildcard origin routes', () => {
    expect(pathMatchesOriginRoute('_next/data/build-id/page.json', ['/_next/*'])).toBe(true);
    expect(pathMatchesOriginRoute('assets/app.js', ['/_next/*'])).toBe(false);
  });
});

describe('handleRequest', () => {
  const hostEntry: HostMapEntry = {
    storage_prefix: 'edge/site-1/deploy-9/',
    deployment_id: 'deploy-9',
    site_id: 'site-1',
    organization_id: 'org-1',
    spa_fallback: true,
    headers: {
      'X-Dply-Site': 'site-1',
    },
  };

  it('serves a static asset from R2', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/assets/app.abc12345.js': {
          body: 'console.log("edge");',
          contentType: 'application/javascript; charset=utf-8',
        },
      }),
    };

    const response = await handleRequest(
      new Request('https://preview.example.test/assets/app.abc12345.js'),
      env,
    );

    expect(response.status).toBe(200);
    expect(await response.text()).toBe('console.log("edge");');
    expect(response.headers.get('Cache-Control')).toBe('public, max-age=31536000, immutable');
    expect(response.headers.get('X-Dply-Deployment-Id')).toBe('deploy-9');
    expect(response.headers.get('X-Dply-Site')).toBe('site-1');
    expect(response.headers.get('X-Content-Type-Options')).toBe('nosniff');
  });

  it('falls back to index.html for SPA routes', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/index.html': {
          body: '<!doctype html><html><body>edge</body></html>',
          contentType: 'text/html; charset=utf-8',
        },
      }),
    };

    const response = await handleRequest(
      new Request('https://preview.example.test/dashboard/settings'),
      env,
    );

    expect(response.status).toBe(200);
    expect(await response.text()).toContain('edge');
    expect(response.headers.get('Cache-Control')).toBe('public, max-age=0, must-revalidate');
  });

  it('injects the deploy id into html when the footer is enabled', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'preview.example.test': { ...hostEntry, deploy_footer: true },
      }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/index.html': {
          body: '<!doctype html><html><body>edge</body></html>',
          contentType: 'text/html; charset=utf-8',
        },
      }),
    };

    const response = await handleRequest(new Request('https://preview.example.test/'), env);
    const html = await response.text();

    expect(html).toContain('data-dply-deploy="deploy-9"');
    expect(html.indexOf('data-dply-deploy')).toBeLessThan(html.indexOf('</body>'));
  });

  it('leaves html unchanged when the deploy footer is off', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/index.html': {
          body: '<!doctype html><html><body>edge</body></html>',
          contentType: 'text/html; charset=utf-8',
        },
      }),
    };

    const response = await handleRequest(new Request('https://preview.example.test/'), env);

    expect(await response.text()).not.toContain('data-dply-deploy');
  });

  it('injects RUM script into html when analytics engine is bound', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/index.html': {
          body: '<!doctype html><html><body>edge</body></html>',
          contentType: 'text/html; charset=utf-8',
        },
      }),
      EDGE_ANALYTICS: { writeDataPoint: () => undefined } as AnalyticsEngineDataset,
    };

    const response = await handleRequest(new Request('https://preview.example.test/'), env);

    expect(response.status).toBe(200);
    expect(await response.text()).toContain('/__dply/vitals');
  });

  it('writes vitals beacons to analytics engine', async () => {
    const points: AnalyticsEngineDataPoint[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({}),
      EDGE_ANALYTICS: {
        writeDataPoint: (point: AnalyticsEngineDataPoint) => {
          points.push(point);
        },
      } as AnalyticsEngineDataset,
    };

    const response = await handleRequest(
      new Request('https://preview.example.test/__dply/vitals', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ path: '/', lcp_ms: 1200, cls: 0.02 }),
      }),
      env,
    );

    expect(response.status).toBe(204);
    expect(points).toEqual([
      {
        indexes: ['vsite1'],
        doubles: [1200, 0.02, 0, 0, 0],
        blobs: ['site-1', 'preview.example.test', '/'],
      },
    ]);
  });

  it('does not count dply control calls as requests', async () => {
    const points: AnalyticsEngineDataPoint[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/index.html': { body: '<!doctype html><html><body>edge</body></html>', contentType: 'text/html; charset=utf-8' },
        'edge/site-1/deploy-9/_dply/instances': { body: '{}', contentType: 'application/json' },
      }),
      EDGE_ANALYTICS: { writeDataPoint: (point: AnalyticsEngineDataPoint) => { points.push(point); } } as AnalyticsEngineDataset,
    };

    await handleRequest(new Request('https://preview.example.test/_dply/instances'), env);
    await handleRequest(new Request('https://preview.example.test/', { headers: { 'User-Agent': 'dply-uptime/1.0' } }), env);
    await handleRequest(new Request('https://preview.example.test/'), env);
    await new Promise((resolve) => setTimeout(resolve, 0));

    expect(points.map((point) => point.blobs?.[3])).toEqual(['/']);
  });

  it('returns 404 for unknown hosts', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({}),
      ARTIFACTS: createMockR2({}),
    };

    const response = await handleRequest(new Request('https://unknown.example.test/'), env);

    expect(response.status).toBe(404);
  });

  it('serves the custom 403 page when the geo firewall blocks the country', async () => {
    const geoEntry: HostMapEntry = { ...hostEntry, firewall_country_mode: 'allow', firewall_countries: ['AM'] };
    const blocked = () => {
      const req = new Request('https://geo.example.test/');
      Object.defineProperty(req, 'cf', { value: { country: 'US' } });
      return req;
    };

    const plain = await handleRequest(blocked(), {
      HOST_MAP: createMockKv({ 'geo.example.test': geoEntry }),
      ARTIFACTS: createMockR2({}),
    });
    expect(plain.status).toBe(403);
    expect(await plain.text()).toContain('not available in this region (US)');

    const custom = await handleRequest(blocked(), {
      HOST_MAP: createMockKv({ 'geo.example.test': { ...geoEntry, error_403_html: '<h1>Nope</h1>' } }),
      ARTIFACTS: createMockR2({}),
    });
    expect(custom.status).toBe(403);
    expect(custom.headers.get('Content-Type')).toContain('text/html');
    expect(await custom.text()).toBe('<h1>Nope</h1>');
  });

  it('sends the Cloudflare Access service token to an Access-protected origin', async () => {
    const hybridEntry: HostMapEntry = {
      ...hostEntry,
      origin_url: 'https://tunnel.example.test',
      origin_routes: ['/api/*'],
      origin_auth_secret: 'shared-secret',
      origin_access_client_id: 'svc.access',
      origin_access_client_secret: 'svc-secret',
    };

    const env: Env = {
      HOST_MAP: createMockKv({ 'hybrid.example.test': hybridEntry }),
      ARTIFACTS: createMockR2({}),
    };

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: RequestInfo | URL) => {
      const headers = (input as Request).headers;
      expect(headers.get('CF-Access-Client-Id')).toBe('svc.access');
      expect(headers.get('CF-Access-Client-Secret')).toBe('svc-secret');
      expect(headers.get('X-Dply-Origin-Auth')).toBe('shared-secret');

      return new Response('ok', { status: 200 });
    }) as typeof fetch;

    try {
      const response = await handleRequest(new Request('https://hybrid.example.test/api/users'), env);
      expect(response.status).toBe(200);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it('strips client-supplied Access headers so they cannot be spoofed inward', async () => {
    const hybridEntry: HostMapEntry = {
      ...hostEntry,
      origin_url: 'https://tunnel.example.test',
      origin_routes: ['/api/*'],
    };

    const env: Env = {
      HOST_MAP: createMockKv({ 'hybrid.example.test': hybridEntry }),
      ARTIFACTS: createMockR2({}),
    };

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: RequestInfo | URL) => {
      const headers = (input as Request).headers;
      // No token configured on the entry, so nothing may reach the origin.
      expect(headers.get('CF-Access-Client-Id')).toBeNull();
      expect(headers.get('CF-Access-Client-Secret')).toBeNull();

      return new Response('ok', { status: 200 });
    }) as typeof fetch;

    try {
      const response = await handleRequest(
        new Request('https://hybrid.example.test/api/users', {
          headers: {
            'CF-Access-Client-Id': 'attacker',
            'CF-Access-Client-Secret': 'attacker',
          },
        }),
        env,
      );
      expect(response.status).toBe(200);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it('sends no Access headers when only one half of the token is configured', async () => {
    const hybridEntry: HostMapEntry = {
      ...hostEntry,
      origin_url: 'https://tunnel.example.test',
      origin_routes: ['/api/*'],
      origin_access_client_id: 'svc.access',
    };

    const env: Env = {
      HOST_MAP: createMockKv({ 'hybrid.example.test': hybridEntry }),
      ARTIFACTS: createMockR2({}),
    };

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: RequestInfo | URL) => {
      const headers = (input as Request).headers;
      expect(headers.get('CF-Access-Client-Id')).toBeNull();

      return new Response('ok', { status: 200 });
    }) as typeof fetch;

    try {
      const response = await handleRequest(new Request('https://hybrid.example.test/api/users'), env);
      expect(response.status).toBe(200);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it('proxies hybrid origin routes before SPA fallback when index.html exists', async () => {
    const hybridEntry: HostMapEntry = {
      ...hostEntry,
      origin_url: 'https://origin.example.test',
      origin_routes: ['/api/*'],
    };

    const env: Env = {
      HOST_MAP: createMockKv({ 'hybrid.example.test': hybridEntry }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/index.html': {
          body: '<!doctype html><html><body>spa shell</body></html>',
          contentType: 'text/html; charset=utf-8',
        },
      }),
    };

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: RequestInfo | URL) => {
      const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
      expect(url).toContain('origin.example.test');
      expect(url).toContain('/api/users');

      return new Response('{"ok":true}', {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }) as typeof fetch;

    try {
      const response = await handleRequest(new Request('https://hybrid.example.test/api/users'), env);
      expect(response.status).toBe(200);
      expect(await response.text()).toBe('{"ok":true}');
      expect(response.headers.get('X-Dply-Origin-Proxy')).toBe('1');
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it('proxies unmatched hybrid routes to the configured origin', async () => {
    const hybridEntry: HostMapEntry = {
      ...hostEntry,
      origin_url: 'https://origin.example.test',
      origin_routes: ['/_next/*', '/api/*'],
    };

    const env: Env = {
      HOST_MAP: createMockKv({ 'hybrid.example.test': hybridEntry }),
      ARTIFACTS: createMockR2({}),
    };

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: RequestInfo | URL) => {
      const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
      expect(url).toContain('origin.example.test');
      expect(url).toContain('/api/users');

      return new Response('{"ok":true}', {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      });
    }) as typeof fetch;

    try {
      const response = await handleRequest(new Request('https://hybrid.example.test/api/users'), env);
      expect(response.status).toBe(200);
      expect(await response.text()).toBe('{"ok":true}');
      expect(response.headers.get('X-Dply-Origin-Proxy')).toBe('1');
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it('indexes cached origin responses by X-Dply-Cache-Tag for purge-by-tag', async () => {
    const tagPuts: string[] = [];
    const edgeCache = {
      get: async () => null,
      put: async (key: string) => {
        if (key.startsWith('edge_cache_tag:')) {
          tagPuts.push(key);
        }
      },
    } as unknown as KVNamespace;

    const hybridEntry: HostMapEntry = {
      ...hostEntry,
      spa_fallback: false,
      origin_url: 'https://origin.example.test',
      origin_routes: ['/api/*'],
    };

    const env: Env = {
      HOST_MAP: createMockKv({ 'hybrid.example.test': hybridEntry }),
      ARTIFACTS: createMockR2({}),
      EDGE_CACHE: edgeCache,
    };

    const pending: Promise<unknown>[] = [];
    const ctx = {
      waitUntil: (promise: Promise<unknown>) => {
        pending.push(promise);
      },
    } as ExecutionContext;

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async () =>
      new Response('{"article":42}', {
        status: 200,
        headers: {
          'Content-Type': 'application/json',
          'Cache-Control': 'public, s-maxage=3600',
          'X-Dply-Cache-Tag': 'article-42,homepage',
        },
      })) as typeof fetch;

    try {
      await handleRequest(new Request('https://hybrid.example.test/api/article'), env, ctx);
      await Promise.all(pending);
      expect(tagPuts).toContain('edge_cache_tag:site-1:article-42');
      expect(tagPuts).toContain('edge_cache_tag:site-1:homepage');
    } finally {
      globalThis.fetch = originalFetch;
    }
  });
});

describe('container sites', () => {
  it('dispatch to the per-site container script like SSR', async () => {
    const seen: string[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: (name: string) => {
          seen.push(name);
          return { fetch: async () => new Response('from laravel', { status: 200 }) };
        },
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(new Request('https://app.example.test/dashboard'), env);

    expect(seen).toEqual(['dply-ctr-site-1']);
    expect(await response.text()).toBe('from laravel');
  });

  it('replaces an html 500 from the container with the edge error page', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('<h1>Oops! An Error Occurred</h1>', {
            status: 500,
            headers: { 'content-type': 'text/html; charset=utf-8' },
          }),
        }),
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(new Request('https://app.example.test/'), env);
    const body = await response.text();

    expect(response.status).toBe(500);
    expect(body).toContain('Something went wrong');
    expect(body).toContain('rel="icon"');
    expect(body).not.toContain('Oops! An Error Occurred');
  });

  it('serves hashed container assets from the colo cache after the first hit', async () => {
    const store = new Map<string, Response>();
    (globalThis as { caches?: unknown }).caches = {
      default: {
        match: async (key: string) => store.get(key)?.clone(),
        put: async (key: string, res: Response) => { store.set(key, res); },
      },
    };
    let calls = 0;
    const waits: Promise<unknown>[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1', deployment_id: 'deploy-9', storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container', ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({ fetch: async () => { calls++; return new Response('body{}', { headers: { 'content-type': 'text/css' } }); } }),
      } as unknown as DispatchNamespace,
    };
    const ctx = { waitUntil: (p: Promise<unknown>) => { waits.push(p); } } as unknown as ExecutionContext;

    const first = await handleRequest(new Request('https://app.example.test/build/assets/app-Bd9cpcDo.css'), env, ctx);
    await Promise.all(waits);
    const second = await handleRequest(new Request('https://app.example.test/build/assets/app-Bd9cpcDo.css'), env, ctx);

    expect(first.headers.get('cache-control')).toBe('public, max-age=31536000, immutable');
    expect(await second.text()).toBe('body{}');
    expect(second.headers.get('x-dply-cache')).toBe('hit');
    expect(calls).toBe(1);
    delete (globalThis as { caches?: unknown }).caches;
  });

  it('keeps the app 500 when APP_DEBUG is on', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('<h1>SQLSTATE connection refused</h1>', {
            status: 500,
            headers: { 'content-type': 'text/html', 'x-dply-app-debug': '1' },
          }),
        }),
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(new Request('https://app.example.test/'), env);

    expect(response.status).toBe(500);
    expect(response.headers.get('x-dply-app-debug')).toBeNull();
    expect(await response.text()).toContain('SQLSTATE connection refused');
  });

  it('uses the site 500 html when one is set and leaves json alone', async () => {
    const host = {
      site_id: 'site-1',
      deployment_id: 'deploy-9',
      storage_prefix: 'edge/site-1/deploy-9',
      runtime_mode: 'container',
      ssr_worker_script: 'dply-ctr-site-1',
      error_500_html: '<p>BookStack is down</p>',
    } as HostMapEntry;
    const json = await handleRequest(new Request('https://app.example.test/api'), {
      HOST_MAP: createMockKv({ 'app.example.test': host }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('{"error":true}', {
            status: 500,
            headers: { 'content-type': 'application/json' },
          }),
        }),
      } as unknown as DispatchNamespace,
    });
    expect(await json.text()).toBe('{"error":true}');

    const html = await handleRequest(new Request('https://app.example.test/'), {
      HOST_MAP: createMockKv({ 'app.example.test': host }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('<h1>Oops</h1>', {
            status: 500,
            headers: { 'content-type': 'text/html' },
          }),
        }),
      } as unknown as DispatchNamespace,
    });
    expect(await html.text()).toBe('<p>BookStack is down</p>');
  });

  it('returns 503 and does not start the container when the usage credit is used up', async () => {
    const seen: string[] = [];
    const host: HostMapEntry = {
      site_id: 'site-1',
      deployment_id: 'deploy-9',
      storage_prefix: 'edge/site-1/deploy-9',
      runtime_mode: 'container',
      ssr_worker_script: 'dply-ctr-site-1',
    } as HostMapEntry;
    const env: Env = {
      HOST_MAP: {
        get: async (key: string, type?: string) => {
          if (key === 'container-pause:site-1') {
            return '1';
          }
          if (key === 'app.example.test') {
            return type === 'json' ? host : JSON.stringify(host);
          }

          return null;
        },
      } as KVNamespace,
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: (name: string) => {
          seen.push(name);
          return { fetch: async () => new Response('from laravel', { status: 200 }) };
        },
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(new Request('https://app.example.test/dashboard'), env);

    expect(seen).toEqual([]);
    expect(response.status).toBe(503);
    expect(await response.text()).toContain('usage credit is used up');
  });

  it('reads the pause flag from GATES when bound (the host map copy is ignored)', async () => {
    const seen: string[] = [];
    const host = { site_id: 'site-1', deployment_id: 'deploy-9', storage_prefix: 'edge/site-1/deploy-9', runtime_mode: 'container', ssr_worker_script: 'dply-ctr-site-1' } as HostMapEntry;
    const dispatcher = { get: (name: string) => { seen.push(name); return { fetch: async () => new Response('from laravel') }; } } as unknown as DispatchNamespace;
    const hostMap = { get: async (key: string, type?: string) => (key === 'app.example.test' ? (type === 'json' ? host : JSON.stringify(host)) : null) } as KVNamespace;

    const paused = await handleRequest(new Request('https://app.example.test/'), {
      HOST_MAP: hostMap, ARTIFACTS: createMockR2({}), DISPATCHER: dispatcher,
      GATES: { get: async (key: string) => (key === 'container-pause:site-1' ? '1' : null) } as KVNamespace,
    });
    expect(paused.status).toBe(503);
    expect(seen).toEqual([]);

    const open = await handleRequest(new Request('https://app.example.test/'), {
      HOST_MAP: hostMap, ARTIFACTS: createMockR2({}), DISPATCHER: dispatcher,
      GATES: { get: async () => null } as unknown as KVNamespace,
    });
    expect(open.status).toBe(200);
    expect(seen).toEqual(['dply-ctr-site-1']);
  });

  it('adds the deploy id to container html when the footer is enabled', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          deploy_footer: true,
          ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('<html><body>from laravel</body></html>', {
            status: 200,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
          }),
        }),
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(new Request('https://app.example.test/dashboard'), env);
    const html = await response.text();

    expect(html).toContain('data-dply-deploy="deploy-9"');
    expect(html.indexOf('data-dply-deploy')).toBeLessThan(html.indexOf('</body>'));
  });

  it('injects the vitals script into container html when log ingest is configured', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('<html><body>from laravel</body></html>', {
            status: 200,
            headers: { 'Content-Type': 'text/html; charset=utf-8' },
          }),
        }),
      } as unknown as DispatchNamespace,
      EDGE_ANALYTICS: { writeDataPoint: () => undefined } as AnalyticsEngineDataset,
    };

    const response = await handleRequest(new Request('https://app.example.test/dashboard'), env);
    const html = await response.text();

    expect(html).toContain('/__dply/vitals');
    expect(html.indexOf('/__dply/vitals')).toBeLessThan(html.indexOf('</body>'));
  });

  it('leaves container json untouched when log ingest is configured', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('{"ok":true}', {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          }),
        }),
      } as unknown as DispatchNamespace,
      EDGE_ANALYTICS: { writeDataPoint: () => undefined } as AnalyticsEngineDataset,
    };

    const response = await handleRequest(new Request('https://app.example.test/api/health'), env);

    expect(await response.text()).toBe('{"ok":true}');
  });

  it('stores a static asset from the container when cache mode is assets', async () => {
    const puts: string[] = [];
    const pending: Promise<unknown>[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
          cache: {
            mode: 'assets',
            edge_ttl_seconds: 86400,
            browser_ttl_seconds: 86400,
            query_string: 'ignore',
          },
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      EDGE_CACHE: {
        get: async () => null,
        put: async (key: string) => {
          puts.push(key);
        },
      } as unknown as KVNamespace,
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('console.log(1)', {
            status: 200,
            headers: { 'Content-Type': 'application/javascript' },
          }),
        }),
      } as unknown as DispatchNamespace,
    };
    const ctx = {
      waitUntil: (promise: Promise<unknown>) => {
        pending.push(promise);
      },
    } as ExecutionContext;

    const response = await handleRequest(
      new Request('https://app.example.test/build/assets/app-abc123.js?id=1'),
      env,
      ctx,
    );
    await Promise.all(pending);

    expect(response.headers.get('Cache-Control')).toContain('s-maxage=86400');
    expect(response.headers.get('Cache-Tag')).toBe('assets');
    expect(await response.text()).toBe('console.log(1)');
    expect(puts).toContain('edge_cache:site-1:/build/assets/app-abc123.js');
  });

  it('does not write the edge cache for a container without a cache mode', async () => {
    const puts: string[] = [];
    const pending: Promise<unknown>[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      EDGE_CACHE: {
        get: async () => null,
        put: async (key: string) => {
          puts.push(key);
        },
      } as unknown as KVNamespace,
      DISPATCHER: {
        get: () => ({
          fetch: async () => new Response('a{}', {
            status: 200,
            headers: { 'Content-Type': 'text/css', 'Cache-Control': 'public, max-age=31536000, immutable' },
          }),
        }),
      } as unknown as DispatchNamespace,
    };
    const ctx = { waitUntil: (promise: Promise<unknown>) => { pending.push(promise); } } as ExecutionContext;

    const response = await handleRequest(new Request('https://app.example.test/build/assets/app-BXa3Kq9z.css'), env, ctx);
    await Promise.all(pending);

    expect(await response.text()).toBe('a{}');
    expect(response.headers.get('Cache-Control')).toBe('public, max-age=31536000, immutable');
    expect(puts).toEqual([]);
  });
});

describe('websocket passthrough', () => {
  // Node cannot build a 101 Response (RangeError), which is exactly why the
  // filters must not rebuild one: a stand-in the handler has to hand back as-is.
  const socketResponse = (accept = () => {}) =>
    ({ status: 101, webSocket: { accept }, headers: new Headers(), body: null }) as unknown as Response;
  const upgrade = (url: string) => new Request(url, { headers: { Upgrade: 'websocket', Connection: 'Upgrade' } });
  const collectingCtx = (pending: Promise<unknown>[]) =>
    ({ waitUntil: (p: Promise<unknown>) => pending.push(p) }) as unknown as ExecutionContext;

  it('returns a container 101 untouched with every response filter armed', async () => {
    const fake = socketResponse();
    const cacheReads: string[] = [];
    const seen: Request[] = [];
    const pending: Promise<unknown>[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.example.test': {
          organization_id: 'org-1',
          spa_fallback: false,
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
          deploy_footer: true,
          error_500_html: '<p>oops</p>',
          repo_header_rules: [{ for: '/*', values: { 'X-Rule': '1' } }],
          split: { preview_storage_prefix: 'edge/site-1/deploy-8', percentage: 50, sticky_cookie: 'dply_variant' },
          cache: { mode: 'everything', edge_ttl_seconds: 60, browser_ttl_seconds: 60, query_string: 'ignore' },
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      EDGE_CACHE: {
        get: async (key: string) => {
          cacheReads.push(key);
          return null;
        },
        put: async () => {},
      } as unknown as KVNamespace,
      DISPATCHER: {
        get: () => ({
          fetch: async (request: Request) => {
            seen.push(request);
            return fake;
          },
        }),
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(upgrade('https://app.example.test/app/ws'), env, collectingCtx(pending));
    await Promise.all(pending);

    expect(response).toBe(fake);
    expect(cacheReads).toEqual([]);
    expect(seen[0].headers.get('Upgrade')).toBe('websocket');
  });

  it('passes an origin 101 through the rewrite proxy without accepting it', async () => {
    let accepted = false;
    const fake = socketResponse(() => {
      accepted = true;
    });
    const pending: Promise<unknown>[] = [];
    const env: Env = {
      HOST_MAP: createMockKv({
        'hybrid.example.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          organization_id: 'org-1',
          spa_fallback: false,
          storage_prefix: 'edge/site-1/deploy-9',
          repo_rewrites: [{ from: '/ws', to: 'https://origin.example.test/ws' }],
          repo_header_rules: [{ for: '/*', values: { 'X-Rule': '1' } }],
          split: { preview_storage_prefix: 'edge/site-1/deploy-8', percentage: 50, sticky_cookie: 'dply_variant' },
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
    };

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async () => fake) as unknown as typeof fetch;
    try {
      const response = await handleRequest(upgrade('https://hybrid.example.test/ws'), env, collectingCtx(pending));
      await Promise.all(pending);

      expect(response).toBe(fake);
      expect(accepted).toBe(false);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });
});

describe('static delivery speed', () => {
  const host: HostMapEntry = {
    storage_prefix: 'edge/site-1/deploy-9',
    deployment_id: 'deploy-9',
    site_id: 'site-1',
    organization_id: 'org-1',
    spa_fallback: true,
  };

  /** R2 that honours `onlyIf.etagDoesNotMatch` the way R2 does: the object, minus its body. */
  function conditionalR2(objects: Record<string, string>, reads: string[] = []): R2Bucket {
    return {
      get: async (key: string, options?: R2GetOptions) => {
        reads.push(key);
        const body = objects[key];
        if (body === undefined) return null;
        const etag = `etag-${body.length}`;
        const meta = {
          etag,
          writeHttpMetadata(headers: Headers) {
            headers.set('Content-Type', key.endsWith('.html') ? 'text/html; charset=utf-8' : 'text/javascript');
          },
        };
        const onlyIf = options?.onlyIf as R2Conditional | undefined;
        if (onlyIf?.etagDoesNotMatch === etag) return meta;

        return { ...meta, body: new Blob([body]).stream(), text: async () => body };
      },
    } as unknown as R2Bucket;
  }

  it('answers a revalidation of unchanged html with 304 and no body', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({ 'site.test': host }),
      ARTIFACTS: conditionalR2({ 'edge/site-1/deploy-9/index.html': '<html><body>hi</body></html>' }),
    };

    const first = await handleRequest(new Request('https://site.test/'), env);
    const etag = first.headers.get('ETag');
    expect(first.status).toBe(200);
    expect(etag).toMatch(/^W\/"etag-\d+-[a-z0-9]+"$/);

    const second = await handleRequest(new Request('https://site.test/', { headers: { 'If-None-Match': etag! } }), env);
    expect(second.status).toBe(304);
    expect(second.body).toBeNull();
    expect(second.headers.get('ETag')).toBe(etag);
    expect(second.headers.get('X-Content-Type-Options')).toBe('nosniff');
    expect(second.headers.get('Cache-Control')).toBe('public, max-age=0, must-revalidate');
  });

  it('sends the page again when the host entry changed what gets injected', async () => {
    const objects = { 'edge/site-1/deploy-9/index.html': '<html><body>hi</body></html>' };
    const before = await handleRequest(new Request('https://site.test/'), {
      HOST_MAP: createMockKv({ 'site.test': host }),
      ARTIFACTS: conditionalR2(objects),
    });

    const after = await handleRequest(
      new Request('https://site.test/', { headers: { 'If-None-Match': before.headers.get('ETag')! } }),
      { HOST_MAP: createMockKv({ 'site.test': { ...host, deploy_footer: true } }), ARTIFACTS: conditionalR2(objects) },
    );

    expect(after.status).toBe(200);
    expect(await after.text()).toContain('data-dply-deploy="deploy-9"');
  });

  it('ignores an If-None-Match it did not mint', async () => {
    const response = await handleRequest(
      new Request('https://site.test/', { headers: { 'If-None-Match': '"etag-28"' } }),
      { HOST_MAP: createMockKv({ 'site.test': host }), ARTIFACTS: conditionalR2({ 'edge/site-1/deploy-9/index.html': '<html><body>hi</body></html>' }) },
    );

    expect(response.status).toBe(200);
  });

  it('reads skew-protection prefixes in parallel and serves the newest hit', async () => {
    const reads: string[] = [];
    const response = await handleRequest(new Request('https://site.test/assets/app-Ab12Cd34.js'), {
      HOST_MAP: createMockKv({
        'site.test': { ...host, recent_storage_prefixes: ['edge/site-1/deploy-8', 'edge/site-1/deploy-7', 'edge/site-1/deploy-6'] },
      }),
      ARTIFACTS: conditionalR2({
        'edge/site-1/deploy-7/assets/app-Ab12Cd34.js': 'from 7',
        'edge/site-1/deploy-6/assets/app-Ab12Cd34.js': 'from 6',
      }, reads),
    });

    expect(await response.text()).toBe('from 7');
    expect(reads).toEqual([
      'edge/site-1/deploy-9/assets/app-Ab12Cd34.js',
      'edge/site-1/deploy-8/assets/app-Ab12Cd34.js',
      'edge/site-1/deploy-7/assets/app-Ab12Cd34.js',
      'edge/site-1/deploy-6/assets/app-Ab12Cd34.js',
    ]);
  });

  describe('colo cache', () => {
    const store = new Map<string, Response>();
    const original = (globalThis as { caches?: unknown }).caches;

    beforeEach(() => {
      store.clear();
      (globalThis as { caches?: unknown }).caches = {
        default: {
          match: async (key: string) => store.get(key)?.clone(),
          put: async (key: string, response: Response) => {
            store.set(key, new Response(await response.arrayBuffer(), response));
          },
        },
      };
    });
    afterEach(() => {
      (globalThis as { caches?: unknown }).caches = original;
    });

    it('serves an asset from the colo cache without touching R2 again', async () => {
      const reads: string[] = [];
      const objects: Record<string, string> = { 'edge/site-1/deploy-9/assets/index-BXa3Kq9z.js': 'console.log(1)' };
      const pending: Promise<unknown>[] = [];
      const ctx = { waitUntil: (p: Promise<unknown>) => pending.push(p) } as unknown as ExecutionContext;
      const env: Env = { HOST_MAP: createMockKv({ 'site.test': host }), ARTIFACTS: conditionalR2(objects, reads) };

      const first = await handleRequest(new Request('https://site.test/assets/index-BXa3Kq9z.js'), env, ctx);
      expect(await first.text()).toBe('console.log(1)');
      await Promise.all(pending);

      const second = await handleRequest(new Request('https://site.test/assets/index-BXa3Kq9z.js'), env, ctx);
      expect(await second.text()).toBe('console.log(1)');
      expect(second.headers.get('Cache-Control')).toBe('public, max-age=31536000, immutable');
      expect(second.headers.get('Content-Type')).toBe('text/javascript');
      expect(reads).toHaveLength(1);

      const revalidated = await handleRequest(
        new Request('https://site.test/assets/index-BXa3Kq9z.js', { headers: { 'If-None-Match': first.headers.get('ETag')! } }),
        env,
        ctx,
      );
      expect(revalidated.status).toBe(304);
      expect(reads).toHaveLength(1);
    });

    it('never puts html in the colo cache', async () => {
      const pending: Promise<unknown>[] = [];
      const ctx = { waitUntil: (p: Promise<unknown>) => pending.push(p) } as unknown as ExecutionContext;
      await handleRequest(new Request('https://site.test/'), {
        HOST_MAP: createMockKv({ 'site.test': host }),
        ARTIFACTS: conditionalR2({ 'edge/site-1/deploy-9/index.html': '<html><body>hi</body></html>' }),
      }, ctx);
      await Promise.all(pending);

      expect(store.size).toBe(0);
    });
  });
});

describe('container kv reads', () => {
  it('reads the pause flag and the edge cache at the same time', async () => {
    const inFlight: string[] = [];
    let overlap = false;
    const slow = async <T>(key: string, value: T): Promise<T> => {
      inFlight.push(key);
      if (inFlight.length > 1) overlap = true;
      await new Promise((resolve) => setTimeout(resolve, 5));
      inFlight.splice(inFlight.indexOf(key), 1);

      return value;
    };
    const host = {
      site_id: 'site-1',
      deployment_id: 'deploy-9',
      storage_prefix: 'edge/site-1/deploy-9',
      runtime_mode: 'container',
      ssr_worker_script: 'dply-ctr-site-1',
      cache: { mode: 'everything', edge_ttl_seconds: 60, browser_ttl_seconds: 60, query_string: 'ignore' },
    } as HostMapEntry;
    const env: Env = {
      HOST_MAP: {
        get: async (key: string, type?: string) =>
          key === 'app.test' ? (type === 'json' ? host : JSON.stringify(host)) : slow(key, null),
      } as unknown as KVNamespace,
      EDGE_CACHE: { get: async (key: string) => slow(key, null), put: async () => {} } as unknown as KVNamespace,
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({ fetch: async () => new Response('ok', { status: 200 }) }),
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(new Request('https://app.test/'), env);

    expect(await response.text()).toBe('ok');
    expect(overlap).toBe(true);
  });
});

describe('waiting room cookie and edge errors', () => {
  const room = { enabled: true, total_active_users: 50, new_users_per_minute: 50, session_duration_minutes: 30, paths: [] as string[] };

  it('stamps the waiting room cookie on container responses', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.test': {
          site_id: 'site-1',
          deployment_id: 'deploy-9',
          storage_prefix: 'edge/site-1/deploy-9',
          runtime_mode: 'container',
          ssr_worker_script: 'dply-ctr-site-1',
          waiting_room: room,
        } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({}),
      DISPATCHER: {
        get: () => ({ fetch: async () => new Response('ok', { status: 200 }) }),
      } as unknown as DispatchNamespace,
    };

    const response = await handleRequest(new Request('https://app.test/checkout'), env);

    expect(await response.text()).toBe('ok');
    expect(response.headers.get('Set-Cookie')).toContain('dply_wr=1');
  });

  it('does not stamp the cookie on a visitor who already has it', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.test': { site_id: 'site-1', deployment_id: 'deploy-9', storage_prefix: 'edge/site-1/deploy-9', waiting_room: room } as HostMapEntry,
      }),
      ARTIFACTS: createMockR2({ 'edge/site-1/deploy-9/index.html': { body: 'hi', contentType: 'text/html' } }),
    };

    const response = await handleRequest(new Request('https://app.test/', { headers: { cookie: 'dply_wr=1' } }), env);

    expect(response.headers.get('Set-Cookie')).toBeNull();
  });

  it('never shows visitors the internal error when the edge itself fails', async () => {
    const errors: unknown[] = [];
    const spy = vi.spyOn(console, 'error').mockImplementation((...args) => errors.push(args));
    const env: Env = {
      HOST_MAP: createMockKv({
        'app.test': { site_id: 'site-1', deployment_id: 'deploy-9', storage_prefix: 'edge/site-1/deploy-9' } as HostMapEntry,
      }),
      ARTIFACTS: {
        get: async () => {
          throw new Error('R2 bucket dply-artifacts-internal unreachable');
        },
      } as unknown as R2Bucket,
    };

    const response = await handleRequest(new Request('https://app.test/'), env);
    spy.mockRestore();

    expect(response.status).toBe(500);
    expect(response.headers.get('Content-Type')).toContain('text/html');
    const body = await response.text();
    expect(body).toContain('Something went wrong');
    expect(body).not.toContain('dply-artifacts-internal');
    expect(JSON.stringify(errors)).toContain('dply-artifacts-internal');
  });
});
