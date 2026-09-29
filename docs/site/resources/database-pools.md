---
title: "Database pools"
description: "Keep warm connections to a Postgres or MySQL database close to your app so each request skips the connection setup."
---

A database pool keeps warm connections to a Postgres or MySQL database near your app, so each request skips the TCP, TLS, and login round trips of opening a new connection. It can also cache read queries for a short time. Pools matter most for SSR and hybrid apps running as Workers, which open a fresh connection on every request. The pool points at a database you already have: this app's [dply database](/docs/resources/databases), or any Postgres or MySQL reachable from the internet, such as Neon, Supabase, RDS, or PlanetScale.

## Create a pool

1. In your app, open **Overview**.
2. Choose **Add resource**, then **Database pool**.
3. Leave **Create new** selected and enter a **Name**, such as `DB`.
4. Choose what to pool:
   - **This app's database** pools the dply Postgres or MySQL the app already has. This option only appears when the app has one.
   - **Another database**: paste its **Address**, for example `postgres://user:secret@db.example.com:5432/app`. The address must include a user, password, host, and database name, and start with `postgres://`, `postgresql://`, or `mysql://`.
5. Choose **Create**, then deploy the app.

Creating a pool connects to the database once, which can take a few seconds if it is waking up. The pasted address is sent to the pool and is not stored or shown by dply.

To reuse a pool this organization already created, choose **Attach existing** and pick it from **Existing Pool**.

## Use the pool

### SSR and hybrid apps (Workers)

The next deploy binds the pool as `env.<NAME>`, where `<NAME>` is the name you gave it in capitals. Pass its `connectionString` to your usual driver. Workers need the `nodejs_compat` compatibility flag.

```ts
import { Client } from 'pg';

export default {
  async fetch(request, env, ctx) {
    const client = new Client({ connectionString: env.DB.connectionString });
    await client.connect();
    const { rows } = await client.query('select now()');
    ctx.waitUntil(client.end());
    return Response.json(rows);
  },
};
```

With postgres.js:

```ts
import postgres from 'postgres';

const sql = postgres(env.DB.connectionString, { max: 5, fetch_types: false });
const rows = await sql`select now()`;
ctx.waitUntil(sql.end());
```

### Container apps

A container app reads the pool's connection string from its private host after the next deploy. The host is shown on the pool's **Connect** tab.

```php
$url = Http::get('http://dply.<app>.db.internal/')->json('connectionString');
```

```js
const { connectionString } = await (await fetch('http://dply.<app>.db.internal/')).json();
```

> [!WARNING]
> Pools have not yet been verified from container apps: the pool's address may only be reachable from a Worker. A container app already keeps its own connections to a nearby dply database, so connect to the database's own address instead.

## Inspect the pool

Choose **Open** on the pool card. The **Overview** tab shows the database host, database, user, engine, and whether query caching is on. The **Settings** tab shows the pool id.

## Pricing

dply does not bill pools separately. You pay for the database the pool points at.

## Delete a pool

On the pool's **Settings** tab, choose **Delete**, then **Delete resource**. If this organization created the pool, it is deleted; otherwise it is only detached from this app. The database it points at, and its data, are not touched.

If a live deploy still uses the pool, the delete waits until the next deploy drops it, so the running app does not break. **Detach** on the card removes the pool from this app and keeps it for other apps.

## Related

- [Postgres, MySQL & MongoDB](/docs/resources/databases)
- [Server rendering (SSR)](/docs/server-rendering)
- [Environment variables](/docs/environment-variables)
