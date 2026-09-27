import { build } from 'esbuild';
import { Miniflare } from 'miniflare';
import { afterAll, beforeAll, describe, expect, it } from 'vitest';

/**
 * The same handler inside real workerd (Miniflare): R2 conditional reads,
 * the Cache API and HTMLRewriter do not exist under Node, so the unit tests
 * only reach their fallbacks.
 */
const ENTRY = `
import { handleRequest, injectEdgeHtml } from './handler';

export default {
  async fetch(request, env, ctx) {
    if (new URL(request.url).hostname === 'stream.test') {
      // An app that flushes its head and holds the rest until we say so.
      // A buffering injector could not hand back a single byte meanwhile.
      const enc = new TextEncoder();
      const dec = new TextDecoder();
      let release;
      const gate = new Promise((r) => (release = r));
      let pulls = 0;
      const upstream = new ReadableStream({
        async pull(c) {
          if (pulls++ === 0) {
            c.enqueue(enc.encode('<html><head><title>t</title></head><body>first'));
            return;
          }
          await gate;
          c.enqueue(enc.encode(' second</body></html>'));
          c.close();
        },
      });
      const injected = injectEdgeHtml(new Response(upstream, { headers: { 'Content-Type': 'text/html' } }), true, 'deploy-9');
      let reader;
      const first = await Promise.race([
        injected.then((r) => (reader = r.body.getReader()).read()).then((c) => dec.decode(c.value)),
        scheduler.wait(1000).then(() => 'TIMEOUT'),
      ]);
      release();
      reader ??= (await injected).body.getReader();
      let rest = '';
      for (let c = await reader.read(); !c.done; c = await reader.read()) rest += dec.decode(c.value);
      return Response.json({ first, html: first === 'TIMEOUT' ? rest : first + rest });
    }
    return handleRequest(request, env, ctx);
  },
};
`;

const host = {
  storage_prefix: 'edge/site-1/deploy-9',
  deployment_id: 'deploy-9',
  site_id: 'site-1',
  organization_id: 'org-1',
  spa_fallback: true,
  deploy_footer: true,
};

let mf: Miniflare;

beforeAll(async () => {
  const bundle = await build({
    stdin: { contents: ENTRY, resolveDir: 'src', loader: 'js' },
    bundle: true,
    format: 'esm',
    write: false,
    target: 'es2022',
  });
  mf = new Miniflare({
    modules: true,
    script: bundle.outputFiles[0].text,
    compatibilityDate: '2024-11-01',
    r2Buckets: ['ARTIFACTS'],
    kvNamespaces: ['HOST_MAP'],
  });
  const kv = await mf.getKVNamespace('HOST_MAP');
  await kv.put('site.test', JSON.stringify(host));
  const r2 = await mf.getR2Bucket('ARTIFACTS');
  await r2.put('edge/site-1/deploy-9/index.html', '<!doctype html><html><head></head><body>hello</body></html>', {
    httpMetadata: { contentType: 'text/html; charset=utf-8' },
  });
  await r2.put('edge/site-1/deploy-9/assets/index-BXa3Kq9z.js', 'console.log(1)', {
    httpMetadata: { contentType: 'text/javascript' },
  });
}, 30_000);

afterAll(async () => {
  await mf?.dispose();
});

describe('edge worker in workerd', () => {
  it('streams html with the footer and RUM before </body>', async () => {
    const { first, html } = (await (await mf.dispatchFetch('https://stream.test/')).json()) as { first: string; html: string };

    // Bytes left before the app finished the body.
    expect(first).not.toBe('TIMEOUT');
    expect(first).toContain('<head>');
    expect(first).not.toContain('second');

    expect(html).toContain('/__dply/vitals');
    expect(html).toMatch(/second<script>.*<\/script><p data-dply-deploy="deploy-9"[^>]*>deploy-9<\/p><\/body><\/html>$/s);
  });

  it('answers a revalidation of unchanged html with 304 from a real R2 conditional read', async () => {
    const first = await mf.dispatchFetch('https://site.test/');
    const etag = first.headers.get('ETag')!;
    expect(first.status).toBe(200);
    expect(await first.text()).toContain('data-dply-deploy="deploy-9"');
    expect(etag).toMatch(/^W\/"[0-9a-f]+-[a-z0-9]+"$/);

    const second = await mf.dispatchFetch('https://site.test/', { headers: { 'If-None-Match': etag } });
    expect(second.status).toBe(304);
    expect(await second.text()).toBe('');
  });

  it('serves an immutable asset from the colo cache once R2 has it cached', async () => {
    const first = await mf.dispatchFetch('https://site.test/assets/index-BXa3Kq9z.js');
    expect(first.headers.get('Cache-Control')).toBe('public, max-age=31536000, immutable');
    expect(await first.text()).toBe('console.log(1)');

    // Pull the object out of R2: only the colo cache can answer now.
    const r2 = await mf.getR2Bucket('ARTIFACTS');
    await r2.delete('edge/site-1/deploy-9/assets/index-BXa3Kq9z.js');

    let body = '';
    for (let attempt = 0; attempt < 20 && body === ''; attempt++) {
      const again = await mf.dispatchFetch('https://site.test/assets/index-BXa3Kq9z.js');
      body = again.status === 200 ? await again.text() : (await again.text(), '');
      if (body === '') await new Promise((r) => setTimeout(r, 50));
    }
    expect(body).toBe('console.log(1)');
  });
});
