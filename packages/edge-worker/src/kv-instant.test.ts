import { describe, expect, it, vi } from 'vitest';
import { lookupHost, type Env } from './handler';
import { handleQueue } from './queue';

// A KV mock that understands get(key, 'json') and get(key, { type: 'json', ... }).
function kv(entries: Record<string, unknown>) {
  const get = vi.fn(async (key: string, type?: unknown) => {
    const value = entries[key];
    if (value === undefined) return null;
    const asJson = type === 'json' || (typeof type === 'object' && (type as { type?: string }).type === 'json');
    return asJson ? value : typeof value === 'string' ? value : JSON.stringify(value);
  });
  return { get } as unknown as KVNamespace & { get: typeof get };
}

const entry = { storage_prefix: 'edge/s/d1', site_id: 's1' };
const newer = { storage_prefix: 'edge/s/d2', site_id: 's1' };

describe('lookupHost', () => {
  it('reads HOST_MAP alone when ROUTES is not bound (unchanged behaviour)', async () => {
    const env = { HOST_MAP: kv({ 'a.test': entry }) } as unknown as Env;
    expect(await lookupHost(env, 'a.test')).toEqual(entry);
  });

  it('follows the ROUTES pointer to an immutable payload', async () => {
    const hostMap = kv({ 'a.test': entry, 'payload:a.test:h2': newer });
    const env = { HOST_MAP: hostMap, ROUTES: kv({ 'a.test': 'h2' }) } as unknown as Env;
    expect(await lookupHost(env, 'a.test')).toEqual(newer);
    expect(hostMap.get).toHaveBeenCalledWith('payload:a.test:h2', { type: 'json', cacheTtl: 86400 });
  });

  it('falls back to the full entry when there is no pointer or its payload is not visible yet', async () => {
    const env1 = { HOST_MAP: kv({ 'a.test': entry }), ROUTES: kv({}) } as unknown as Env;
    expect(await lookupHost(env1, 'a.test')).toEqual(entry);
    const env2 = { HOST_MAP: kv({ 'a.test': entry }), ROUTES: kv({ 'a.test': 'h9' }) } as unknown as Env;
    expect(await lookupHost(env2, 'a.test')).toEqual(entry);
  });

  it('is null for an unknown host', async () => {
    const env = { HOST_MAP: kv({}), ROUTES: kv({}) } as unknown as Env;
    expect(await lookupHost(env, 'x.test')).toBeNull();
  });
});

describe('queue routes with GATES', () => {
  const route = JSON.stringify({ script: 'dply-ssr-abc', token: 't' });
  const batch = () => ({ queue: 'jobs', messages: [{ id: 'a', body: {}, attempts: 1, timestamp: new Date(0), ack: vi.fn(), retry: vi.fn() }], retryAll: vi.fn(), ackAll: vi.fn() });

  it('reads the route from GATES when bound, not HOST_MAP', async () => {
    const fetch = vi.fn(async () => Response.json({}));
    const hostMap = kv({});
    const env = { HOST_MAP: hostMap, GATES: kv({ 'queue:jobs': route }), DISPATCHER: { get: vi.fn(() => ({ fetch })) } } as unknown as Env;
    const b = batch();
    await handleQueue(b as unknown as MessageBatch<unknown>, env);
    expect(fetch).toHaveBeenCalled();
    expect(hostMap.get).not.toHaveBeenCalled();
    expect(b.retryAll).not.toHaveBeenCalled();
  });
});
