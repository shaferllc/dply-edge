import { describe, expect, it } from 'vitest';
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

  it('returns 404 for unknown hosts', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({}),
      ARTIFACTS: createMockR2({}),
    };

    const response = await handleRequest(new Request('https://unknown.example.test/'), env);

    expect(response.status).toBe(404);
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
    } as KVNamespace;

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
      } as KVNamespace,
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
});
