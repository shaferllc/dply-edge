# Report: Resources (writer 1)

Pages: `resources`, `resources/key-value`, `resources/object-storage`, `resources/sql`, `resources/queues`, `resources/state`, `resources/workflows`, `resources/images`.

## 1. Mismatches

1. **Bucket Costs tab quotes the wrong rates.** `resources/views/livewire/sites/edge/workspace/partials/sheets/object.blade.php:112-121` prints the delivery rates: `r2_storage_cents_per_gb_month` ($0.03), 5 GB included, 20k Class A / 1M Class B free, plus a 25% fee. But the figure it shows (`ManagesStorageResources::r2UsageCents`, line ~200) and the bill (`EdgePlatformUsageCost`) both use `r2_bucket_*`: $0.015/GB, $4.50/M, $0.36/M, no allowance, no markup. The docs give the billed rates and carry a NOTE about the difference.
2. **D1 created from Add resource is always placed in `wnam`.** `EdgeContainerConnections::provision()` line 949 falls back to `'wnam'`. The builder has no location field (`Resources::saveConnection` line 1858 only passes a hint for object storage). The org **Databases** page offers Automatic plus six regions (`Databases::LOCATIONS`). Rows from `provision()` also never record `location_hint`, so the SQL sheet's "Asked for" note is always empty for them.
3. **A deferred delete is silently dropped when another app still binds the resource.** In `EdgeContainerConnections::deletePending()` (lines 1250-1252), a bound-elsewhere item hits `continue` and is not added to `$left`, so it leaves `pending_deletes` for good. The user was told "it is deleted after the next deploy", but it never is. The resource stays and keeps billing. The queue sheet's "Removes the queue … for every app attached to it" (`sheets/queue.blade.php:147`) is also wrong in this case. An immediate delete, by contrast, does detach it everywhere (`Resources::detachEverywhere`).
4. **The org Queues page delete skips everything the app path does.** `Modules/Edge/Livewire/Queues.php:107-122` does not detach the queue from apps, does not defer for live deploys, and does not check the queue is unbound (Cloudflare refuses when it is).
5. **The org Databases page deletes a D1 immediately** (`Databases.php:136-165`), even when a live deploy binds it. That breaks the live app until its next deploy. Unlike the app path, it has no deferral.
6. **The org Databases / Queues Attach skips the builder's checks.** `EdgeContainerConnections::attach()` never checks `allowedKinds` (a static site can get a binding), `repoBindingNames` (a wrangler.toml clash), or `WORKER_KINDS`.
7. **The Databases page contradicts the product.** It says "Container apps use an external database (DB_URL / DATABASE_URL)" (`livewire/edge/partials/databases-manager.blade.php:103`), but D1 on a container works through the `/query` host. Its description also says "query it from your app as env.DB", which is Worker-only.
8. **State's builder button reads "Attach", not "Create".** `chooseConnectionKind` (Resources.php:1768) sets mode `attach` for `durable_object`, so the footer says **Attach** for a thing that is created. The docs say **Attach**.
9. **The Images Worker hint is wrong.** For a Worker app the builder header says "Your code reads it as env.NAME, where NAME is the name you give it here" (`sheets/connection.blade.php:27`). Images and AI take no name: they are auto-named `IMAGES` / `AI` (Resources.php:1779).
10. **Images and AI get an unprefixed host at first.** `storeConnection(strtoupper($kind), $kind.'.internal', '')` (Resources.php:1779) produces `images.internal`. `prefixBareHosts()` rewrites it on the next Resources mount (line 1075). There is no env var for this host, so code that hard-coded `images.internal` breaks after the following deploy. The docs tell users to reload before copying.
10b. **No "Redeploy" banner for resource changes.** `storeConnection`, `removeConnection`, `sleepConnection` and `sqlRename` never set `settings_saved_at`, so `needsRedeploy()` (Resources.php:2728) does not show the banner after adding, detaching, sleeping or renaming. Only a deferred delete sets it. Users see only a toast.
11. **Stale comment.** The `PAID_ONLY` docblock (`EdgeContainerConnections.php:67-71`) says AI, Browser, Images and Vectorize are "Unmetered". Images is now metered (`images_transformations_*`).
12. **KV "Letters and numbers only" copy vs behaviour.** The builder error says "Letters and numbers only, starting with a letter", but `identity()` accepts spaces and dashes and converts them (`user files` becomes `USER_FILES`).
13. **The Delete sheet copy is generic.** "A key-value store, bucket, database, or queue is removed… This cannot be undone." (`delete-connection.blade.php:22`) does not warn that the delete may be deferred, or that another app in the org using it loses it too.
14. **Workflow copy vs reality.** The `KINDS['workflow']` hint and `wranglerEntry` still exist. `HIDDEN_FROM_BUILDER` hides it, but an old row still breaks deploys (the sheet says so). There is no repo path to verify. Documented as "not available".
15. **The Images resource sheet says images up to 20 MB** (`sheets/images.blade.php:26`). Nothing in the container proxy enforces or checks this (`EdgeContainerDeployer.php:1076-1095`). It is Cloudflare's limit, so it is probably fine, but it is not from config.

## 2. Gaps vs Laravel Cloud

