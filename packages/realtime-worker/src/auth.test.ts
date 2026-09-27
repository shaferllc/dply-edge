import { describe, expect, it } from 'vitest';
import {
  buildPusherSignString,
  channelAuthToken,
  hmacSha256Hex,
  timingSafeEqual,
  verifyChannelAuth,
  verifyPublishRequest,
  type AppCredentials,
} from './auth';
import { md5 } from './md5';

const app: AppCredentials = { id: 'app1', key: 'pubkey', secret: 'topsecret', enabled: true };

describe('hmacSha256Hex', () => {
  it('matches a known HMAC-SHA256 vector', async () => {
    // HMAC_SHA256(key="key", msg="The quick brown fox jumps over the lazy dog")
    const sig = await hmacSha256Hex('key', 'The quick brown fox jumps over the lazy dog');
    expect(sig).toBe('f7bc83f430538424b13298e6aa6fb143ef4d59a14946175997479dbc2d1a3cd8');
  });
});

describe('timingSafeEqual', () => {
  it('compares equal and unequal strings', () => {
    expect(timingSafeEqual('abc', 'abc')).toBe(true);
    expect(timingSafeEqual('abc', 'abd')).toBe(false);
    expect(timingSafeEqual('abc', 'abcd')).toBe(false);
  });
});

describe('channel auth', () => {
  it('round-trips a private channel token', async () => {
    const token = await channelAuthToken(app.key, app.secret, '123.456', 'private-orders');
    expect(token.startsWith('pubkey:')).toBe(true);
    expect(await verifyChannelAuth(token, app.key, app.secret, '123.456', 'private-orders')).toBe(true);
  });

  it('round-trips a presence channel token with channel_data', async () => {
    const channelData = JSON.stringify({ user_id: '7', user_info: { name: 'Ada' } });
    const token = await channelAuthToken(app.key, app.secret, '123.456', 'presence-room', channelData);
    expect(
      await verifyChannelAuth(token, app.key, app.secret, '123.456', 'presence-room', channelData),
    ).toBe(true);
  });

  it('rejects a tampered token', async () => {
    const token = await channelAuthToken(app.key, app.secret, '123.456', 'private-orders');
    expect(await verifyChannelAuth(token, app.key, app.secret, '999.999', 'private-orders')).toBe(false);
    expect(await verifyChannelAuth(token, app.key, app.secret, '123.456', 'private-other')).toBe(false);
    expect(await verifyChannelAuth('', app.key, app.secret, '123.456', 'private-orders')).toBe(false);
  });

  it('matches the Pusher signing format (key:hexsig over socket:channel)', async () => {
    const expectedSig = await hmacSha256Hex(app.secret, '123.456:private-orders');
    const token = await channelAuthToken(app.key, app.secret, '123.456', 'private-orders');
    expect(token).toBe(`pubkey:${expectedSig}`);
  });
});

describe('verifyPublishRequest', () => {
  it('accepts dply header auth', async () => {
    const headers = new Headers({ 'X-Dply-Key': 'pubkey', 'X-Dply-Secret': 'topsecret' });
    const ok = await verifyPublishRequest(app, 'POST', '/apps/app1/events', new URLSearchParams(), headers, '{}');
    expect(ok).toBe(true);
  });

  it('rejects wrong header secret', async () => {
    const headers = new Headers({ 'X-Dply-Key': 'pubkey', 'X-Dply-Secret': 'nope' });
    const ok = await verifyPublishRequest(app, 'POST', '/apps/app1/events', new URLSearchParams(), headers, '{}');
    expect(ok).toBe(false);
  });

  it('accepts a valid Pusher REST signature', async () => {
    const body = JSON.stringify({ name: 'evt', channels: ['c1'], data: {} });
    const path = '/apps/app1/events';
    const ts = Math.floor(1_700_000_000).toString();
    const params = new URLSearchParams();
    params.set('auth_key', 'pubkey');
    params.set('auth_timestamp', ts);
    params.set('auth_version', '1.0');
    params.set('body_md5', md5(body));
    const sig = await hmacSha256Hex(app.secret, buildPusherSignString('POST', path, params));
    params.set('auth_signature', sig);

    const ok = await verifyPublishRequest(
      app,
      'POST',
      path,
      params,
      new Headers(),
      body,
      1_700_000_000 * 1000,
    );
    expect(ok).toBe(true);
  });

  it('rejects a Pusher signature with a tampered body', async () => {
    const body = JSON.stringify({ name: 'evt', channels: ['c1'], data: {} });
    const path = '/apps/app1/events';
    const params = new URLSearchParams();
    params.set('auth_key', 'pubkey');
    params.set('auth_timestamp', Math.floor(1_700_000_000).toString());
    params.set('body_md5', md5(body));
    const sig = await hmacSha256Hex(app.secret, buildPusherSignString('POST', path, params));
    params.set('auth_signature', sig);

    const ok = await verifyPublishRequest(
      app,
      'POST',
      path,
      params,
      new Headers(),
      '{"tampered":true}',
      1_700_000_000 * 1000,
    );
    expect(ok).toBe(false);
  });

  it('rejects a Pusher signature that leaves the body unsigned', async () => {
    const path = '/apps/app1/events';
    const params = new URLSearchParams();
    params.set('auth_key', 'pubkey');
    params.set('auth_timestamp', Math.floor(1_700_000_000).toString());
    const sig = await hmacSha256Hex(app.secret, buildPusherSignString('POST', path, params));
    params.set('auth_signature', sig);

    const ok = await verifyPublishRequest(app, 'POST', path, params, new Headers(), '{"name":"evil"}', 1_700_000_000 * 1000);
    expect(ok).toBe(false);
  });

  it('rejects a stale timestamp', async () => {
    const params = new URLSearchParams();
    params.set('auth_key', 'pubkey');
    params.set('auth_timestamp', '1');
    params.set('auth_signature', 'whatever');
    const ok = await verifyPublishRequest(app, 'POST', '/apps/app1/events', params, new Headers(), '{}');
    expect(ok).toBe(false);
  });
});
