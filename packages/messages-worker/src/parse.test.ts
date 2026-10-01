import { describe, expect, it } from 'vitest';
import { durationMs, nextRun, parseCron, refuseDestination, withDplyHeaders } from './parse';
import { signMessage } from './sign';
import { Receiver } from '@upstash/qstash';

describe('durations', () => {
  it('reads QStash delays', () => {
    expect(durationMs('30s')).toBe(30_000);
    expect(durationMs('10m')).toBe(600_000);
    expect(durationMs('2h')).toBe(7_200_000);
    expect(durationMs('1d')).toBe(86_400_000);
    expect(durationMs('45')).toBe(45_000);
    expect(durationMs('soon')).toBeNull();
    expect(durationMs(null)).toBeNull();
  });
});

describe('cron (UTC)', () => {
  const at = (iso: string) => Date.parse(iso);
  it('every 5 minutes', () => {
    expect(new Date(nextRun(parseCron('*/5 * * * *'), at('2026-09-30T10:02:30Z'))).toISOString()).toBe('2026-09-30T10:05:00.000Z');
  });
  it('strictly after the given time', () => {
    expect(new Date(nextRun(parseCron('*/5 * * * *'), at('2026-09-30T10:05:00Z'))).toISOString()).toBe('2026-09-30T10:10:00.000Z');
  });
  it('weekdays at 09:30, names and ranges', () => {
    // 2026-10-03 is a Saturday: next run is Monday 10-05.
    expect(new Date(nextRun(parseCron('30 9 * * mon-fri'), at('2026-10-02T10:00:00Z'))).toISOString()).toBe('2026-10-05T09:30:00.000Z');
  });
  it('day-of-month OR weekday when both are set', () => {
    // The 1st, or any Sunday: 2026-10-04 is a Sunday, before 2026-11-01.
    expect(new Date(nextRun(parseCron('0 0 1 * 0'), at('2026-10-02T00:00:00Z'))).toISOString()).toBe('2026-10-04T00:00:00.000Z');
  });
  it('refuses what it cannot run', () => {
    expect(() => parseCron('* * * *')).toThrow();
    expect(() => parseCron('61 * * * *')).toThrow();
    expect(() => parseCron('@hourly')).toThrow();
  });
});

describe('destinations', () => {
  const blocked = ['dply.io', 'on-dply.live'];
  it('allows public http(s)', () => {
    expect(refuseDestination('https://example.com/api/job?x=1', blocked)).toBeNull();
    expect(refuseDestination('http://93.184.216.34/hook', blocked)).toBeNull();
  });
  it('refuses dply hosts, private names and private or reserved IPs', () => {
    for (const bad of [
      'https://dply.io/x', 'https://api.dply.io/x', 'https://app.on-dply.live/x', 'http://localhost:8080/', 'http://svc.internal/',
      'http://printer.local/', 'http://127.0.0.1/', 'http://10.1.2.3/', 'http://172.20.0.1/', 'http://192.168.1.1/',
      'http://169.254.169.254/latest/meta-data', 'http://100.64.0.1/', 'http://[::1]/', 'http://[fd00::1]/', 'ftp://example.com/', 'not a url',
      'https://user:pass@example.com/',
    ]) {
      expect(refuseDestination(bad, blocked), bad).not.toBeNull();
    }
  });
  it('local tests may allow private addresses, never blocked hosts', () => {
    expect(refuseDestination('http://127.0.0.1:9999/', blocked, true)).toBeNull();
    expect(refuseDestination('https://dply.io/x', blocked, true)).not.toBeNull();
  });
});

describe('signatures', () => {
  it('verify with the real @upstash/qstash Receiver, current or next key', async () => {
    const body = new TextEncoder().encode('{"hello":"world"}');
    const url = 'https://example.com/api/job';
    const receiver = new Receiver({ currentSigningKey: 'sig_current', nextSigningKey: 'sig_next' });

    const signed = await signMessage('sig_current', url, body);
    await expect(receiver.verify({ signature: signed, body: '{"hello":"world"}', url })).resolves.toBe(true);
    // Rotated: messages signed with the next key still verify.
    const rotated = await signMessage('sig_next', url, body);
    await expect(receiver.verify({ signature: rotated, body: '{"hello":"world"}', url })).resolves.toBe(true);
    // Tampered body, wrong URL, unknown key: refused.
    await expect(receiver.verify({ signature: signed, body: '{"hello":"there"}', url })).rejects.toThrow();
    await expect(receiver.verify({ signature: signed, body: '{"hello":"world"}', url: 'https://evil.com/' })).rejects.toThrow();
    await expect(receiver.verify({ signature: await signMessage('someone_else', url, body), body: '{"hello":"world"}', url })).rejects.toThrow();
  });
});

describe('withDplyHeaders', () => {
  it('reads Dply-* as Upstash-*, and Upstash-* wins', () => {
    const r = withDplyHeaders(new Request('https://x/v2/publish/https://a.test', { method: 'POST', headers: { 'Dply-Delay': '10m', 'Dply-Retries': '1', 'Upstash-Retries': '3', 'Dply-Forward-X-Id': '7' } }));
    expect(r.headers.get('upstash-delay')).toBe('10m');
    expect(r.headers.get('upstash-retries')).toBe('3');
    expect(r.headers.get('upstash-forward-x-id')).toBe('7');
  });
});
