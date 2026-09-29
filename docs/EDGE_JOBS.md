---
title: "Edge jobs"
slug: edge-jobs
category: "Edge"
order: 117
description: "See which Projects queues this app runs or sends to, its Laravel queue workers, and the queue bindings its Worker code sends with."
group: edge
---

# Edge jobs

**Jobs** shows the background work for one app: the **Projects → Queues** it is attached to and which app runs each one, its Laravel queue workers (container apps), and the queue bindings its Worker code sends with. It opens with one sentence built from that state, for example which queue this app runs, which it sends to and which app runs those, and how its workers are sized. The sentence turns amber when a queue has nothing running it or jobs have failed.

The section appears for apps that ship a Worker: container apps, SSR apps, and static or hybrid apps with middleware.

## Queues

Queues are created in **Projects → Queues** (Cloudflare Queues, private to your organization) and attached to an app on **Overview → Resources**. Attaching sets `DPLY_QUEUE` (and `QUEUE_CONNECTION=dply` for Laravel) on the next deploy.

**Each queue has exactly one consumer.** The earliest-created production app attached to it runs its jobs; every other attached app only sends. Jobs are never split between apps. A container app consumes in its own Worker; an SSR or hybrid app is fed by the platform Worker.

One row per attached queue:

| Label | Meaning |
|-------|---------|
| **Runs here** | Jobs sent to this queue by any app are delivered to this app. |
| **Sends to *app*** | Another app runs this queue; this app only adds jobs. The label links to that app's **Jobs** section. |
| **Nothing runs it** | Messages wait on the queue. Attach a production app to run them. |

Attach or detach queues on **Overview**; this page only shows them.

## Workers (container apps)

Laravel queue workers run `php artisan queue:work` next to a container app. They are separate from Projects queues: they read Redis (dply Valkey) or a shared dply Postgres/MySQL database. The section summarizes them:

- processes per instance, the instance count or autoscale range, and the queues they read
- Redis or database, and any extra worker groups
- whether the Laravel scheduler runs inside a worker
- live **N of M running** and the failed-job count, loaded after the page renders

If workers can't run, the section says why (for example the app needs to be Laravel, or needs Valkey or a Postgres/MySQL database). Every control stays on **Overview → Resources** (**Manage on Overview**). See [Queue workers](site/queue-workers.md).

## Send from Worker code

Middleware and SSR code send with a queue binding:

```js
await env.JOBS.send({ type: 'notify', … })
```

**Manage bindings** opens a modal to create a new queue or attach an existing one under a binding name. Bindings apply on the next deploy.

## Tips

- Binding names are case-sensitive; match `env.NAME` to the dashboard name.
- Creating a queue from the bindings modal needs platform Edge credentials.

## Related sections

- **Projects → Queues** — create queues, watch the backlog
- **Overview → Resources** — attach queues, turn on workers
- **Bindings** — KV, R2, D1, and queue attachments
- **Crons** — run commands or `scheduled()` on a schedule
