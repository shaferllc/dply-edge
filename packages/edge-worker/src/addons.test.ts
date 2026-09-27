import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
  applyHtmlAddons,
  injectDeployFooter,
  injectSnippets,
  injectTags,
  injectTurnstileWidget,
  runEarlyAddons,
  waitingRoomAdmitCookie,
  type EdgeAddonsHostEntry,
} from './addons';

describe('edge addons html helpers', () => {
  it('injects turnstile into forms', () => {
    const html = '<html><body><form action="/contact"></form></body></html>';
    const out = injectTurnstileWidget(html, 'site-key-1');
    expect(out).toContain('cf-turnstile');
    expect(out).toContain('site-key-1');
    expect(out).toContain('challenges.cloudflare.com/turnstile');
  });

  it('injects a deploy id before the closing body tag', () => {
    const out = injectDeployFooter('<html><body><p>Hi</p></body></html>', 'deploy-9');
    expect(out).toContain('data-dply-deploy="deploy-9"');
    expect(out.indexOf('data-dply-deploy')).toBeLessThan(out.indexOf('</body>'));
    expect(injectDeployFooter(out, 'deploy-9').match(/data-dply-deploy/g)).toHaveLength(1);
  });

  it('injects head snippets', () => {
    const html = '<html><head><title>x</title></head><body></body></html>';
    const out = injectSnippets(html, '/', {
      enabled: true,
      items: [{ name: 'meta', phase: 'head', path: '/*', html: '<meta name="x" content="1">' }],
    });
    expect(out).toContain('<meta name="x" content="1"></head>');
  });

  it('injects https tag scripts', () => {
    const html = '<html><head></head><body></body></html>';
    const out = injectTags(html, {
      enabled: true,
      tools: [{ name: 'a', src: 'https://example.com/a.js', async: true }],
    });
    expect(out).toContain('src="https://example.com/a.js"');
  });

  it('injects consent helper without any script URLs', () => {
    const html = '<html><head></head><body></body></html>';
    const out = injectTags(html, {
      enabled: true,
      consent_required: true,
      tools: [],
    });
    expect(out).toContain('__dplyTags');
    expect(out).toContain('dply_tag_consent');
    expect(out).not.toContain('<script src=');
  });

  it('renders vendor loader and init from an id', () => {
    const out = injectTags('<html><head></head></html>', {
      enabled: true,
      tools: [{ name: 'GA', vendor: 'ga4', id: 'G-ABC123' }],
    });
    expect(out).toContain("gtag('config',\"G-ABC123\")");
    expect(out).toContain('src="https://www.googletagmanager.com/gtag/js?id=G-ABC123" async');
    expect(out).toContain('__dplyTags');
  });

  it('holds non-necessary tools until consent', () => {
    const out = injectTags('<html><head></head></html>', {
      enabled: true,
      consent_required: true,
      tools: [
        { name: 'px', vendor: 'meta', id: '1234567', purpose: 'marketing' },
        { name: 'ok', src: 'https://cdn.example/necessary.js', purpose: 'necessary' },
      ],
    });
    expect(out).not.toContain('src="https://connect.facebook.net');
    expect(out).toContain('"p":"marketing"');
    expect(out).toContain('src="https://cdn.example/necessary.js"');
  });

  it('only fires tools whose path matches', () => {
    const cfg = { enabled: true, tools: [{ name: 'c', src: 'https://cdn.example/c.js', path: '/checkout/*' }] };
    expect(injectTags('<head></head>', cfg, '/')).toBe('<head></head>');
    expect(injectTags('<head></head>', cfg, '/checkout/pay')).toContain('cdn.example/c.js');
  });

  it('cannot break out of the inline script with a bad id', () => {
    const out = injectTags('<head></head>', {
      enabled: true,
      consent_required: true,
      tools: [{ name: 'x', vendor: 'ga4', id: '</script><script>alert(1)' }],
    });
    expect(out.match(/<\/script>/g)?.length).toBe(1);
  });

  it('tag runtime lands after a legacy snippet consent helper', () => {
    const out = applyHtmlAddons('<html><head></head><body></body></html>', '/', {
      snippets: { enabled: true, items: [{ name: 'c', phase: 'head', path: '/*', html: '<script>__legacyGrant</script>' }] },
      tags: { enabled: true, consent_required: true, tools: [] },
    });
    expect(out.indexOf('__legacyGrant')).toBeLessThan(out.indexOf('T.grant=function'));
  });

  it('applyHtmlAddons combines tags and snippets', () => {
    const html = '<html><head></head><body><form></form></body></html>';
    const out = applyHtmlAddons(html, '/', {
      snippets: { enabled: true, items: [{ name: 'n', phase: 'head', path: '/*', html: '<!--s-->' }] },
      tags: { enabled: true, tools: [{ name: 't', src: 'https://cdn.example/t.js' }] },
      turnstile: { enabled: true, site_key: 'sk', secret_key: 'sec', mode: 'forms' },
      forms: { enabled: true, endpoints: [] },
    });
    expect(out).toContain('<!--s-->');
    expect(out).toContain('cdn.example/t.js');
    expect(out).toContain('cf-turnstile');
  });
});


