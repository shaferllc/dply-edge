---
title: "Postgres, MySQL & MongoDB"
description: "Run a managed Postgres, MySQL, or MongoDB database for your app that sleeps when idle, backs itself up continuously, and connects over TLS."
---

A dply database is a Postgres, MySQL, or MongoDB server that belongs to one app. dply starts it, puts its address and password in the app's environment on the next deploy, backs it up continuously, and lets it sleep when nothing is connected so you pay for compute only while it is used. Use one when your app needs a real relational or document database. For small, read-heavy data at the edge, see [Edge SQL (D1)](/docs/resources/sql) instead.

> [!NOTE]
> A database needs a card on the account. It is billed to that card as usage. SQLite does not need one.

## Engines

| Engine | Version | Port | Login | Database |
|---|---|---|---|---|
| Postgres | 17 | `5432` | `app` | `app` |
| MySQL | 8.4 | `3306` | `app` | `app` |
| MongoDB | 7 | `27017` | `app` | `app` |
| SQLite | | | | a file in the app |

Every server engine accepts TLS connections only. Plain-text connections are refused.

SQLite is a file at `/tmp/database.sqlite` inside the app. It is saved while the app runs and restored when the app wakes, and one instance serves the app so the file stays consistent. It has no backups, statistics, or console.

## Create a database

1. In your app, open **Resources**.
2. Choose **Add resource**, then **Database**. A **Database** card appears on the app's map.
3. Choose the **Database** card. Under **Database**, pick **Postgres**, **MySQL**, or **MongoDB**.
4. Pick a **Size**, a **Sleep** time, and a **Disk** (see below). The sheet shows an estimate as **About $…/mo**. The **Awake** field (hours a day) only changes the estimate, not the database.
5. Choose **Confirm** in the **Switch to Postgres?** footer.
6. Deploy the app.

The database starts on its first connection. The first start creates its disk and takes a few seconds, once. After that the app receives these values on every deploy:

| Engine | Environment variables set by dply |
|---|---|
| Postgres | `DB_CONNECTION=pgsql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DB_SSLMODE=require`, `DATABASE_URL` |
| MySQL | `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `MYSQL_ATTR_SSL_CA`, `DATABASE_URL` |
| MongoDB | `MONGODB_URI`, `MONGO_URL`, `MONGODB_DATABASE` |
| SQLite | `DB_CONNECTION=sqlite`, `DB_DATABASE=/tmp/database.sqlite` |

A value you save yourself in [Environment variables](/docs/environment-variables) wins over the one dply sets.

An app has one database. Databases are available to apps with server code: container apps, and SSR or hybrid apps. On an SSR or hybrid app the values arrive as Worker bindings (`env.DATABASE_URL`), and the Worker needs a driver that opens TCP sockets with the `nodejs_compat` flag. A [database pool](/docs/resources/database-pools) in front of the database saves the connection setup on every request.

## Sizes and disk

| Size | Memory | Compute units |
|---|---|---|
| 0.25 vCPU | 1 GB | 0.25 |
| 0.5 vCPU | 2 GB | 0.5 |

The larger rungs of the size ladder (1, 2 and 4 vCPU) are not offered for databases yet.

Disks come in `1 GB`, `5 GB`, `10 GB`, and `25 GB`. You can change the size and the disk later from the same sheet. A new size applies the next time the database wakes. A disk only grows: picking a smaller one is refused.

Each size is tuned for its memory. Postgres gets a quarter of it for shared buffers and caps the write-ahead log at a quarter of the disk. MySQL gives the buffer pool half the memory. MongoDB sizes the WiredTiger cache to the plan.

## Sleep and wake

Pick how long the database waits after the last connection closes before it sleeps: **1 minute**, **5 minutes**, **15 minutes**, or **Stays on**. A sleeping database stops its compute and keeps its data on its disk. The next connection wakes it in about a third of a second; the client waits while it does.

Compute is billed only while the database is awake. The disk is billed whether it is awake or asleep. **Stays on** bills every hour.

Anything that keeps a connection open keeps the database awake. A queue worker polling a `database` queue is the common case. Put queues on [Valkey](/docs/resources/valkey) if you want the database to sleep.

## Connect

Open the database card, then the **Connect** tab. It shows the **Host**, **Port**, **User**, **Password** (choose **Show**), a **Connection URL**, and a command to open a shell. The host has the form `<id>.db.dply.io`.

### Laravel

The next deploy sets `DB_CONNECTION`, the host, the password, and `DATABASE_URL`, so the stock `config/database.php` works unchanged. To run migrations on each start, open **Sleep, region, scheduler** on the app card and turn on **Run migrations when a container starts** (`migrate --force --isolated`).

```php
// config/database.php works as shipped. For Postgres, dply also sets:
// DB_SSLMODE=require
$users = DB::table('users')->count();
```

### Node

```js
// Postgres
import pg from 'pg';
const client = new pg.Client({ connectionString: process.env.DATABASE_URL });
await client.connect();

