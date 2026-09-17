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
- **Attach**: bind a queue to a project under a name, `JOBS` by default. After
  the next deploy:
  - **Container apps** consume it automatically. Install `dply/laravel` or
    `dply-rails` (see [Container apps](EDGE_CONTAINERS.md)).
  - **Worker / SSR code** can `env.JOBS.send(...)`.
- **Delete**: removes the queue and any waiting messages.

A queue can have only one consumer. Attach it to just one container project.

## Billing

On Pro and Team, operations (writes, reads, deletes) are billed at $0.40 per million plus the usage markup, on the **Databases & queues** line.

| Plan | Queues |
|---|---|
| Free | 1 |
| Pro | 10 |
| Team | 50 |