describe('rate limit and waiting room counters', () => {
  const original = (globalThis as { caches?: unknown }).caches;
  // caches.default that honours max-age against the (fake) clock.
  const store = new Map<string, { body: string; expires: number }>();

  beforeEach(() => {
    store.clear();
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-26T00:00:00Z'));
    (globalThis as { caches?: unknown }).caches = {
      default: {
        match: async (key: URL) => {
          const hit = store.get(String(key));
          return hit && hit.expires > Date.now() ? new Response(hit.body) : undefined;
        },
        put: async (key: URL, response: Response) => {
          const maxAge = Number(/max-age=(\d+)/.exec(response.headers.get('Cache-Control') ?? '')?.[1] ?? 0);
          store.set(String(key), { body: await response.text(), expires: Date.now() + maxAge * 1000 });
        },
      },
    };
  });
  afterEach(() => {
    vi.useRealTimers();
    (globalThis as { caches?: unknown }).caches = original;
  });

  const visit = (host: EdgeAddonsHostEntry, hostname = 'a.test', path = '/api/x') =>
    runEarlyAddons(new Request(`https://${hostname}${path}`, { headers: { 'cf-connecting-ip': '1.2.3.4' } }), path, host);
  const limited = (siteId: string, limit = 1): EdgeAddonsHostEntry => ({
    site_id: siteId,
    rate_limit: { enabled: true, rules: [{ path: '/api/*', limit, window_seconds: 60, action: 'block' }] },
  });

  it('keeps one app\'s rate-limit count away from another app', async () => {
    expect(await visit(limited('site-a'))).toBeNull();
    expect((await visit(limited('site-a')))?.status).toBe(429);
    expect(await visit(limited('site-b'), 'b.test')).toBeNull();
  });

  it('resets a rate-limit count when its window ends, even under steady traffic', async () => {
    for (let i = 0; i < 6; i++) {
      expect(await visit(limited('site-a', 2))).toBeNull();
      vi.advanceTimersByTime(50_000);
    }
  });

  it('counts every matching rule, not only the first', async () => {
    const host: EdgeAddonsHostEntry = {
      site_id: 'site-a',
      rate_limit: {
        enabled: true,
        rules: [
          { path: '/*', limit: 100, window_seconds: 60, action: 'block' },
          { path: '/api/*', limit: 1, window_seconds: 60, action: 'block' },
        ],
      },
    };
    expect(await visit(host)).toBeNull();
    expect((await visit(host))?.status).toBe(429);
  });

  const room = (siteId: string, total: number): EdgeAddonsHostEntry => ({
    site_id: siteId,
    waiting_room: { enabled: true, total_active_users: total, new_users_per_minute: 100, session_duration_minutes: 1, paths: [] },
  });

  it('keeps one app\'s waiting room away from another app', async () => {
    expect(await visit(room('site-a', 1))).toBeNull();
    expect((await visit(room('site-a', 1)))?.status).toBe(503);
    expect(await visit(room('site-b', 1), 'b.test')).toBeNull();
  });

  it('lets admitted visitors age out of the active count', async () => {
    // Admits at 0s, 50s and 100s: with one-minute sessions the first two have
    // expired by 150s, so there is room again even though admits never stopped.
    for (const at of [0, 50_000, 100_000]) {
      vi.setSystemTime(new Date(Date.parse('2026-09-26T00:00:00Z') + at));
      expect(await visit(room('site-a', 3))).toBeNull();
    }
    vi.setSystemTime(new Date(Date.parse('2026-09-26T00:00:00Z') + 150_000));
    expect(await visit(room('site-a', 3))).toBeNull();
  });

  it('flags an admitted request for the session cookie', async () => {
    const request = new Request('https://a.test/', { headers: { 'cf-connecting-ip': '1.2.3.4' } });
    const host = room('site-a', 5);
    expect(await runEarlyAddons(request, '/', host)).toBeNull();
    expect(waitingRoomAdmitCookie(request, host.waiting_room)).toContain('dply_wr=1');
  });
});
