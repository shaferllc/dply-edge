/**
 * Edge product add-ons enforced in the platform Worker (managed delivery).
 * Config arrives flattened on HostMapEntry from EdgeHostMapAddons.
 */

export interface TurnstileConfig {
  enabled: boolean;
  site_key: string;
  secret_key: string;
  mode: 'forms' | 'all';
  paths?: string[];
}

export interface RateLimitRule {
  path: string;
  limit: number;
  window_seconds: number;
  action: 'block' | 'challenge';
}

export interface RateLimitConfig {
  enabled: boolean;
  rules: RateLimitRule[];
}

export interface FormEndpoint {
  path: string;
  to_email: string;
  honeypot: string;
  require_turnstile: boolean;
}

export interface FormsConfig {
  enabled: boolean;
  endpoints: FormEndpoint[];
  ingest_url?: string | null;
  ingest_key?: string;
}

export interface WaitingRoomConfig {
  enabled: boolean;
  total_active_users: number;
  new_users_per_minute: number;
  session_duration_minutes: number;
  paths: string[];
}

export interface SnippetItem {
  name: string;
  phase: 'head' | 'body';
  html: string;
  path: string;
}

export interface SnippetsConfig {
  enabled: boolean;
  items: SnippetItem[];
}

export interface TagTool {
  name: string;
  /** ga4|gtm|meta|clarity|hotjar|plausible|custom — absent means custom (src only). */
  vendor?: string;
  id?: string;
  src?: string;
  async?: boolean;
  /** Consent purpose; `necessary` is never held back. */
  purpose?: string;
  /** Page trigger, same syntax as snippets. */
  path?: string;
}

export interface TagsConfig {
  enabled: boolean;
  consent_required?: boolean;
  tools: TagTool[];
}

export interface EdgeAddonsHostEntry {
  site_id?: string;
  turnstile?: TurnstileConfig;
  rate_limit?: RateLimitConfig;
  forms?: FormsConfig;
  waiting_room?: WaitingRoomConfig;
  snippets?: SnippetsConfig;
  tags?: TagsConfig;
}

function pathMatches(pattern: string, pathname: string): boolean {
  if (pattern === '/*' || pattern === '*') return true;
  if (pattern.endsWith('/*')) {
    const prefix = pattern.slice(0, -1); // keep trailing /
    return pathname === pattern.slice(0, -2) || pathname.startsWith(prefix);
  }
  return pathname === pattern;
}

function clientIp(request: Request): string {
  return (
    request.headers.get('cf-connecting-ip') ||
    request.headers.get('x-forwarded-for')?.split(',')[0]?.trim() ||
    '0.0.0.0'
  );
}

async function verifyTurnstile(token: string, secret: string, ip: string): Promise<boolean> {
  if (!token || !secret) return false;
  try {
    const body = new URLSearchParams();
    body.set('secret', secret);
    body.set('response', token);
    body.set('remoteip', ip);
    const res = await fetch('https://challenges.cloudflare.com/turnstile/v0/siteverify', {
      method: 'POST',
      body,
    });
    if (!res.ok) return false;
    const data = (await res.json()) as { success?: boolean };
    return data.success === true;
  } catch {
    return false;
  }
}

