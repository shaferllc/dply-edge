# Report: Resources, part 2

Pages: `resources/databases`, `resources/valkey`, `resources/external-redis`, `resources/realtime`, `resources/database-pools`, `resources/vector-search`, `resources/ai`, `resources/browser-rendering`.

Paths are relative to the repo root. `S/` = `resources/views/livewire/sites/edge/workspace/partials/`.

## 1. Mismatches

### Data loss and billing (fix first)

1. **Removing or switching a database deletes it and its backups, and the UI does not say so.** The footer reads "Remove the database from this app?" / "Switch to :engine?" followed by "The app uses it from the next deploy." (`S/sheets/database.blade.php:111-117`). But `EdgeAppDatabase::sync()` → `release()` destroys the database right away (`app/Modules/Edge/Services/EdgeAppDatabase.php:195-206`). The gateway's `deleteTenant` → `deleteDatabase` then removes the pod, the volume, and `tenants/{id}/` backups (`packages/valkey-gateway/main.go:778-790`, `database.go:568-576`). Nothing is kept for restore. The docs page carries a `[!WARNING]`.
2. **The card's Sleep button on a Pro Valkey probably keeps billing.** The card copy says "Asleep. The address comes off the app on the next deploy. Not billed until you wake it." (`S/connection-card.blade.php:47`). But `sleepConnection` only flips `asleep` in meta and never calls the gateway (`app/Livewire/Sites/Edge/Workspace/Resources.php:2020-2075`). Pro sizes are `persistent` and never idle-sleep, so awake seconds keep accruing and `EdgeRedisCost` bills them up to the cap. Flex stores do stop billing, but only after they idle out once the app stops connecting. Not verified end to end.
3. **The Valkey eviction copy contradicts itself.** The Overview card says "Writes are refused. Nothing is evicted." (`S/sheets/valkey.blade.php:59`). The Statistics footnote says "When full it evicts keys that expire" (`:165`). The pod runs `--maxmemory-policy volatile-lru` (`packages/valkey-gateway/kube.go:468`, `resp.go:133`), so the footnote is right: TTL keys are evicted, and writes of non-TTL keys fail with OOM. The docs follow the code.
4. **The Valkey billing hint is wrong.** `KINDS['redis']['hint']` says "Commands and storage are billed with usage." (`app/Modules/Edge/Support/EdgeContainerConnections.php:36`). Billing is per second awake, capped monthly (`app/Modules/Billing/Services/EdgeRedisCost.php`). That hint is the first thing a user reads in **Add a resource**.
5. **The Workers AI billing note is misleading.** "AI usage is billed by Cloudflare in neurons, per model. It is not shown on this page yet." (`S/sheets/ai.blade.php:36`). Nothing in `app/Modules/Billing/Services/` meters AI, Vectorize, Browser Rendering, or Hyperdrive. `EdgeContainerConnections::PAID_ONLY` says they are "Unmetered on our shared account" (`EdgeContainerConnections.php:62-66`). So the customer is not billed; dply absorbs the cost. The docs say plainly that it is not metered per organization today.
6. **The database's monthly price is shown lower than it bills.** `EdgeAppDatabaseCost::hourly()` rounds to 3 decimals before `monthly()` multiplies by 720 (`app/Modules/Billing/Services/EdgeAppDatabaseCost.php:22-35`). The Resources page does the same with awake hours (`Resources.php:2638, 2648`). As a result 0.25 CU shows $23.76/mo but bills $23.85 (0.033125 × 720), and 0.5 CU shows $47.52 but bills $47.70.
7. **"Applies at the relay right away" is too strong** for Realtime settings (`S/sheets/realtime.blade.php:245`). The relay README says limits (`clientEvents`, `maxMessageBytes`) are read at connect time and reach existing sockets only on reconnect. `docs/edge-realtime.md` says KV reads can lag about 60 s. The docs say open sockets keep their old limits until they reconnect.
8. **The Realtime sheet warns about "usage credit" when there can be no credit.** "This counts against the usage credit until a card is on the account." (`S/sheets/realtime.blade.php:122`). Creating Realtime requires a card (`S/sheets/connection.blade.php:47-51`), so this state only happens after a card is removed. The copy also refers to a usage-credit model the Free-plan removal retired.
9. **dply Valkey is documented as "on every plan" but the UI requires a card.** The comment says "dply Valkey is on every plan; only the advanced Pro sizes … need a paid plan" (`EdgeContainerConnections.php:342`). Yet create requires `cardOnFile()` (`Resources.php:1883-1886`), which is `onAnyPaidPlan()`. An org on the card-less generic trial (`trial_ends_at`, migrated from Free) cannot create Valkey, Realtime, or a database even though its tier is Pro.
10. **External Redis sits under "dply Valkey" in Add a resource.** There is no separate entry: users paste an address via **dply Valkey → Attach existing** (`S/sheets/connection.blade.php:40-45, 130-139`). "Attach existing" suggests picking an existing dply store, not pasting a third-party URL.
11. **"Sleep" has two meanings on the same card.** Valkey's idle sleep (automatic, keys kept, not billed) is different from the card's **Sleep** button (manual, removes `REDIS_URL` on the next deploy). For Realtime, **Sleep** closes sockets immediately, with no redeploy. The docs explain all three; the UI does not distinguish them.
12. **Pools from containers are unverified.** The sheet itself says "Not yet verified from a container" (`S/sheets/pool.blade.php:53`). The pool is offered to container apps anyway (`EdgeContainerConnections::KINDS` has no runtime restriction for `database_pool`).
13. **Vectors cannot be written from a container** (`S/sheets/vectors.blade.php:59`). A container-only app can create an index it can never fill.
14. **The Valkey gateway README is stale.** It says "MySQL and MongoDB are next" and "Not done: MySQL …, MongoDB, wal-g to R2 …, Laravel side" (`packages/valkey-gateway/README.md` top and *Databases* section). All of these ship now (`dbagent/mysql.go`, `mongo.go`, `RestoreEdgeDplyPostgresJob`).
15. **Realtime per-app host is off by default.** `edge.realtime.per_app_hosts` defaults to false (`config/product/edge.php:399`, `.env.example:737`). The brief and `docs/edge-realtime.md` present `{label}.realtime.dply.io` as the app's host. Unless production sets `EDGE_REALTIME_PER_APP_HOSTS=true`, apps get the shared `realtime-apps.on-dply.site`. The docs tell users to copy **Host** from **Credentials** rather than naming a pattern.
16. **Realtime has no "Attach existing" in the UI, but the backend supports it.** `EdgeContainerConnections::catalog()` handles `realtime` (`:879-882`), but the builder never shows the segmented control for it. Harmless, but it is dead code or a missing feature.
17. **Only one Valkey per app is priced.** `EdgeRedisCost::valkeyCents` returns after the first Valkey connection per site (`EdgeRedisCost.php:66`). The UI blocks a second Redis today (`Resources.php:1875-1880`), so this is latent, not live.
18. **The postgres rate comment is stale.** `config/product/dply.php:355` still says "at Launch list price" (Neon's pricing), but databases now run on dply's own cluster. The rate is fine; the provenance is wrong.
19. **Valkey prices are not in config.** They live in `EdgeValkey::CLASSES` (`app/Modules/Edge/Support/EdgeValkey.php:27-35`), unlike every other rate (`config/product/dply.php`). STYLE.md says prices come from config.

## 2. Gaps vs Laravel Cloud

Checked against Cloud's Postgres, Valkey, and WebSockets pages (fetched 2026-09-26). Items not on those pages are marked unverified.

- **Database compute range.** Cloud Postgres autoscales between a min and max of 0.25–10 CU (up to 10 vCPU / 40 GB). dply offers fixed 0.25 or 0.5 CU (`EdgeDplyDatabase::OFFERED_SIZES`) with no autoscaling between them (the Postgres "scales from 1/4 vCPU; priced at full size" note aside).
- **Database storage.** Cloud storage autoscales with data. dply disks are fixed at 1, 5, 10, or 25 GB, grow only by hand, and top out at 25 GB.
- **Backup retention.** Cloud lets you choose the PITR retention days. dply is fixed at 7.
- **Clusters and sharing.** A Cloud cluster holds up to 500 databases and attaches to any environment in the region. A dply database belongs to one app; previews and other apps cannot attach it.
- **Region.** Cloud picks the region per cluster to match compute. dply has one (New York) today (`docs/DATA_REGIONS.md`).
- **Connection pooler.** Cloud offers a pgbouncer endpoint (`-pooler` host, 10,000 connections). dply offers a Hyperdrive pool, verified only from Workers.
- **Metrics.** Cloud shows CPU, write throughput, and storage charts. dply has live stats, top queries, and health, but no time-series charts beyond awake hours and size.
- **Valkey eviction policy is fixed** at `volatile-lru`. Cloud lets you pick any of the Valkey policies (it recommends `allkeys-lru`) plus `noeviction`. For a pure cache without TTLs, dply's policy means OOM errors instead of evictions.
- **Valkey auto-upgrade.** Cloud can move a cache to the next size automatically, with a notification. dply has none.
- **Valkey sharing.** Cloud caches are org resources attachable to several apps. A dply Valkey belongs to one app.
- **Valkey sizes.** Both start at 250 MB. dply stops at 12 GB (25 and 50 GB are defined but not offered). Cloud's max is on its pricing page (not checked).
- **Documented connection and message limits.** Cloud states 10,000 concurrent connections per Valkey and publishes per-size message-size limits. dply documents neither (unverified what the gateway enforces).
- **WebSockets sharing.** A Cloud WebSocket cluster is split across several apps and environments. A dply Realtime app belongs to one site, and the UI has no **Attach existing** for it.
- **WebSockets region.** Cloud picks a region per cluster. dply's relay runs on Cloudflare everywhere, which is arguably better.
- **Realtime webhooks, `batch_events`, and channel-info/users HTTP APIs.** The relay has none (it only has `/events`, `/stats`, `/disconnect`). Cloud's page does not say whether its Reverb supports them, so this gap is unverified against Cloud. Open-source Reverb does serve the Pusher channel-info and users endpoints, so apps migrating from Reverb may call them.
- **AI, Vectorize, and Browser** have no Cloud equivalents on these pages. Nothing to compare.

## 3. Pricing and limits questions

- **Unmetered paid-only resources (AI, Vectorize, Browser, Hyperdrive).** One Pro customer running an LLM in a loop costs dply real money with no cap. Either meter them (`EdgePlatformUsageCost` already reads Cloudflare usage) or add a per-org rate limit. Also decide whether the docs should promise "included".
- **The plan's `databases` allowance (Pro 10, Team 50) counts D1 only** (`EdgeContainerConnections::creationError`, `:966-977`). dply Postgres/MySQL/Mongo have no plan limit other than one per app. Is that intended? The pricing page may imply otherwise.
- **Realtime `messages` counts both directions** (publishes in plus frames out; `EdgeRealtimeUsageCollector.php:85`). One publish to 1,000 subscribers is 1,001 messages. The ruling says so, but customers comparing against Pusher (which counts that as 1,000) should see it stated. The docs do state it.
- **Realtime peak is summed across shards,** so it overstates the real peak (docs/edge-realtime.md). Only relevant above 10,000 connections.
- **Valkey Flex 250 MB costs $6/mo capped** against a trial cap of $5. A trial user who leaves one Valkey on "Stays on" uses most of the trial spend limit on it alone.
- **Database storage is billed on the provisioned disk, not bytes used** (`EdgeValkeyUsageCollector.php:183`). That is reasonable but should be stated on the pricing page. The docs state it.
- **Realtime `default_max_connections` is 200.** Pro allows 1,000. Fine, but raising the size later is only possible up to the plan cap, and shards only ever grow.

## 4. Suggested fixes

| # | Fix | Size |
|---|---|---|
| 1 | Database footer: when the saved engine is a dply engine and the new one differs, say "This deletes the :engine database and its backups now." Offer **Export now** first. | S |
| 2 | `sleepConnection` on a dply Valkey: call the gateway to sleep it (`POST /tenants/{id}/sleep`), or change the card copy for Pro sizes to "Still billed while it stays on." | S |
| 3 | Valkey Overview card: "Keys with an expiry are evicted; other writes are refused." | S |
| 4 | `KINDS['redis']['hint']`: "Billed per second while awake, up to a monthly cap." | S |
| 5 | AI sheet note: "Included with paid plans. Usage is not metered per organization yet." Then decide on metering. | S (copy) / M (metering) |
| 6 | `EdgeAppDatabaseCost::monthly()`/`daily()`: compute from the unrounded rate. | S |
| 7 | Realtime settings: "Applies within a minute. Open connections pick up new limits when they reconnect." | S |
| 8 | Remove the Realtime "usage credit" note, or reword it to "Add a card to keep Realtime running." | S |
| 9 | Split **External Redis** into its own **Add a resource** row, or rename the Valkey segment to **Paste an address**. | S |
| 10 | Hide **Database pool** for container apps until verified, or verify it and remove the warning. | S / M |
| 11 | Update the valkey-gateway README *Databases* section. | S |
| 12 | Confirm production sets `EDGE_REALTIME_PER_APP_HOSTS=true` (and `EDGE_REALTIME_CUSTOM_DOMAINS`). If so, flip the config default so dev matches. | S |
| 13 | Move `EdgeValkey::CLASSES` prices into `config/product/dply.php`. | S |
| 14 | Offer 1 CU and 2 CU database sizes (node pool) and a 50 GB+ disk. | L |
| 15 | Document or implement Realtime webhooks and `batch_events`. | M |
| 16 | Realtime create sheet: disable the **Max connections** sizes above the plan cap, as the Settings tab does (`S/sheets/connection.blade.php`, the `MAX_CONNECTION_SIZES` loop). Today the error only appears on **Create**. | S |
| 17 | Realtime Overview: the Messages note says "collected daily" (`S/sheets/realtime.blade.php:104`), but the collector runs hourly (`app/Console/Scheduling/DplySchedule.php:169-172`). | S |
| 18 | Offer a choice of Valkey eviction policy (at least `allkeys-lru` for cache-only stores). | M |
