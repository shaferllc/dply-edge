---
title: "Queues"
slug: edge-queues
category: "Edge"
order: 120
description: "Create Cloudflare Queues, watch the backlog, and bind them to projects for background jobs."
group: edge
---

# Queues

**Projects → Queues** manages [Cloudflare Queues](https://developers.cloudflare.com/queues/)
for background work.

- **Create**: name a queue. Queues are private to your organization.
- **Waiting**: the number of messages not yet processed, averaged over the latest minute.
- **Send test**: publishes a small JSON message.
- **Attach**: bind a queue to a project on **Overview → Resources**, under a
  name, `JOBS` by default. The next deploy sets `DPLY_QUEUE` (and
  `QUEUE_CONNECTION=dply` for Laravel). Every attached app can send:
  - **Container apps** send with `dply/laravel` or `dply-rails` (see
    [Container apps](EDGE_CONTAINERS.md)).
  - **Worker / SSR code** can `env.JOBS.send(...)`.
- **Delete**: removes the queue and any waiting messages.

**Each queue has exactly one consumer.** The earliest-created production app
attached to it runs its jobs; every other attached app only sends. Jobs are
never split between apps. A container app consumes in its own Worker; an SSR
or hybrid app is fed by the platform Worker. Each app's **Jobs** section shows
whether it runs a queue or sends to it, and which app runs it.

## Billing

On Pro and Team, operations (writes, reads, deletes) are billed at $0.40 per million plus the usage markup, on the **Databases & queues** line.

| Plan | Queues |
|---|---|
| Starter | 2 |
| Pro | 10 |
| Team | 50 |
