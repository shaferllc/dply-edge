---
title: "Resources overview"
description: "Attach storage, databases, queues, and platform services to an app from the resource map on its Overview page."
---

Resources are the services your app's server code talks to: a key-value store, an object storage bucket, an Edge SQL database, a queue, State, Images, and more. You add them from the resource map on the app's **Overview** page. dply creates the resource in your organization, gives your app a private way to reach it, and sets the environment variables your framework needs on the next deploy.

Resources need server code. A static site has nothing to read them with, so the map shows "Resources need server code. Switch to SSR or a container app to attach a database, cache or storage." instead of an **Add resource** button.

## The resource map

Open your app. The **Overview** page shows the app the way a request travels: visitors, the **Edge network**, the runtime (**App** for a container app, **Worker** for an SSR or hybrid app), and then every resource the app uses. Each resource is a card. The card shows:

- The resource kind and, for most kinds, the address your code uses: a private host such as `dply.my-app.uploads.internal` on a container app, or `env.UPLOADS` on a Worker app.
- **Asleep** when you have put it to sleep.
- A cost estimate for this month, where dply meters the resource.
- "Overridden by wrangler.toml. The repo binding is used." when your repository declares a binding with the same name.

Every card has these buttons:

| Button | What it does |
| --- | --- |
| **Open** or **Settings** | Opens the resource's sheet: how it works, code samples, usage, costs, and settings. |
| **Sleep** / **Wake** | Takes the resource off the app on the next deploy, or puts it back. The resource and its data stay. |
| **Delete** | Deletes the resource itself (see [Detach or delete](#detach-or-delete)). |
| **Detach** | Removes the resource from this app only. The resource stays in your organization. |

Resource changes (adding, detaching, sleeping, renaming) reach the running app only on the next deploy. Redeploy from **Deploys** after you change resources.

## Add a resource

1. Open your app. On **Overview**, choose **Add resource**.
2. The **Add a resource** sheet lists the kinds your app's runtime supports. Choose one.
3. For kinds dply can create, choose **Create new** or **Attach existing**.
4. Enter a **Name**, fill in any kind-specific fields, and choose **Create** (or **Attach**).
5. Redeploy the app.

The toast reads "Connected. It applies on the next deploy."

### Naming

The name becomes both the binding name and part of the private host. dply turns it into:

- An upper-case binding name. `uploads` becomes `UPLOADS`, and `user files` becomes `USER_FILES`. Worker code reads it as `env.UPLOADS`.
- A private host, `dply.{app}.{name}.internal`, for example `dply.my-app.uploads.internal`. Only this app can reach it.
- The Laravel store or disk name, which is the name in lower case: `Cache::store('uploads')`, `Storage::disk('uploads')`.

A name must start with a letter. Two resources on one app cannot share a name. If your repository's `wrangler.toml` already declares the same binding name, dply refuses it: "wrangler.toml already declares UPLOADS. The repo file wins, so pick another name."

These names are reserved and cannot be used: `APP`, `BILLING`, `HOST_MAP`, `ASSETS`, `DEPLOYMENT_ID`, `SITE_ID`, `STORAGE_PREFIX`, `EDGE_CACHE`, and `DISPATCHER`.

### Attach an existing resource

**Attach existing** lists the resources of that kind that your organization already created, from any app or from the organization's **Databases** and **Queues** pages. Pick one from **Existing Store**, **Existing Bucket**, **Existing Database**, or **Existing Queue**, and choose **Attach**. One resource can be attached to several apps. For example, two apps can share a bucket, or one app can send jobs to a queue that another app runs.

The list only shows resources your organization owns. You cannot attach another organization's resource, even if you know its id.

## Which kinds each runtime supports

| Kind | Container app | SSR or hybrid (Worker) | Static |
| --- | --- | --- | --- |
| [Key-value store](/docs/resources/key-value) | Yes | Yes | No |
| [Object storage](/docs/resources/object-storage) | Yes | Yes | No |
| [SQL database](/docs/resources/sql) (Edge SQL) | Yes | Yes | No |
| [Queue](/docs/resources/queues) | Yes | Yes | No |
| [State](/docs/resources/state) | Yes | Yes | No |
| [dply Valkey](/docs/resources/valkey) or [a pasted Redis address](/docs/resources/external-redis) | Yes | Yes | No |
| [Realtime](/docs/resources/realtime) | Yes | Yes | No |
| [Database pool](/docs/resources/database-pools) | Yes | Yes | No |
| [Vector search](/docs/resources/vector-search) | Yes | Yes | No |
| [AI](/docs/resources/ai) | Yes | Yes | No |
| [Images](/docs/resources/images) | Yes | Yes | No |
| Another app | Yes | Yes | No |
| **Database** (Postgres, MySQL, MongoDB), see [Postgres, MySQL & MongoDB](/docs/resources/databases) | Yes | See that page | No |
| **Queue workers** and **Scheduler**, see [Queue workers](/docs/queue-workers) | Yes | No | No |
| [Workflows](/docs/resources/workflows) | Not available | Not available | No |

The **Add a resource** sheet also shows **Queue workers**, **Scheduler** (Laravel container apps), and **Database** at the top of the list when your app can use them.

If you switch a Worker app's runtime while it has a resource that needs a container, the card says "This app runs as a Worker. This resource needs a container app, so it is not attached."

## How your code reaches a resource

How a resource shows up depends on the runtime.

**Container apps** reach every resource over plain HTTP at its private host, `http://dply.{app}.{name}.internal/`. The container's Worker receives those calls and forwards them to the resource. No credentials are involved, and nothing outside the app can use the address. Each resource page lists the paths it answers.

**Worker apps** (SSR and hybrid) get a native binding at `env.NAME`: a KV namespace, an R2 bucket, a D1 database, a queue producer, and so on. The resource pages show the calls.

### Environment variables set on the next deploy

On container apps, dply sets these variables for attached resources that are awake. Values you save yourself under **Environment** win over them.

| Resource | Variables | Laravel only |
| --- | --- | --- |
| Key-value store | `DPLY_KV_HOST`, `DPLY_KV_STORE`, `DPLY_KV_STORES` | `CACHE_STORE={name}`, only when made the default cache and no Redis is attached |
| Object storage | `DPLY_STORAGE_HOST`, `DPLY_STORAGE_DISK`, `DPLY_STORAGE_DISKS` | `FILESYSTEM_DISK={name}` |
| Queue | `DPLY_QUEUE={NAME}` | `QUEUE_CONNECTION=dply` |
| Redis | `REDIS_URL`, `REDIS_USERNAME`, `REDIS_PASSWORD`, `REDIS_HOST`, `REDIS_PORT` | `CACHE_STORE=redis`, `REDIS_CLIENT=phpredis`, `REDIS_PERSISTENT=true` |
| Realtime | `REVERB_*`, `PUSHER_*`, `VITE_REVERB_*`, `VITE_PUSHER_*` | `BROADCAST_CONNECTION=reverb` |

Every container app also gets `DPLY_APP_URL` and `DPLY_QUEUE_TOKEN`. When several stores or buckets are attached, the first one is the default, and `DPLY_KV_STORES` / `DPLY_STORAGE_DISKS` list them all as `name=host` pairs.

For a Laravel container app, the next deploy adds the `dply/laravel` package when a key-value store, bucket, or queue is attached and the app does not already require it. That package registers the cache stores, disks, and the `dply` queue connection.

> [!IMPORTANT]
> dply only adds `dply/laravel` when it builds the image for you. If your repository has its own `Dockerfile`, run `composer require dply/laravel` yourself.

Rails apps use the `dply-rails` gem (`gem "dply-rails"`), which reads the same variables.

## Plans and billing

Some kinds need a paid plan and are not included in the trial: **AI**, **Browser**, **Images**, and **Vector search**. During the trial they appear greyed out in **Add a resource** with "Needs a paid plan. Not included in the trial." A deploy also leaves them off an app whose organization is not on a paid plan.

> [!NOTE]
> A key-value store needs a card on file before dply creates one: "Add a card before starting a key-value store. Reads, writes, and storage are billed to that card." The 5-day trial takes a card up front, so a trial organization can create one. An organization on an older trial without a card must add one first. The same rule applies to starting dply Valkey and Realtime.

Plans also limit how many Edge SQL databases and queues your organization can have. See [Edge SQL (D1)](/docs/resources/sql#limits) and [Queues](/docs/resources/queues#limits).

Resource usage is billed on your monthly invoice with your plan. Each resource page has its own price table. See [Usage & metering](/docs/usage) for how usage reaches your bill.

## Sleep and wake

**Sleep** takes a resource off the app without deleting it. On the next deploy the binding and its environment variables are left out. On a container app, calls to its host get `503 This resource is asleep.` Choose **Wake** and redeploy to bring it back.

A sleeping key-value store is not billed. A sleeping bucket or SQL database keeps its data and its storage is still billed. Realtime and dply Valkey sleep differently; see [Realtime](/docs/resources/realtime) and [Valkey (Redis)](/docs/resources/valkey).

## Detach or delete

The two actions do different things.

**Detach** removes the resource from this app only. The resource, and everything in it, stays in your organization. You can attach it again later, to this app or another. dply Valkey and Realtime have no **Detach**: delete them instead.

**Delete** opens **Delete this resource?** and destroys the resource itself, not only the link:

- A key-value store, bucket, SQL database, or queue is deleted with all its data. This cannot be undone.
- A bucket with files in it cannot be deleted. Choose **Empty and delete** to remove every file first. A large bucket can take a few runs.
- AI, Images, Another app, and Workflow only have **Remove** ("Remove from this app?"). Nothing is deleted.
- A pasted Redis address is removed from the app. The Redis server is not touched.
- State keys are not wiped. See [State](/docs/resources/state#delete).

When deleting completes, dply also removes the resource from your organization's other apps, so their next deploy does not bind something missing.

### Deleted after the next deploy

Deleting a resource that the live deploy still uses would break the running app, and Cloudflare refuses outright to delete a queue that a deploy still binds. So when the app has a live deploy and the resource is a key-value store, bucket, SQL database, queue, vector index, or database pool, dply:

1. Detaches it from this app now.
2. Shows "Detached. The live app still uses it, so it is deleted after the next deploy — redeploy to finish."
3. Deletes it once your next deploy is live and no longer binds it.

If another app in your organization still uses the resource at that point, dply keeps it, because it is in use. If the delete fails, dply tries again after the following deploy.

> [!WARNING]
> Until you redeploy, the resource and its data still exist and are still billed.

If your organization did not create the resource, **Delete** only detaches it: "Detached. There was nothing this organization created to delete, so it was left in place."

## Ownership

Every organization's resources live in one shared Cloudflare account. dply keeps them apart by name: everything created from dply is named `dply-{organization id}-{name}`. dply:

- Only lists your organization's resources under **Attach existing**.
- Refuses to attach a resource your organization does not own: "That bucket does not belong to this organization."
- Only ever deletes resources your organization created.

Resources declared in your repository's `wrangler.toml` or `dply.yaml` `bindings:` follow the same rule. A name such as `cache` means your organization's `cache`, and dply creates it on first deploy if it does not exist.

## Organization Databases and Queues pages

Edge SQL databases and queues are also listed outside any app, under **Databases** and **Queues** in the top navigation (`/projects/databases` and `/projects/queues`). Both pages let you create a resource, attach it to an app with a binding name, and delete it. See [Edge SQL (D1)](/docs/resources/sql#the-databases-page) and [Queues](/docs/resources/queues#the-queues-page).

## Next steps

- [Key-value (KV)](/docs/resources/key-value)
- [Object storage](/docs/resources/object-storage)
- [Edge SQL (D1)](/docs/resources/sql)
- [Queues](/docs/resources/queues)
