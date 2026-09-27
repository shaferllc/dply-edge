---
title: "Queues"
description: "Cloudflare Queues for background jobs: send from any app, run the jobs in one app, and pay per operation."
---

A queue carries messages from the code that sends them (a producer) to the code that handles them (a consumer). Use it to move slow work, such as sending email, calling APIs, or resizing uploads, out of the request. Queues run on Cloudflare Queues. There is no worker process to run: Cloudflare delivers messages to your app in batches.

On a Laravel container app, the queue is also a Laravel queue connection, so `dispatch()` works unchanged.

> [!TIP]
> A queue resource pushes batches into your app. If you want long-running `queue:work` processes on Redis or a database queue instead, with autoscaling and failed-job tools, use [Queue workers](/docs/queue-workers).

## Create a queue

1. Open your app. On **Overview**, choose **Add resource**, then **Queue**.
2. Keep **Create new** selected and enter a **Name**, such as `jobs`.
3. Choose **Create**, then redeploy the app.

To use a queue your organization already has, choose **Attach existing**, pick it under **Existing Queue**, and choose **Attach**.

On an SSR or hybrid app, the **Jobs** section can also create and attach queue bindings.

### The Queues page

Your organization's queues are also listed under **Queues** in the top navigation (`/projects/queues`). There you can:

- Create a queue: enter a name (lowercase letters, numbers, and dashes) and choose **Create queue**.
- See each queue's **Waiting** messages and which apps it is **Bound to**.
- **Send test** to put a `{"dply_test": true}` message on it.
- **Attach to a project**: pick a **Queue…**, a **Project…**, and a binding name in `UPPER_SNAKE_CASE` (default `JOBS`), then choose **Attach** and redeploy that app.
- **Delete** a queue.

## Producers and consumers

Every app a queue is attached to can send to it. Only one app runs its jobs: a Cloudflare queue has one consumer.

- The earliest-created production app attached to the queue is the consumer. Every other app only sends. Its card says "Sends only. {app} runs these jobs."
- Previews can send to a queue but never consume it, so they never take production's messages.
- A sleeping queue connection does not make an app the consumer.
- If no production app consumes the queue, messages wait.

The queue's sheet shows this under **Who runs the jobs** and on the **Consumers** tab.

## Delivery settings

dply sets the consumer's delivery settings. Batch size and retries are the same on every plan. Batch wait and concurrency come from your plan, in `config/product/subscription.php`:

| Plan | Batch size | Retries | Batch wait | Concurrent batches |
| --- | --- | --- | --- | --- |
| Starter | 10 | 5 | 2 s | 5 |
| Trial (Pro) | 10 | 5 | 2 s | 10 |
| Pro | 10 | 5 | 2 s | 10 |
| Team | 10 | 5 | 1 s | 50 |
| Enterprise | 10 | 5 | 0 s | Automatic (Cloudflare's maximum) |

Batch wait is the longest a partial batch waits before it is delivered. A container app picks up a plan change on its next deploy.

dply does not set a dead-letter queue. A message that fails all 5 retries is dropped.

## Limits

Your plan sets how many queues your organization can have. Queues created from any app and from the Queues page count together.

<!-- generated: php artisan dply:billing:price-table limits -->
|  | Starter | Pro | Team |
| --- | --- | --- | --- |
| Sites | Unlimited | Unlimited | Unlimited |
| Concurrent builds | 1 | 2 | 5 |
| Build timeout | 20 min | 45 min | 60 min |
| Custom domains (per organization) | 3 | 20 | 100 |
| Container app instances | 1 per app | Autoscaling | Autoscaling |
| Queue workers per app | 1 | 5, autoscaling | 10, autoscaling |
| SQL databases (D1) | 2 | 10 | 50 |
| Queues | 2 | 10 | 50 |
| Realtime connections per app | 200 | 1,000 | 5,000 |
| Audit log | No | No | Yes |

A message can be up to 128 KB. Laravel jobs can be delayed up to 12 hours.

## Laravel (container apps)

When a queue is attached and awake, the next deploy sets:

```env
QUEUE_CONNECTION=dply
DPLY_QUEUE=JOBS
```

`DPLY_QUEUE` is the binding name of the app's first attached queue. Values you save under **Environment** win. Then dispatch as usual:

```php
dispatch(new ProcessOrder($order));

// A second attached queue, by its binding name:
dispatch(new ProcessOrder($order))->onQueue('REPORTS');
```

The app's Worker receives each batch and POSTs it to `/_dply/queue` in your app, where `dply/laravel` runs the jobs. A job that throws is retried. There is no `queue:work` to run.

> [!IMPORTANT]
> `dply/laravel` provides the `dply` connection. dply adds it on the next deploy when the app does not require it, but only when dply builds the image. If your repository has its own `Dockerfile`, run `composer require dply/laravel`.

Rails apps use `gem "dply-rails"` and `config.active_job.queue_adapter = :dply`.

## Any language (container apps)

**Send** a message by POSTing its body to the queue's private host. Copy the host from the queue's **Connect** tab.

```js
await fetch('http://dply.my-app.jobs.internal/send', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ order: 42 }),
});
```

**Consume** by handling `POST /_dply/queue` in your app. The app's Worker sends each batch there:

```json
{
  "queue": "JOBS",
  "messages": [
    { "id": "…", "body": "{\"order\":42}", "attempts": 1 }
  ]
}
```

The request carries an `x-dply-queue-token` header equal to the `DPLY_QUEUE_TOKEN` environment variable; reject requests without it. Answer `200` with the ids that failed. Those are retried after 30 seconds, and the rest are acknowledged. Any non-2xx answer retries the whole batch after 30 seconds.

```js
import express from 'express';

const app = express();
app.use(express.json());

app.post('/_dply/queue', async (req, res) => {
  if (req.get('x-dply-queue-token') !== process.env.DPLY_QUEUE_TOKEN) {
    return res.status(403).end();
  }
  const failed = [];
  for (const message of req.body.messages) {
    try {
      await handle(JSON.parse(message.body));
    } catch {
      failed.push(message.id);
    }
  }
  res.json({ failed });
});
```

## Worker apps (SSR and hybrid)

The queue is a producer binding at `env.NAME`. Send any JSON value. To consume, export a `queue` handler from your Worker. dply's platform Worker delivers each batch to the app that owns the queue.

```js
export default {
  async fetch(request, env) {
    await env.JOBS.send({ order: 42 });
    return new Response('queued', { status: 202 });
  },

  async queue(batch, env) {
    for (const message of batch.messages) {
      try {
        await handle(message.body);
        message.ack();
      } catch {
        message.retry({ delaySeconds: 30 });
      }
    }
  },
};
```

A message you neither acknowledge nor retry is acknowledged. If the handler throws, the whole batch is retried.

## Watch and test a queue

Choose **Open** on the queue's card. The sheet has these tabs:

- **Overview**: **Waiting** messages (last hour), **Operations** and **Cost** this month, who runs the jobs, and the **Delivery** settings.
- **Connect**: code samples for this queue.
- **Send a test**: enter a **Message (JSON)** and choose **Send message**. The consumer receives exactly this body, so send something it expects or ignores.
- **Consumers**: the queue's consumer script and settings.

## Pricing

Queues bill every operation from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="Queues" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Operations | $0.52 | per million |

Every write, read, and delete of a message is an operation, so a delivered message costs about three. Usage is collected daily. This bills at the invoice line **SQL, queues and key-value**.

## Delete a queue

From the app, choose **Delete** on the card, or **Delete queue** in the sheet. This removes the queue and every message waiting in it.

Cloudflare refuses to delete a queue that a live deploy still binds. So when the app has a live deploy, dply detaches the queue now and deletes it after your next deploy: "Detached. The live app still uses it, so it is deleted after the next deploy — redeploy to finish." If another app in your organization still uses the queue at that point, dply keeps it. See [Detach or delete](/docs/resources#detach-or-delete).

> [!WARNING]
> The **Delete** button on the **Queues** page does not wait for a deploy and does not detach the queue from apps. If a deploy still binds it, Cloudflare refuses and the page shows the error. Otherwise the queue is deleted right away but stays attached to its apps, and their next deploy binds a queue that no longer exists. Delete a queue from the app instead.

## Related

- [Resources overview](/docs/resources)
- [Queue workers](/docs/queue-workers)
- [Scheduled tasks](/docs/scheduled-tasks)
- [Usage & metering](/docs/usage)
