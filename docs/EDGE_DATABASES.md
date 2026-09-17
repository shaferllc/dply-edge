---
title: "Databases (D1)"
slug: edge-databases
category: "Edge"
order: 119
description: "Create Cloudflare D1 databases, query them from the dashboard, and bind them to projects."
group: edge
---

# Databases

**Projects → Databases** manages serverless SQLite databases on
[Cloudflare D1](https://developers.cloudflare.com/d1/).

- **Create**: pick a name and, optionally, a location. Databases are private to
  your organization.
- **Query**: run SQL in the console. It runs against live data, and each result
  shows rows read and written.
- **Attach**: bind a database to a project under a name such as `DB`. After the
  next deploy it is `env.DB` in Worker SSR and middleware code.
- **Delete**: type the name to confirm. This removes all data permanently.

| Plan | Databases |
|---|---|
| Free | 1 |
| Pro | 10 |
| Team | 50 |

D1 limits: 10 GB per database and 30 s per query.

Container apps (PHP, Rails, Node servers) can't reach D1 directly. Use a hosted
Postgres or MySQL through `DB_URL` / `DATABASE_URL` (see
[Container apps](EDGE_CONTAINERS.md)).
