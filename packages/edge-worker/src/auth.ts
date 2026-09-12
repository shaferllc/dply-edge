/**
 * Preview access gate for non-production Edge hostnames (PR previews,
 * per-deploy aliases). Production hostnames skip the gate entirely.
 */

export const ACCESS_COOKIE_NAME = '__dply_edge_access';
export const ACCESS_POST_PATH = '/__dply/access';
export const ACCESS_COMPLETE_PATH = '/__dply/access/complete';

export interface AccessGateConfig {
  mode: 'password' | 'dply_account';
  site_id: string;
  cookie_secret: string;
  app_url?: string;
  password_salt?: string;
  password_verifier?: string;
  allowed_emails?: string[];
  account_login_url?: string;
}

export interface HostMapEntryWithAccess {
  is_production?: boolean;
  access_gate?: AccessGateConfig;
}

interface AccessJwtPayload {
  site_id: string;
  hostname: string;
  email?: string;
  exp: number;
}

function base64UrlEncode(bytes: Uint8Array): string {
  let binary = '';
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }

  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
}

function base64UrlDecode(value: string): Uint8Array {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(value.length / 4) * 4, '=');
  const binary = atob(padded);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }

  return bytes;
}

async function sha256Hex(input: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(input));
  return [...new Uint8Array(digest)].map((b) => b.toString(16).padStart(2, '0')).join('');
}

async function hmacSha256(message: string, secret: string): Promise<string> {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign'],
  );
  const signature = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(message));

  return base64UrlEncode(new Uint8Array(signature));
}

function parseJwt(token: string): { header: string; payload: string; signature: string } | null {
  const parts = token.split('.');
  if (parts.length !== 3) {
    return null;
  }

  return { header: parts[0], payload: parts[1], signature: parts[2] };
}

async function verifyJwt(token: string, secret: string, hostname: string, siteId: string): Promise<AccessJwtPayload | null> {
  const parsed = parseJwt(token);
  if (parsed === null) {
    return null;
  }

  const expected = await hmacSha256(`${parsed.header}.${parsed.payload}`, secret);
  if (expected !== parsed.signature) {
    return null;
  }

  try {
    const json = new TextDecoder().decode(base64UrlDecode(parsed.payload));
    const payload = JSON.parse(json) as AccessJwtPayload;
    if (payload.site_id !== siteId || payload.hostname !== hostname.toLowerCase()) {
      return null;
    }
    if (typeof payload.exp !== 'number' || payload.exp < Math.floor(Date.now() / 1000)) {
      return null;
    }

    return payload;
  } catch {
    return null;
  }
}

function readCookie(request: Request, name: string): string | null {
  const header = request.headers.get('Cookie') ?? '';
  for (const part of header.split(';')) {
    const trimmed = part.trim();
    if (trimmed.startsWith(`${name}=`)) {
      return decodeURIComponent(trimmed.slice(name.length + 1));
    }
  }

  return null;
}

function accessCookieOptions(maxAgeSeconds: number): string {
  return `Path=/; HttpOnly; Secure; SameSite=Lax; Max-Age=${maxAgeSeconds}`;
}

