import { describe, expect, it } from 'vitest';
import {
  ACCESS_COOKIE_NAME,
  ACCESS_COMPLETE_PATH,
  ACCESS_POST_PATH,
  handleAccessGate,
  type AccessGateConfig,
} from './auth';

const gate: AccessGateConfig = {
  mode: 'password',
  site_id: 'site-1',
  cookie_secret: 'test-cookie-secret',
  password_salt: 'abc123',
  password_verifier: 'a7c163cce5ead60357b268f24a09d3fa6f902d8382e13cf9b11157bb8d8366b0',
};

const hostEntry = {
  is_production: false,
  access_gate: gate,
};

describe('handleAccessGate', () => {
  it('shows auth page for a protected hostname without a cookie', async () => {
    const response = await handleAccessGate(
      new Request('https://preview.example.test/'),
      new URL('https://preview.example.test/'),
      hostEntry,
    );

    expect(response).not.toBeNull();
    expect(response?.status).toBe(401);
    expect(await response?.text()).toContain('Site protected');
  });

  it('gates the live hostname when an access rule is set', async () => {
    const response = await handleAccessGate(
      new Request('https://app.example.test/'),
      new URL('https://app.example.test/'),
      { is_production: true, access_gate: gate },
    );

    expect(response?.status).toBe(401);
    expect(await response?.text()).toContain('Site protected');
  });

  it('accepts the shared password and sets an access cookie', async () => {
    const response = await handleAccessGate(
      new Request('https://preview.example.test'.concat(ACCESS_POST_PATH), {
        method: 'POST',
        body: new URLSearchParams({ password: 'secret-preview' }),
      }),
      new URL('https://preview.example.test'.concat(ACCESS_POST_PATH)),
      hostEntry,
    );

    expect(response?.status).toBe(302);
    expect(response?.headers.get('Location')).toBe('/');
    expect(response?.headers.get('Set-Cookie')).toContain(`${ACCESS_COOKIE_NAME}=`);
  });

  it('allows requests with a valid access cookie', async () => {
    const postResponse = await handleAccessGate(
      new Request('https://preview.example.test'.concat(ACCESS_POST_PATH), {
        method: 'POST',
        body: new URLSearchParams({ password: 'secret-preview' }),
      }),
      new URL('https://preview.example.test'.concat(ACCESS_POST_PATH)),
      hostEntry,
    );

    const cookieHeader = postResponse?.headers.get('Set-Cookie') ?? '';
    const token = decodeURIComponent(cookieHeader.split(`${ACCESS_COOKIE_NAME}=`)[1]?.split(';')[0] ?? '');

    const response = await handleAccessGate(
      new Request('https://preview.example.test/', {
        headers: { Cookie: `${ACCESS_COOKIE_NAME}=${encodeURIComponent(token)}` },
      }),
      new URL('https://preview.example.test/'),
      hostEntry,
    );

    expect(response).toBeNull();
  });

  it('sets cookie from complete redirect token', async () => {
    const postResponse = await handleAccessGate(
      new Request('https://preview.example.test'.concat(ACCESS_POST_PATH), {
        method: 'POST',
        body: new URLSearchParams({ password: 'secret-preview' }),
      }),
      new URL('https://preview.example.test'.concat(ACCESS_POST_PATH)),
      hostEntry,
    );
    const cookieHeader = postResponse?.headers.get('Set-Cookie') ?? '';
    const token = decodeURIComponent(cookieHeader.split(`${ACCESS_COOKIE_NAME}=`)[1]?.split(';')[0] ?? '');

    const completeResponse = await handleAccessGate(
      new Request(`https://preview.example.test${ACCESS_COMPLETE_PATH}?token=${encodeURIComponent(token)}`),
      new URL(`https://preview.example.test${ACCESS_COMPLETE_PATH}?token=${encodeURIComponent(token)}`),
      hostEntry,
    );

    expect(completeResponse?.status).toBe(302);
    expect(completeResponse?.headers.get('Set-Cookie')).toContain(`${ACCESS_COOKIE_NAME}=`);
  });
});
