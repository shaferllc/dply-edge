import { describe, expect, it } from 'vitest';
import { applyHtmlAddons, injectDeployFooter, injectSnippets, injectTags, injectTurnstileWidget } from './addons';

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
