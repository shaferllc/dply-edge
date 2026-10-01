// Small parsers for message headers and a 5-field cron, all in UTC.

/** "10s", "5m", "2h", "1d", or a bare number of seconds, to milliseconds. Null when malformed. */
export function durationMs(value: string | null): number | null {
  if (value === null || value.trim() === '') return null;
  const m = /^\s*(\d+)\s*(ms|s|m|h|d)?\s*$/i.exec(value);
  if (!m) return null;
  const n = Number(m[1]);
  const unit = (m[2] ?? 's').toLowerCase();
  return n * ({ ms: 1, s: 1_000, m: 60_000, h: 3_600_000, d: 86_400_000 } as Record<string, number>)[unit];
}

// ---- cron ----

type Field = Set<number>;
export type Cron = { minute: Field; hour: Field; day: Field; month: Field; weekday: Field; anyDay: boolean; anyWeekday: boolean };

const RANGES: [number, number][] = [[0, 59], [0, 23], [1, 31], [1, 12], [0, 6]];
const NAMES: Record<string, number>[] = [
  {}, {}, {},
  { jan: 1, feb: 2, mar: 3, apr: 4, may: 5, jun: 6, jul: 7, aug: 8, sep: 9, oct: 10, nov: 11, dec: 12 },
  { sun: 0, mon: 1, tue: 2, wed: 3, thu: 4, fri: 5, sat: 6 },
];

function field(text: string, i: number): Field {
  const [lo, hi] = RANGES[i];
  const out = new Set<number>();
  const num = (s: string): number => {
    const named = NAMES[i][s.toLowerCase()];
    const n = named ?? Number(s);
    if (!Number.isInteger(n)) throw new Error(`bad cron value "${s}"`);
    return i === 4 && n === 7 ? 0 : n;
  };
  for (const part of text.split(',')) {
    const [range, stepText] = part.split('/');
    const step = stepText === undefined ? 1 : Number(stepText);
    if (!Number.isInteger(step) || step < 1) throw new Error(`bad cron step "${part}"`);
    let a: number, b: number;
    if (range === '*') [a, b] = [lo, hi];
    else if (range.includes('-')) [a, b] = range.split('-').map(num) as [number, number];
    else { a = num(range); b = stepText === undefined ? a : hi; }
    if (a < lo || b > hi || a > b) throw new Error(`cron value out of range "${part}"`);
    for (let v = a; v <= b; v += step) out.add(v);
  }
  return out;
}

/** A standard 5-field cron in UTC ("*\/5 * * * *"). Throws on anything else. */
export function parseCron(expr: string): Cron {
  const parts = expr.trim().split(/\s+/);
  if (parts.length !== 5) throw new Error('use a 5-field cron, like "*/5 * * * *" (UTC)');
  const [minute, hour, day, month, weekday] = parts.map(field);
  return { minute, hour, day, month, weekday, anyDay: parts[2] === '*', anyWeekday: parts[4] === '*' };
}

/** The first run strictly after `after` (ms), to the minute. */
export function nextRun(cron: Cron, after: number): number {
  const d = new Date(Math.floor(after / 60_000) * 60_000 + 60_000);
  for (let i = 0; i < 366 * 24 * 60; i++) {
    const dayOk = cron.anyDay && cron.anyWeekday ? true
      : cron.anyDay ? cron.weekday.has(d.getUTCDay())
      : cron.anyWeekday ? cron.day.has(d.getUTCDate())
      : cron.day.has(d.getUTCDate()) || cron.weekday.has(d.getUTCDay()); // cron's OR rule
    if (cron.month.has(d.getUTCMonth() + 1) && dayOk && cron.hour.has(d.getUTCHours()) && cron.minute.has(d.getUTCMinutes())) {
      return d.getTime();
    }
    d.setTime(d.getTime() + 60_000);
  }
  throw new Error('this cron never runs');
}

// ---- destinations ----

/**
 * A destination this service may call: http(s), not dply's own hosts, not
 * localhost / .internal / .local, and not a private or reserved IP literal.
 * Returns the reason it is refused, or null.
 */
export function refuseDestination(raw: string, blockedSuffixes: string[], allowPrivate = false): string | null {
  let url: URL;
  try { url = new URL(raw); } catch { return 'the destination must be a full URL, like https://example.com/api/job'; }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') return 'the destination must be http or https';
  if (url.username || url.password) return 'the destination must not carry credentials';
  const host = url.hostname.toLowerCase().replace(/\.$/, '').replace(/^\[|\]$/g, '');
  for (const suffix of blockedSuffixes) {
    const s = suffix.trim().toLowerCase();
    if (s !== '' && (host === s || host.endsWith('.' + s))) return 'that host cannot receive messages';
  }
  if (allowPrivate) return null;
  if (host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.internal') || host.endsWith('.local')) return 'private hosts cannot receive messages';
  if (isPrivateIP(host)) return 'private and reserved addresses cannot receive messages';
  return null;
}

function isPrivateIP(host: string): boolean {
  const v4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(host);
  if (v4) {
    const [a, b] = [Number(v4[1]), Number(v4[2])];
    return a === 0 || a === 10 || a === 127 || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254)
      || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 198 && (b === 18 || b === 19)) || a >= 224;
  }
  if (host.includes(':')) {
    const h = host.toLowerCase();
    return h === '::' || h === '::1' || h.startsWith('fc') || h.startsWith('fd') || h.startsWith('fe80') || h.startsWith('::ffff:');
  }
  return false;
}

// Dply-* headers are aliases for Upstash-* (Dply-Delay = Upstash-Delay, and
// so on), the names dply's docs use. The Upstash-* names win.
export function withDplyHeaders(request: Request): Request {
  const headers = new Headers(request.headers);
  for (const [key, value] of request.headers) {
    const alias = 'upstash-' + key.slice('dply-'.length);
    if (key.startsWith('dply-') && !headers.has(alias)) headers.set(alias, value);
  }
  return new Request(request, { headers });
}
