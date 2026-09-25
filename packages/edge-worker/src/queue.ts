import type { Env } from './handler';

/**
 * Global queue consumer (T-019). The platform Worker is the consumer of every
 * queue a Worker site owns. HOST_MAP `queue:{name}` names the site's live
 * script and a shared token; the batch goes to that script's dply-entry.js at
 * POST /__dply/queue, and its reply says which messages to retry.
 *
 * Bodies travel as JSON, so a message sent as a Date, Map or ArrayBuffer
 * arrives as its JSON form.
 */
interface QueueRoute {
  script: string;
  token: string;
}

interface QueueReply {
  retryAll?: boolean;
  delaySeconds?: number;
  retry?: { id: string; delaySeconds?: number }[];
}

export async function handleQueue(batch: MessageBatch<unknown>, env: Env): Promise<void> {
  const raw = await env.HOST_MAP.get('queue:' + batch.queue);
  const route = raw ? (JSON.parse(raw) as QueueRoute) : null;
  if (!route?.script || !route.token || !env.DISPATCHER) {
    batch.retryAll();
    return;
  }

  let reply: QueueReply;
  try {
    const response = await env.DISPATCHER.get(route.script).fetch(new Request('https://dply.internal/__dply/queue', {
      method: 'POST',
      headers: { 'content-type': 'application/json', 'x-dply-queue-token': route.token },
      body: JSON.stringify({
        queue: batch.queue,
        messages: batch.messages.map((m) => ({ id: m.id, body: m.body, attempts: m.attempts, timestamp: m.timestamp.getTime() })),
      }),
    }));
    if (!response.ok) {
      batch.retryAll();
      return;
    }
    reply = (await response.json()) as QueueReply;
  } catch {
    batch.retryAll();
    return;
  }

  if (reply.retryAll) {
    batch.retryAll(reply.delaySeconds ? { delaySeconds: reply.delaySeconds } : undefined);
    return;
  }
  const retries = new Map((reply.retry ?? []).map((r) => [r.id, r.delaySeconds]));
  for (const message of batch.messages) {
    if (retries.has(message.id)) {
      const delaySeconds = retries.get(message.id);
      message.retry(delaySeconds ? { delaySeconds } : undefined);
    } else {
      message.ack();
    }
  }
}