- **Object storage has no S3 credentials, endpoint, public bucket, public URL, temporary URLs, CORS, or local-machine access.** Cloud injects `AWS_*` for any language and offers public/private buckets, `Storage::temporaryUrl`, auto-CORS and "View credentials" for Cyberduck. dply buckets are reachable only from inside the app, so they cannot serve files directly to browsers or accept direct uploads.
- **The `dply` Laravel disk is limited.** It lists via `GET /`, which returns at most 100 objects (the container proxy uses `list({limit:100})`), so `Storage::files()` silently truncates. HEAD calls (`fileSize`, `lastModified` in `DplyAdapter.php:102,118`) hit a proxy with no HEAD branch for object storage.
- **No bucket jurisdiction choice** (EU/FedRAMP) in Add resource. Repo-declared buckets pick EU from `organizations.edge_data_region`, but `ManagesStorageResources` notes that later API calls never send the jurisdiction header, so the Files, Usage and Empty-and-delete features probably fail on EU buckets.
- **Queues lack Cloud's operability.** Cloud has a failed-job dashboard, retry/delete, pause/purge, FIFO, job duration and memory metrics. The queue resource has no dead-letter queue (messages are dropped after 5 retries), no purge, no pause, no failed-job view (only [Queue workers] has one), and a fixed batch size of 10.
- **No object-storage egress or error metrics** (Cloud shows egress and 4xx/5xx).
- **Edge SQL has no backups/time travel UI, no export/import, and no read replicas**, even though D1 supports Time Travel.
- **No per-environment resources.** Cloud gives previews isolated queues. I could not confirm how preview sites get connections (not traced). If they share production's KV/R2/D1, preview writes hit production data. Worth checking (queues are known to be send-only on previews).
- **Worker-app queue consumption and State are unverified on Cloudflare.** `docs/EDGE_PLATFORM_STATUS.md` (T-018/T-019): no message has been seen going from a queue to the platform Worker to a Worker site's `queue()` export, and no State request has crossed into `dply-state-{site}`. The docs describe these as working.

## 3. Pricing / limits questions

- **No free allowance on D1, Queues, R2 buckets, State or Images.** They are charged from the first row or operation. D1 and Queues also carry a 25% markup (`EdgeDataUsageCost::cents`), while KV, R2 buckets, DO and Images carry none (`EdgeKvCost`, `EdgePlatformUsageCost`). Three different regimes for similar things is hard to explain on a pricing page.
- **KV's 1 GB free storage is per store** (`EdgeKvCost::cents` runs per namespace). Creating many small stores multiplies the allowance.
- **Sleeping a KV store erases the whole period's charges.** `EdgeKvCost::asleepNamespaces()` excludes every usage row for a namespace that is asleep *now*, including usage from before it slept. Sleep a store on the last day of the month and the month is free.
- **State billing is mixed with the container's own DO.** A container app's `dply-ctr-{site}` DO requests and duration are billed at DO rates whether or not State is attached. The collector also skips DO storage for namespaces with no requests that day (`EdgePlatformUsageCollector`, ponytail note), so storage is under-billed.
- **Delivery image optimization is unmetered and ungated.** It runs on the platform Worker, which the collector skips, while the Images resource is paid-only and billed at $0.50/1k. A trial user can transform unlimited images through `/_dply/image`.
- **Queue operations counts three per message**, so the effective price is $1.50 per million messages. That is fine, but worth saying on the pricing page.
- **The 5-message-retry cap and lack of a DLQ** make message loss silent.
- **D1 per-database size and row limits** (Cloudflare's 10 GB etc.) are not in config and are not shown anywhere. I left them out of the docs.

## 4. Suggested fixes

| # | Fix | Size |
|---|---|---|
| 1 | Point the bucket Costs copy at `r2_bucket_*` rates with no allowance and no markup | S |
| 2 | Add a Location select for SQL in the builder, pass it through, and store `location_hint` on the `EdgeDatabase` row; default to Automatic, not `wnam` | S |
| 3 | `deletePending`: keep a bound-elsewhere item in `$left`, or tell the user it was kept | S |
| 4 | Org Queues/Databases delete: reuse `deleteWaitsForDeploy` / `deleteAfterDeploy` and detach everywhere | M |
| 5 | Org Databases/Queues Attach: apply the runtime-kind and repo-name checks (move them into `EdgeContainerConnections::attach`) | S |
| 6 | Fix the Databases page copy for containers and `env.DB` | S |
| 7 | State footer button reads **Create** (treat `durable_object` as create mode) | S |
| 8 | Images/AI builder header: say the binding is `env.IMAGES` / `env.AI` | S |
| 9 | Store the prefixed host for Images/AI at creation (`resourceHost($site, $kind)`) | S |
| 10 | `EdgeKvCost`: exclude only usage dated after the store went to sleep (record `asleep_at`) | M |
| 11 | Meter `/_dply/image` or gate it by plan | M |
| 12 | Optional dead-letter queue per queue, and a purge action on the queue sheet | M |
| 13 | S3 credentials per bucket (R2 API tokens scoped to the bucket) and a public-bucket option | L |
| 14 | Send the R2 jurisdiction header on every bucket API call, then offer EU in the builder | M |
| 15 | The dply disk: paginate listing and support HEAD in the container proxy | S |
| 16 | Update the stale `PAID_ONLY` comment | S |