// MongoDB
import { MongoClient } from 'mongodb';
const mongo = new MongoClient(process.env.MONGODB_URI);
const db = mongo.db(process.env.MONGODB_DATABASE);
```

### A shell

```bash
# Postgres
psql "postgresql://app@<id>.db.dply.io:5432/app?sslmode=require"

# MySQL: the mysql client needs the host as the TLS server name
mysql -h <id>.db.dply.io -P 3306 -u app -p --ssl-mode=REQUIRED --tls-sni-servername=<id>.db.dply.io app
```

The shell asks for the password from the **Connect** tab. Connecting wakes the database if it is asleep.

> [!IMPORTANT]
> The database address is public, protected by TLS and the password. Your client must send the host name (SNI) during the TLS handshake. Connecting by IP address does not work.

### Read-only login

For Postgres and MongoDB, **Connect** can create a second login, `app_ro`, that can read every table and change nothing. Use it for BI tools such as Metabase. Choose **New password** to create or rotate it; the password is shown once. **Turn off** removes it.

### MySQL latency

MySQL sends each parameterised query in two round trips. Set `DPLY_MYSQL_ONE_ROUND_TRIP=true` in Environment and redeploy to send each in one; parameters are then escaped into the query by PHP instead of bound by MySQL.

## Region

Every database runs in the region shown on the sheet (currently New York). Container apps that use a dply database run near it by default. The **Overview** tab shows the measured round trip from the app at its last deploy. See [Data regions](/docs/data-regions).

## Backups and restore

Every change is streamed to storage, and a full backup runs daily. Backups are kept for 7 days. This works the same for all three engines: Postgres uses its write-ahead log, MySQL its binlog, and MongoDB its oplog.

To restore:

1. Open the database card, then the **Backups** tab.
2. Under **Restore to a point in time**, enter a **Time (UTC)** or use a **Quick pick**.
3. Choose **Restore** and confirm.

Restoring replaces the data with how it was at that second. It takes a few minutes. The app keeps its address and password. The **Backups** tab also shows when the last full backup ran and whether saving recent changes is healthy.

### Export and import

Under **Export and import**, **Export now** writes a full copy of the data as one file and keeps it beside the backups. **Download** saves it; **Load** loads it back.

| Engine | Export format | Import your own dump |
|---|---|---|
| Postgres | `pg_dump -Fc` | Yes, a `pg_dump -Fc` file |
| MySQL | `mysqldump`, gzipped | No |
| MongoDB | `mongodump --archive` | Yes, a `mongodump --archive` file, gzipped or not |

To import, choose **Get upload command**, run it where the file is (the link works for an hour), then choose **Load it**. For Postgres, tables in the file are replaced and others are left alone. For MongoDB, everything in the database is replaced.

## Look inside

The database sheet has tabs for day-to-day work. None of them wakes the database until you load or refresh something.

- **Overview**: state, size, disk use, awake hours this month, and round-trip time from the app.
- **Statistics**: data size, tables, rows, connections, cache hit rate, and the largest tables. **Load live stats** wakes the database.
- **Queries**: the queries that took the most total time, and what is running now. You can cancel a running query.
- **Health**: disk use, tables that probably need an index, indexes nothing uses, dead rows, and Postgres extensions (**Turn on**).
- **Console**: one read-only statement at a time, up to 200 rows and 15 seconds.
- **Settings**: **Migrate**, **Seed**, **Prepare**, and **Roll back**, and **Run migrations when a container starts**.

## Pricing

Compute is billed per second while awake. Storage is billed per GB-month on the disk size you picked, awake or asleep.

<!-- generated: php artisan dply:billing:price-table sizes --product=database -->
| Size | Memory | Per second awake | Per hour awake |
| --- | --- | --- | --- |
| 0.25 vCPU | 1 GB | $0.00000883 | $0.0318 |
| 0.5 vCPU | 2 GB | $0.0000177 | $0.0636 |
| 1 vCPU | 4 GB | $0.0000353 | $0.127 |
| 2 vCPU | 8 GB | $0.0000707 | $0.254 |
| 4 vCPU | 16 GB | $0.000141 | $0.509 |

Storage is $0.42 per GB-month (`php artisan dply:billing:price-table rates --group=Databases`).

A 0.25 vCPU database that stays on all month with a 1 GB disk is about $23.63. The same database awake 8 hours a day is about $8.05. This bills at the invoice line **Databases**, less your plan's included usage credit. Usage shows on the app's bill and in [Usage & metering](/docs/usage).

## Remove or switch a database

To remove a database, open **Database** on the app card, pick **None**, then choose **Remove**. To move to another engine, pick it and choose **Confirm**.

> [!WARNING]
> Removing a database, or switching to another engine, deletes the database, its disk, and all of its backups at once. It cannot be undone. Choose **Export now** and **Download** first if you want to keep the data.

## Related

- [Database pools](/docs/resources/database-pools)
- [Valkey (Redis)](/docs/resources/valkey)
- [Data regions](/docs/data-regions)
- [Usage & metering](/docs/usage)
