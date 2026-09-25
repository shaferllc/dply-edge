import { describe, expect, it, vi } from 'vitest';
import type { Env } from './handler';
import { handleQueue } from './queue';

function message(id: string) {
  return { id, body: { id }, attempts: 1, timestamp: new Date(0), ack: vi.fn(), retry: vi.fn() };
}

function batchOf(...ids: string[]) {
  return { queue: 'jobs', messages: ids.map(message), retryAll: vi.fn(), ackAll: vi.fn() };
}

function envWith(route: string | null, reply: () => Promise<Response>) {
  const fetch = vi.fn(reply);
  return {
    fetch,
    env: {
      HOST_MAP: { get: vi.fn(async () => route) },
      DISPATCHER: { get: vi.fn(() => ({ fetch })) },
    } as unknown as Env,
  };
}

const route = JSON.stringify({ script: 'dply-ssr-abc', token: 't' });

describe('handleQueue', () => {
  it('delivers to the live script and acks all but the retried ones', async () => {
    const batch = batchOf('a', 'b');
    const { env, fetch } = envWith(route, async () => Response.json({ retry: [{ id: 'b', delaySeconds: 30 }] }));

    await handleQueue(batch as unknown as MessageBatch<unknown>, env);

    const [request] = fetch.mock.calls[0] as unknown as [Request];
    expect(request.url).toBe('https://dply.internal/__dply/queue');
    expect(request.headers.get('x-dply-queue-token')).toBe('t');
    expect(batch.messages[0].ack).toHaveBeenCalled();
    expect(batch.messages[1].retry).toHaveBeenCalledWith({ delaySeconds: 30 });
  });

  it('retries the whole batch when no app owns the queue', async () => {
    const batch = batchOf('a');
    const { env, fetch } = envWith(null, async () => Response.json({}));

    await handleQueue(batch as unknown as MessageBatch<unknown>, env);

    expect(fetch).not.toHaveBeenCalled();
    expect(batch.retryAll).toHaveBeenCalled();
  });

  it('retries the whole batch when the app fails', async () => {
    const batch = batchOf('a');
    const { env } = envWith(route, async () => new Response('boom', { status: 500 }));

    await handleQueue(batch as unknown as MessageBatch<unknown>, env);

    expect(batch.retryAll).toHaveBeenCalled();
    expect(batch.messages[0].ack).not.toHaveBeenCalled();
  });
});
