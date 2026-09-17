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

  it('injects RUM script into html when log ingest is configured', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({
        'edge/site-1/deploy-9/index.html': {
          body: '<!doctype html><html><body>edge</body></html>',
          contentType: 'text/html; charset=utf-8',
        },
      }),
      LOG_INGEST_BASE_URL: 'https://dply.test',
      LOG_INGEST_KEY: 'secret',
    };

    const response = await handleRequest(new Request('https://preview.example.test/'), env);

    expect(response.status).toBe(200);
    expect(await response.text()).toContain('/__dply/vitals');
  });

  it('accepts vitals beacon posts on worker path', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({ 'preview.example.test': hostEntry }),
      ARTIFACTS: createMockR2({}),
      LOG_INGEST_BASE_URL: 'https://dply.test',
      LOG_INGEST_KEY: 'secret',
    };

    const originalFetch = globalThis.fetch;
    globalThis.fetch = (async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = typeof input === 'string' ? input : input instanceof URL ? input.href : input.url;
      expect(url).toContain('/hooks/edge/site-1/vitals');
      expect(init?.method).toBe('POST');

      return new Response('{"message":"Recorded."}', { status: 202 });
    }) as typeof fetch;

    try {
      const response = await handleRequest(
        new Request('https://preview.example.test/__dply/vitals', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ path: '/', lcp_ms: 1200 }),
        }),
        env,
      );

      expect(response.status).toBe(204);
    } finally {
      globalThis.fetch = originalFetch;
    }
  });

  it('returns 404 for unknown hosts', async () => {
    const env: Env = {
      HOST_MAP: createMockKv({}),
      ARTIFACTS: createMockR2({}),
    };

    const response = await handleRequest(new Request('https://unknown.example.test/'), env);

    expect(response.status).toBe(404);
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
});