function renderGateHtml(gate: AccessGateConfig, hostname: string, error?: string): string {
  const errorBlock = error
    ? `<p role="alert" style="color:#b91c1c;margin:0 0 1rem;">${escapeHtml(error)}</p>`
    : '';

  const passwordForm =
    gate.mode === 'password'
      ? `<form method="post" action="${ACCESS_POST_PATH}" style="margin-top:1.25rem;display:grid;gap:.75rem;">
  <label style="display:grid;gap:.35rem;font-size:.875rem;">
    <span>Password</span>
    <input type="password" name="password" autocomplete="current-password" required
      style="border:1px solid #d4d4d8;border-radius:.5rem;padding:.65rem .75rem;font-size:1rem;" />
  </label>
  <button type="submit"
    style="border:0;border-radius:.5rem;background:#111827;color:#fff;padding:.65rem 1rem;font-weight:600;cursor:pointer;">
    Continue
  </button>
</form>`
      : '';

  const accountBlock =
    gate.mode === 'dply_account'
      ? `<p style="margin-top:1.25rem;">
  <a href="${escapeHtml(buildAccountLoginUrl(gate, hostname))}"
    style="display:inline-block;border-radius:.5rem;background:#111827;color:#fff;padding:.65rem 1rem;font-weight:600;text-decoration:none;">
    Sign in with Dply
  </a>
</p>`
      : '';

  return `<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Preview access</title>
<style>
  :root { color-scheme: light dark; }
  body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f6f5ef; color: #111827; }
  @media (prefers-color-scheme: dark) { body { background: #111; color: #f6f5ef; } }
  main { width: min(24rem, calc(100vw - 2rem)); padding: 2rem; border-radius: 1rem; background: rgba(255,255,255,.9); box-shadow: 0 10px 30px rgba(0,0,0,.08); }
  @media (prefers-color-scheme: dark) { main { background: #1a1a1a; } }
  h1 { font-size: 1.25rem; margin: 0 0 .5rem; }
  p { margin: 0; color: #52525b; line-height: 1.5; }
  @media (prefers-color-scheme: dark) { p { color: #a1a1aa; } }
  code { font-family: ui-monospace, monospace; font-size: .85em; }
</style>
</head>
<body>
<main>
  <h1>Preview protected</h1>
  <p>This URL (<code>${escapeHtml(hostname)}</code>) requires access. Production traffic on the live site is unaffected.</p>
  ${errorBlock}
  ${passwordForm}
  ${accountBlock}
</main>
</body>
</html>`;
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function buildAccountLoginUrl(gate: AccessGateConfig, hostname: string): string {
  const base = gate.account_login_url ?? `${gate.app_url ?? ''}/applications/sites/${gate.site_id}/preview-access`;
  const url = new URL(base);
  url.searchParams.set('hostname', hostname);
  url.searchParams.set('return', `https://${hostname}/`);

  return url.toString();
}

function gateApplies(entry: HostMapEntryWithAccess): entry is HostMapEntryWithAccess & { access_gate: AccessGateConfig } {
  if (entry.is_production) {
    return false;
  }

  const gate = entry.access_gate;
  if (!gate) {
    return false;
  }

  return gate.mode === 'password' || gate.mode === 'dply_account';
}

async function hasValidAccessCookie(
  request: Request,
  hostname: string,
  gate: AccessGateConfig,
): Promise<boolean> {
  const token = readCookie(request, ACCESS_COOKIE_NAME);
  if (!token) {
    return false;
  }

  const payload = await verifyJwt(token, gate.cookie_secret, hostname, gate.site_id);
  if (payload === null) {
    return false;
  }

  if (gate.mode === 'dply_account' && Array.isArray(gate.allowed_emails) && gate.allowed_emails.length > 0) {
    const email = (payload.email ?? '').toLowerCase();
    if (!gate.allowed_emails.includes(email)) {
      return false;
    }
  }

  return true;
}

/**
 * Returns a Response when the request should stop at the gate, or null
 * when the Worker should continue normal delivery.
 */
export async function handleAccessGate(
  request: Request,
  url: URL,
  hostEntry: HostMapEntryWithAccess,
): Promise<Response | null> {
  if (!gateApplies(hostEntry)) {
    return null;
  }

  const gate = hostEntry.access_gate;
  const hostname = url.hostname.toLowerCase();

  if (url.pathname === ACCESS_COMPLETE_PATH && request.method === 'GET') {
    const token = url.searchParams.get('token') ?? '';
    const payload = await verifyJwt(token, gate.cookie_secret, hostname, gate.site_id);
    if (payload === null) {
      return new Response('Invalid or expired access token.', { status: 403 });
    }

    const headers = new Headers({
      Location: '/',
      'Set-Cookie': `${ACCESS_COOKIE_NAME}=${encodeURIComponent(token)}; ${accessCookieOptions(86400)}`,
    });

    return new Response(null, { status: 302, headers });
  }

  if (url.pathname === ACCESS_POST_PATH && request.method === 'POST') {
    if (gate.mode !== 'password') {
      return new Response('Method not allowed.', { status: 405 });
    }

    const form = await request.formData();
    const password = String(form.get('password') ?? '');
    const salt = gate.password_salt ?? '';
    const verifier = gate.password_verifier ?? '';
    const candidate = await sha256Hex(`${salt}${password}`);

    if (candidate !== verifier) {
      return new Response(renderGateHtml(gate, hostname, 'Incorrect password.'), {
        status: 401,
        headers: { 'Content-Type': 'text/html; charset=utf-8' },
      });
    }

    const expiresAt = Math.floor(Date.now() / 1000) + 86400;
    const header = base64UrlEncode(new TextEncoder().encode(JSON.stringify({ alg: 'HS256', typ: 'JWT' })));
    const body = base64UrlEncode(
      new TextEncoder().encode(
        JSON.stringify({
          site_id: gate.site_id,
          hostname,
          exp: expiresAt,
        }),
      ),
    );
    const signature = await hmacSha256(`${header}.${body}`, gate.cookie_secret);
    const token = `${header}.${body}.${signature}`;

    const headers = new Headers({
      Location: '/',
      'Set-Cookie': `${ACCESS_COOKIE_NAME}=${encodeURIComponent(token)}; ${accessCookieOptions(86400)}`,
    });

    return new Response(null, { status: 302, headers });
  }

  if (await hasValidAccessCookie(request, hostname, gate)) {
    return null;
  }

  return new Response(renderGateHtml(gate, hostname), {
    status: 401,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Cache-Control': 'no-store',
    },
  });
}
