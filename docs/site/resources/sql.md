---
title: "Edge SQL (D1)"
description: "Serverless SQLite databases on Cloudflare D1, with a query console, table browser, and per-row billing."
---

Edge SQL gives your app a serverless SQLite database on Cloudflare D1. There is no server to size or keep awake: you pay for rows read, rows written, and storage. Use it for app data that fits SQLite well, such as settings, content, small catalogs, and per-tenant data, especially from Worker apps that cannot open a TCP connection to Postgres.

For a full Postgres, MySQL, or MongoDB server, see [Postgres, MySQL & MongoDB](/docs/resources/databases).

## Create a database

From an app:

1. Open your app. On **Overview**, choose **Add resource**, then **SQL database**.
2. Keep **Create new** selected and enter a **Name**, such as `main`.
3. Choose **Create**, then redeploy the app.

To use a database your organization already has, choose **Attach existing**, pick it under **Existing Database**, and choose **Attach**.

> [!NOTE]
> A database created from **Add resource** is always placed in Western North America (`wnam`). To choose a location, create it on the **Databases** page and attach it to your app.

### The Databases page

Your organization's databases are also listed under **Databases** in the top navigation (`/projects/databases`). From there:

1. Under **New database**, enter a name (lowercase letters, numbers, and dashes, up to 40 characters) and pick a location.
2. Choose **Create**.
3. Select the database. Under **Attach to a project**, choose the app, enter a binding name in `UPPER_SNAKE_CASE` (default `DB`), and choose **Attach**.
4. Redeploy that app.

Locations on the Databases page:

| Location | Value |
| --- | --- |
| Automatic | none |
| Western North America | `wnam` |
| Eastern North America | `enam` |
| Western Europe | `weur` |
| Eastern Europe | `eeur` |
| Asia-Pacific | `apac` |
| Oceania | `oc` |

A location is a hint for where the primary copy lives. It cannot be changed later.

## Limits

Your plan sets how many Edge SQL databases your organization can have. Databases created from any app and from the Databases page count together.

<!-- generated: php artisan dply:billing:price-table limits -->
|  | Starter | Pro | Team |
| --- | --- | --- | --- |
| Sites | Unlimited | Unlimited | Unlimited |
| Concurrent builds | 1 | 2 | 5 |
| Build timeout | 20 min | 45 min | 60 min |
| Custom domains (per organization) | 3 | 20 | 100 |
| Container app instances | 1 per app | Autoscaling | Autoscaling |
| Queue workers per app | 1, starts when jobs arrive | 5, autoscaling | 10, autoscaling |
| SQL databases (D1) | 2 | 10 | 50 |
| Queues | 2 | 10 | 50 |
| Realtime connections per app | 200 | 1,000 | 5,000 |
| Audit log | No | No | Yes |

At the limit, creating another shows "Your Pro plan includes 10 databases. Upgrade on the billing page for more."

## Query from your code

### Worker apps (SSR and hybrid)

The database is a D1 binding at `env.NAME`.

```js
export default {
  async fetch(request, env) {
    const { results } = await env.MAIN
      .prepare('SELECT * FROM users WHERE id = ?')
      .bind(1)
      .all();
    return Response.json(results);
  },
};
```

### Container apps

POST the SQL and its parameters as JSON to `http://{host}/query`. Copy the exact host from the database's **Connect** tab. The answer is D1's `{results, success, meta}`. Each call runs one statement. Use `?` placeholders and `params` for values.

```php
use Illuminate\Support\Facades\Http;

$rows = Http::post('http://dply.my-app.main.internal/query', [
    'sql' => 'SELECT * FROM users WHERE id = ?',
    'params' => [1],
])->json('results');
```

```js
const res = await fetch('http://dply.my-app.main.internal/query', {
  method: 'POST',
  headers: { 'content-type': 'application/json' },
  body: JSON.stringify({ sql: 'SELECT * FROM users WHERE id = ?', params: [1] }),
});
const { results } = await res.json();
```

> [!NOTE]
> On a container app, Edge SQL is an HTTP API, not a database connection. Laravel's Eloquent and migrations do not use it. For an ORM-backed database on a container app, add a Postgres or MySQL **Database** instead.

## Browse and query

Choose **Open** on the database's card. The sheet has these tabs:

- **Overview**: size, number of tables, region (and the location you asked for), rows read and written this month, peak storage, and this month's cost.
- **Connect**: the code samples above with this database's host or binding filled in.
- **Tables**: lists tables. Select one to see its first 50 rows.
- **Console**: run SQL against the live database. Several statements separated by semicolons run in order, and the result shows the last one. Results show up to 200 rows.
- **Settings**: rename the binding (**Save name**). The app uses the new name after the next deploy; update your code to match. The database itself keeps its name.

> [!WARNING]
> The console runs against the live database the app uses. Writes and deletes are real and cannot be undone.

The **Databases** page has the same kind of console under **SQL**, with **Run**.

## Pricing

Edge SQL bills every unit from the first, less your plan's included usage credit:

<!-- generated: php artisan dply:billing:price-table rates --group="SQL (D1)" -->
| Meter | Price | Unit |
| --- | --- | --- |
| Rows read | $0.0013 | per million |
| Rows written | $1.30 | per million |
| Storage | $0.975 | per GB-month |

Storage is billed as the database's largest size during the month, for the full month. Usage is collected daily, so the sheet's figure runs through yesterday. This bills at the invoice line **SQL, queues and key-value**.

## Delete a database

From the app, choose **Delete** on the card, or **Delete database** under **Settings**. This deletes the database and every row in it, and removes it from the app. It cannot be undone. If the app's live deploy still uses the database, dply detaches it now and deletes it after your next deploy. See [Detach or delete](/docs/resources#detach-or-delete).

On the **Databases** page, select the database, type its name to confirm, and choose **Delete database**.

> [!WARNING]
> The **Databases** page deletes the database immediately, even if a live app still uses it. Those apps lose the binding on their next deploy, and requests that use it fail until then. Delete from the app instead to wait for the next deploy.

## Related

- [Resources overview](/docs/resources)
- [Postgres, MySQL & MongoDB](/docs/resources/databases)
- [Database pools](/docs/resources/database-pools)
- [Usage & metering](/docs/usage)
