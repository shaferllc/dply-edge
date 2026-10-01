// The delivery signature: an HS256 JWT the receiver checks with the current
// signing key, then the next one: iss "Upstash", sub the
// destination URL, body the base64url SHA-256 of the body, and exp/nbf.

const enc = new TextEncoder();

export function base64url(bytes: Uint8Array): string {
  let bin = '';
  for (const b of bytes) bin += String.fromCharCode(b);
  return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

export async function sha256Base64url(body: Uint8Array | string): Promise<string> {
  const data = typeof body === 'string' ? enc.encode(body) : body;
  return base64url(new Uint8Array(await crypto.subtle.digest('SHA-256', data)));
}

export async function signMessage(key: string, url: string, body: Uint8Array, now = Date.now()): Promise<string> {
  const iat = Math.floor(now / 1000);
  const header = base64url(enc.encode(JSON.stringify({ alg: 'HS256', typ: 'JWT' })));
  const payload = base64url(enc.encode(JSON.stringify({
    iss: 'Upstash',
    sub: url,
    iat,
    nbf: iat,
    exp: iat + 300,
    jti: 'jwt_' + crypto.randomUUID().replace(/-/g, ''),
    body: await sha256Base64url(body),
  })));
  const hmac = await crypto.subtle.importKey('raw', enc.encode(key), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  const sig = new Uint8Array(await crypto.subtle.sign('HMAC', hmac, enc.encode(`${header}.${payload}`)));
  return `${header}.${payload}.${base64url(sig)}`;
}

export async function sha256Hex(value: string): Promise<string> {
  const digest = new Uint8Array(await crypto.subtle.digest('SHA-256', enc.encode(value)));
  return [...digest].map((b) => b.toString(16).padStart(2, '0')).join('');
}