export function injectTurnstileWidget(html: string, siteKey: string): string {
  if (!html || !siteKey) return html;
  const widget = `<div class="cf-turnstile" data-sitekey="${escapeAttr(siteKey)}"></div>`;
  const script = `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>`;
  let out = html;
  if (!/challenges\.cloudflare\.com\/turnstile/i.test(out)) {
    out = out.includes('</body>') ? out.replace(/<\/body>/i, `${script}</body>`) : out + script;
  }
  if (!/class=["']cf-turnstile["']/.test(out) && out.includes('</form>')) {
    out = out.replace(/<\/form>/i, `${widget}</form>`);
  }
  return out;
}

function escapeAttr(value: string): string {
  return value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
}

export async function enforceRateLimit(
  request: Request,
  pathname: string,
  config: RateLimitConfig | undefined,
  turnstile: TurnstileConfig | undefined,
): Promise<Response | null> {
  if (!config?.enabled || !config.rules?.length) return null;
  const ip = clientIp(request);
  for (const rule of config.rules) {
    if (!pathMatches(rule.path, pathname)) continue;
    const key = `rl:${ip}:${rule.path}:${rule.window_seconds}`;
    const allowed = await bumpCacheCounter(key, rule.limit, rule.window_seconds);
    if (allowed) continue;
    if (rule.action === 'challenge' && turnstile?.enabled && turnstile.secret_key) {
      const token =
        request.headers.get('cf-turnstile-response') ||
        new URL(request.url).searchParams.get('cf-turnstile-response') ||
        '';
      if (token && (await verifyTurnstile(token, turnstile.secret_key, ip))) {
        continue;
      }
      return challengeHtml(turnstile.site_key);
    }
    return new Response('Too Many Requests', {
      status: 429,
      headers: { 'Retry-After': String(rule.window_seconds), 'Content-Type': 'text/plain; charset=utf-8' },
    });
  }
  return null;
}

async function bumpCacheCounter(key: string, limit: number, windowSeconds: number): Promise<boolean> {
  try {
    const cache = caches.default;
    const url = new URL(`https://edge-rate-limit.dply.internal/${encodeURIComponent(key)}`);
    const hit = await cache.match(url);
    let count = 0;
    if (hit) {
      count = Number(await hit.text()) || 0;
    }
    count += 1;
    const response = new Response(String(count), {
      headers: { 'Cache-Control': `max-age=${windowSeconds}`, 'Content-Type': 'text/plain' },
    });
    await cache.put(url, response.clone());
    return count <= limit;
  } catch {
    return true; // fail open
  }
}

function challengeHtml(siteKey: string): Response {
  const body = `<!doctype html><html><head><meta charset="utf-8"><title>Verify</title>
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script></head>
<body style="font-family:system-ui;display:grid;place-items:center;min-height:100vh">
<form method="get"><div class="cf-turnstile" data-sitekey="${escapeAttr(siteKey)}" data-callback="ok"></div></form>
<script>function ok(t){const u=new URL(location.href);u.searchParams.set('cf-turnstile-response',t);location.href=u.toString()}</script>
</body></html>`;
  return new Response(body, { status: 429, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

export async function handleEdgeForm(
  request: Request,
  pathname: string,
  config: FormsConfig | undefined,
  turnstile: TurnstileConfig | undefined,
): Promise<Response | null> {
  if (!config?.enabled || request.method !== 'POST' || !config.endpoints?.length) return null;
  const endpoint = config.endpoints.find((e) => pathMatches(e.path, pathname));
  if (!endpoint) return null;

  const contentType = request.headers.get('content-type') || '';
  let fields: Record<string, string> = {};
  try {
    if (contentType.includes('application/json')) {
      const json = (await request.json()) as Record<string, unknown>;
      for (const [k, v] of Object.entries(json)) {
        if (typeof v === 'string' || typeof v === 'number' || typeof v === 'boolean') {
          fields[k] = String(v);
        }
      }
    } else {
      const form = await request.formData();
      form.forEach((v, k) => {
        if (typeof v === 'string') fields[k] = v;
      });
    }
  } catch {
    return jsonFormError('Invalid form body', 400);
  }

  const honeypot = endpoint.honeypot || 'company';
  if ((fields[honeypot] || '').trim() !== '') {
    return jsonFormOk(); // bot absorbed
  }

  if (endpoint.require_turnstile) {
    const token = fields['cf-turnstile-response'] || fields['turnstile_token'] || '';
    const secret = turnstile?.secret_key || '';
    if (!secret || !(await verifyTurnstile(token, secret, clientIp(request)))) {
      return jsonFormError('Bot check failed', 403);
    }
  }

  const wantsHtml = (request.headers.get('accept') || '').includes('text/html');
  if (wantsHtml) {
    return new Response('<!doctype html><html><body><p>Thanks — we received your message.</p></body></html>', {
      status: 200,
      headers: { 'Content-Type': 'text/html; charset=utf-8' },
    });
  }
  return jsonFormOk();
}

function jsonFormOk(): Response {
  return new Response(JSON.stringify({ ok: true }), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  });
}

function jsonFormError(message: string, status: number): Response {
  return new Response(JSON.stringify({ ok: false, error: message }), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

const WR_COOKIE = 'dply_wr';

export async function enforceWaitingRoom(
  request: Request,
  pathname: string,
  config: WaitingRoomConfig | undefined,
): Promise<Response | null> {
  if (!config?.enabled) return null;
  const paths = config.paths?.length ? config.paths : ['/*'];
  if (!paths.some((p) => pathMatches(p, pathname))) return null;

  const cookieHeader = request.headers.get('cookie') || '';
  const admitted = cookieHeader.split(';').some((c) => c.trim().startsWith(`${WR_COOKIE}=1`));
  if (admitted) return null;

  const ip = clientIp(request);
  const minuteKey = `wr:admit:${Math.floor(Date.now() / 60000)}`;
  const activeKey = `wr:active`;
  const admittedThisMinute = await readCacheCount(minuteKey);
  const activeApprox = await readCacheCount(activeKey);

  if (
    admittedThisMinute < config.new_users_per_minute &&
    activeApprox < config.total_active_users
  ) {
    await bumpCacheCounter(minuteKey, config.new_users_per_minute + 1, 120);
    await bumpCacheCounter(activeKey, config.total_active_users + 1, config.session_duration_minutes * 60);
    // Let request through; caller stamps cookie via waitingRoomAdmitHeaders
    (request as Request & { __dplyWaitingRoomAdmit?: boolean }).__dplyWaitingRoomAdmit = true;
    return null;
  }

  const retry = Math.max(5, Math.ceil(60 / Math.max(1, config.new_users_per_minute)));
  const html = `<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="${retry}">
<title>You’re in line</title></head>
<body style="font-family:system-ui;display:grid;place-items:center;min-height:100vh;background:#f6f5ef">
<main style="text-align:center;max-width:28rem;padding:2rem">
<h1>You’re in line</h1>
<p>This site is at capacity. We’ll refresh automatically.</p>
</main></body></html>`;
  return new Response(html, {
    status: 503,
    headers: {
      'Content-Type': 'text/html; charset=utf-8',
      'Retry-After': String(retry),
      'Cache-Control': 'no-store',
    },
  });
}

async function readCacheCount(key: string): Promise<number> {
  try {
    const cache = caches.default;
    const url = new URL(`https://edge-rate-limit.dply.internal/${encodeURIComponent(key)}`);
    const hit = await cache.match(url);
    if (!hit) return 0;
    return Number(await hit.text()) || 0;
  } catch {
    return 0;
  }
}

export function waitingRoomAdmitCookie(request: Request, config: WaitingRoomConfig | undefined): string | null {
  if (!config?.enabled) return null;
  const flagged = (request as Request & { __dplyWaitingRoomAdmit?: boolean }).__dplyWaitingRoomAdmit;
  if (!flagged) return null;
  const maxAge = Math.max(60, (config.session_duration_minutes || 30) * 60);
  return `${WR_COOKIE}=1; Path=/; Max-Age=${maxAge}; HttpOnly; Secure; SameSite=Lax`;
}

export function injectSnippets(html: string, pathname: string, config: SnippetsConfig | undefined): string {
  if (!config?.enabled || !config.items?.length || !html) return html;
  let out = html;
  for (const item of config.items) {
    if (!pathMatches(item.path || '/*', pathname)) continue;
    if (item.phase === 'head' && out.includes('</head>')) {
      out = out.replace(/<\/head>/i, `${item.html}</head>`);
    } else if (item.phase === 'body' && out.includes('</body>')) {
      out = out.replace(/<\/body>/i, `${item.html}</body>`);
    }
  }
  return out;
}

interface TagLoad {
  inline?: string;
  src?: string;
  attrs?: Record<string, string>;
}

/** JSON string literal safe inside an inline <script>. */
function jsString(value: string): string {
  return JSON.stringify(value).replace(/</g, '\\u003c');
}

// ids are pattern-checked by EdgeTagVendors (PHP) before publish; jsString /
// encodeURIComponent keep a bad KV entry from breaking out anyway.
const TAG_VENDORS: Partial<Record<string, (id: string) => TagLoad>> = {
  ga4: (id) => ({
    inline: `window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments)};gtag('js',new Date());gtag('config',${jsString(id)});`,
    src: `https://www.googletagmanager.com/gtag/js?id=${encodeURIComponent(id)}`,
  }),
  gtm: (id) => ({
    inline: `window.dataLayer=window.dataLayer||[];dataLayer.push({'gtm.start':Date.now(),event:'gtm.js'});`,
    src: `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(id)}`,
  }),
  meta: (id) => ({
    inline: `!function(f){if(f.fbq)return;var n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[]}(window);fbq('init',${jsString(id)});fbq('track','PageView');`,
    src: 'https://connect.facebook.net/en_US/fbevents.js',
  }),
  clarity: (id) => ({
    inline: `window.clarity=window.clarity||function(){(clarity.q=clarity.q||[]).push(arguments)};`,
    src: `https://www.clarity.ms/tag/${encodeURIComponent(id)}`,
  }),
  hotjar: (id) => ({
    inline: `window.hj=window.hj||function(){(hj.q=hj.q||[]).push(arguments)};window._hjSettings={hjid:Number(${jsString(id)}),hjsv:6};`,
    src: `https://static.hotjar.com/c/hotjar-${encodeURIComponent(id)}.js?sv=6`,
  }),
  plausible: (id) => ({
    inline: `window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)};`,
    src: 'https://plausible.io/js/script.js',
    attrs: { 'data-domain': id },
  }),
};

function tagLoad(tool: TagTool): TagLoad | null {
  const vendor = tool.vendor || 'custom';
  if (vendor === 'custom') {
    return typeof tool.src === 'string' && tool.src.startsWith('https://') ? { src: tool.src } : null;
  }
  const build = TAG_VENDORS[vendor];
  return build && typeof tool.id === 'string' && tool.id !== '' ? build(tool.id) : null;
}

function renderTagLoad(load: TagLoad, async: boolean): string {
  let out = load.inline ? `<script>${load.inline}</script>` : '';
  if (load.src) {
    const attrs = Object.entries(load.attrs ?? {})
      .map(([k, v]) => ` ${k}="${escapeAttr(v)}"`)
      .join('');
    out += `<script src="${escapeAttr(load.src)}"${async ? ' async' : ''}${attrs}></script>`;
  }
  return out;
}

// window.__dplyTags: consent (bool), purposes, grant(purposes?), revoke(), track(event, props).
// Held tools load as soon as their purpose is granted. localStorage
// dply_tag_consent is '1' (everything) or a JSON list of purposes.
const TAG_RUNTIME = `(function(w,d,H){var T=w.__dplyTags=w.__dplyTags||{},mem=null;
function read(){if(mem)return mem;try{var v=localStorage.getItem('dply_tag_consent');return v==='1'?['analytics','marketing']:v?JSON.parse(v):[]}catch(e){return[]}}
function load(t){if(t.i){var x=d.createElement('script');x.text=t.i;d.head.appendChild(x)}if(t.s){var s=d.createElement('script');s.src=t.s;s.async=true;for(var k in t.a||{})s.setAttribute(k,t.a[k]);d.head.appendChild(s)}}
function flush(){var g=read();T.purposes=g;T.consent=g.length>0;H=H.filter(function(t){if(g.indexOf(t.p)<0)return true;load(t);return false})}
T.grant=function(p){mem=p||['analytics','marketing'];try{localStorage.setItem('dply_tag_consent',p?JSON.stringify(p):'1')}catch(e){}flush()};
T.revoke=function(){mem=[];try{localStorage.removeItem('dply_tag_consent')}catch(e){}flush()};
T.track=function(n,p){p=p||{};if(w.gtag)gtag('event',n,p);if(w.dataLayer)dataLayer.push(Object.assign({event:n},p));if(w.fbq)fbq('trackCustom',n,p);if(w.plausible)plausible(n,{props:p});if(w.clarity)clarity('event',n);if(w.hj)hj('event',n)};
flush()})(window,document,`;

export function injectTags(html: string, config: TagsConfig | undefined, pathname = '/'): string {
  if (!config?.enabled || !html) return html;

  const tools = Array.isArray(config.tools) ? config.tools : [];
  const held: Array<{ p: string; i?: string; s?: string; a?: Record<string, string> }> = [];
  let scripts = '';
  for (const tool of tools) {
    if (!pathMatches(tool.path || '/*', pathname)) continue;
    const load = tagLoad(tool);
    if (!load) continue;
    const purpose = tool.purpose || 'analytics';
    if (config.consent_required && purpose !== 'necessary') {
      held.push({ p: purpose, i: load.inline, s: load.src, a: load.attrs });
    } else {
      scripts += renderTagLoad(load, tool.async !== false);
    }
  }

  // Runtime ships first so track()/grant() exist before any tool runs. With
  // consent off and nothing held it still provides track().
  const runtime = `<script>${TAG_RUNTIME}${JSON.stringify(held).replace(/</g, '\\u003c')});</script>`;
  const block = config.consent_required || scripts !== '' ? runtime + scripts : '';
  if (block === '') return html;

  if (html.includes('</head>')) {
    // Function replacer: `$&`-style sequences in a tag URL must stay literal.
    return html.replace(/<\/head>/i, () => `${block}</head>`);
  }
  return html + block;
}

export async function runEarlyAddons(
  request: Request,
  pathname: string,
  host: EdgeAddonsHostEntry,
): Promise<Response | null> {
  const waiting = await enforceWaitingRoom(request, pathname, host.waiting_room);
  if (waiting) return waiting;

  const form = await handleEdgeForm(request, pathname, host.forms, host.turnstile);
  if (form) return form;

  const limited = await enforceRateLimit(request, pathname, host.rate_limit, host.turnstile);
  if (limited) return limited;

  return null;
}

/** A one-line deploy id before `</body>`, when the site has the footer enabled. */
export function injectDeployFooter(html: string, deploymentId: string): string {
  const id = deploymentId.trim().replace(/[^A-Za-z0-9_-]/g, '');
  if (id === '' || html.includes('data-dply-deploy=')) return html;

  const tag = `<p data-dply-deploy="${id}" style="margin:1rem 0 0;padding:0.5rem 1rem;text-align:center;font:12px/1.4 ui-monospace,monospace;color:#6b7280">${id}</p>`;
  const idx = html.toLowerCase().lastIndexOf('</body>');
  if (idx === -1) return html + tag;

  return html.slice(0, idx) + tag + html.slice(idx);
}

export function applyHtmlAddons(html: string, pathname: string, host: EdgeAddonsHostEntry): string {
  let out = html;
  out = injectSnippets(out, pathname, host.snippets);
  out = injectTags(out, host.tags, pathname);
  if (host.turnstile?.enabled && host.turnstile.mode === 'all' && host.turnstile.site_key) {
    out = injectTurnstileWidget(out, host.turnstile.site_key);
  } else if (
    host.turnstile?.enabled &&
    host.turnstile.mode === 'forms' &&
    host.turnstile.site_key &&
    host.forms?.enabled
  ) {
    out = injectTurnstileWidget(out, host.turnstile.site_key);
  }
  return out;
}
