import { createHmac } from 'node:crypto';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { handleEdgeForm, type FormsConfig } from './addons';

const config: FormsConfig = {
  enabled: true,
  endpoints: [{ path: '/contact', to_email: 'me@example.com', honeypot: 'company', require_turnstile: false }],
  ingest_url: 'https://dply.test/hooks/edge/site-1/forms',
  ingest_key: 'per-site-key',
};

function post(body: Record<string, string>): Request {
  return new Request('https://app.example/contact', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

describe('edge forms delivery', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('forwards screened fields to the signed ingest', async () => {
    const fetchMock = vi.fn(async () => new Response('{"ok":true}', { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const res = await handleEdgeForm(
      post({ name: 'Ada', company: '', turnstile_token: 't' }),
      '/contact',
      config,
      undefined,
    );

    expect(res?.status).toBe(200);
    expect(fetchMock).toHaveBeenCalledOnce();
    const [url, init] = fetchMock.mock.calls[0] as unknown as [string, RequestInit];
    expect(url).toBe(config.ingest_url);
    const body = String(init.body);
    const sent = JSON.parse(body);
    expect(sent.path).toBe('/contact');
    expect(sent.fields).toEqual({ name: 'Ada' });
    expect(sent.to_email).toBeUndefined();
    const expected = createHmac('sha256', 'per-site-key').update(body).digest('hex');
    expect((init.headers as Record<string, string>)['X-Dply-Edge-Form-Signature']).toBe(expected);
  });

  it('does not forward a honeypot hit', async () => {
    const fetchMock = vi.fn();
    vi.stubGlobal('fetch', fetchMock);
    const res = await handleEdgeForm(post({ name: 'bot', company: 'spam' }), '/contact', config, undefined);
    expect(res?.status).toBe(200);
    expect(fetchMock).not.toHaveBeenCalled();
  });

  it('answers 503 when delivery is not configured and 502 when ingest fails', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => new Response('', { status: 500 })));
    const unconfigured = await handleEdgeForm(post({ name: 'Ada' }), '/contact', { ...config, ingest_key: '' }, undefined);
    expect(unconfigured?.status).toBe(503);
    const failed = await handleEdgeForm(post({ name: 'Ada' }), '/contact', config, undefined);
    expect(failed?.status).toBe(502);
  });
});
