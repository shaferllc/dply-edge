# ADR: dply Cache — managed cache as a first-class product

Status: accepted (2026-08-21)

Amends `docs/adr/managed-services-tier.md` (decision 4; see "Amendments"
below).

## Context

dply has four unrelated Redis paths and no cache product.

1. **`Site.meta['serverless']['cache']`** — `ProvisionServerlessCacheJob`
   creates a DigitalOcean Valkey cluster per function, polls it online, and
   writes `REDIS_*` + `CACHE_STORE=redis` into the managed env.
   `CachePanel` drives it. The whole resource is a schemaless JSON blob:
   no index lists it, no policy authorizes it, no billing observer sees it,
   and diagnosing one means opening the `meta` column.
2. **`CloudDatabase` with `engine=redis`** — an org-owned managed cache
   across six backends, created at `/cloud/databases`, attached through the
   `cloud_database_site` pivot, and **billed** by
   `CloudResourceCostCalculator` at `subscription.standard.cloud_database_cents`
   plus markup.
3. **`DedicatedRedisVm`** — a whole VM whose only job is Redis, via the
   `redis_server` install profile and `DedicatedCacheServerProvisionConfig`.
4. **`SiteBinding` types `redis` and `cache`** — VM-site configuration, where
   `redis` supplies the connection and `cache` is a *target-less driver
   choice* (`target_type='cache_driver'`, `target_id=null`) injecting only
   `CACHE_STORE`/`CACHE_PREFIX`. Both are `runtimes => ['vm']`.

Paths 1 and 2 provision byte-identical DigitalOcean Valkey clusters, and one
is billed while the other is not.

Meanwhile the defect that makes a cache necessary is already documented in the
codebase and unaddressed. `ServerlessQueueDoctorCommand` warns that a function's
default cache store is per-invocation, so `ShouldBeUnique`,
`WithoutOverlapping`, and `RateLimited` — which Laravel implements against the
*cache*, not the queue — "silently do nothing while appearing to work." That is
not specific to functions: a multi-replica Cloud container site has the same
per-container store and the same silent breakage.

dply Queue built `PostgresQueueLockStore` and four `/locks/*` routes to paper
over this. They are unreachable: `DigitalOceanFunctionsLaravelAdapter::inject()`
writes exactly one file into a customer app (the handler stub), no
`LockProvider`, and stock Laravel has no way to call a bespoke bearer-auth lock
API. `Cache::lock()` goes to the configured cache store or nowhere.

The reference point for the product shape is Laravel Vapor's caches: named
org-owned Redis clusters with create/scale/metrics/tunnel/delete, plus a
zero-config DynamoDB default that becomes the cache driver when no Redis is
attached.

## Decision

1. **Two tiers, one product.** A zero-config **shared** tier that is always
   available, and a **dedicated** tier for real Redis. This is Vapor's shape,
   and the shared tier is the part that fixes the silent-breakage defect for
   everyone rather than for whoever buys a cluster.

2. **The shared tier speaks the DynamoDB HTTP/JSON API over SigV4.**

   The binding constraint is not storage, it is *distribution*: customer apps
   must connect with no dply package installed. dply Queue already solved this
   by being SQS wire-compatible so the stock `sqs` driver works untouched. The
   cache pulls the same trick — `Illuminate\Cache\DynamoDbStore` ships in the
   framework and its entire API surface is six calls (`GetItem`,
   `BatchGetItem`, `PutItem`, `BatchWriteItem`, `UpdateItem`, `DeleteItem`).
   `flush()` throws by design, so there is no flush to implement.
   `aws/aws-sdk-php` is already a direct dependency.

   A RESP proxy was rejected. It would preserve the customer's config across an
   upgrade (`CACHE_STORE` stays `redis`), but it needs a long-running TLS TCP
   server, an open-ended command surface including the Lua that `PhpRedisLock`
   evaluates, and a fresh TCP connect per function invocation. Six HTTP
   handlers is a closed surface; a Redis dialect is not.

   Reselling Upstash and using real AWS DynamoDB were both rejected: each makes
   the free tier's COGS someone else's price list.

3. **One `Cache` model with a `tier`, mirroring `QueueNamespace.tier`.**
   `tier = shared` is the Postgres-backed store; `tier = dedicated` **points at
   a `CloudDatabase` row** and delegates provisioning, polling, resizing, and
   deletion to `Modules/Database`. Caches is the product face; `Modules/Database`
   stays the engine.

   Vapor's asymmetry — an invisible per-project default plus separately-named
   Redis caches — was rejected because dply is already living with it. Item 1 in
   the Context *is* an invisible per-site default, and reproducing that shape
   for the tier customers hit first means the common case has no surface.

   Consequence: rows appear that nobody created, because zero-config means the
   shared cache auto-provisions on first deploy. `ServerlessQueueProvisioner`
   sets that precedent. The index must distinguish "dply made this for you"
   from "you made this".

4. **A site attaches a cache through a `cache_site` pivot**, mirroring
   `cloud_database_site` — including a `CacheSite` pivot model and
   `connectionEnvVars()` / `connectionEnvKeys()` so detach strips exactly the
   keys attach added. Attach also sets `CACHE_STORE`. Runtime-agnostic.

   `SiteBinding` was rejected as the attachment mechanism. It is VM-only by
   construction, and its `cache` type is deliberately a target-less driver
   choice — that is why a separate `redis` binding exists. Promoting a kernel
   hub model to a four-runtime resource attachment is a larger change than
   adding a pivot that already has a working twin. On VM sites the attach
   action **writes a derived `SiteBinding` row** so the workspace Resources tab
   keeps telling the truth; the pivot is the source of truth, the binding is a
   projection. Two independent mechanisms was rejected outright —
   `ValidateBindingConnectivityJob` and `SiteResourceBindingResolver` both read
   `CACHE_STORE` and would disagree by runtime.

   **One cache per site in v1.** `cloud_database_site.env_prefix` exists so a
   site can hold two databases; the cache equivalent needs extra stores
   synthesized into `config/cache.php`, a file the customer owns. The pivot is
   shaped to allow more later; the rule is enforced now.

   `SESSION_DRIVER` is not touched. The queue-repair precedent in
   `ProvisionServerlessCacheJob` only repairs *provably broken* backends and
   explicitly refuses to repoint a deliberate choice; sessions on `cookie` are
   not broken.

5. **The shared store is an `UNLOGGED` Postgres table on a `dply_cache`
   connection, TTL-only, bounded by a byte quota.**

   `UNLOGGED` is the point. `QueueStoreIsolation` exists because `dply_queue`
   falls through to the primary `DB_*` by default, landing vacuum and WAL
   pressure on the Postgres serving the dashboard. A cache is higher churn than
   a queue. An unlogged table writes no WAL, and its price — truncated on crash
   recovery — is correct semantics for a cache, not a defect.

   LRU was rejected. In SQL it means `DELETE … ORDER BY last_accessed`, which
   requires writing `last_accessed` on every read, turning every `GET` into a
   write on the table we are already worried about. DynamoDB is TTL-only too.

   A dply-run multi-tenant Valkey was rejected: `maxmemory` is per-instance, so
   `allkeys-lru` evicts across tenant boundaries, and per-tenant caps mean one
   process per tenant — which is the dedicated tier.

   Expired rows are **filtered on read**, not merely swept. A lagging sweeper
   must never let a stale value surface. The sweep is a scheduled
   `DELETE WHERE expires_at < now()`, the shape
   `ReapExpiredTrustedSourcesCommand` already uses.

   `dply_cache` gets the same fall-through defaults and a `CacheStoreIsolation`
   doctor line. Sharing a database is surfaced, never failed closed.

6. **Credentials become a kernel `ServiceCredential` holding a grant list.**
   This is forced by the framework, not chosen: `config/cache.php`'s `dynamodb`
   store and `config/queue.php`'s `sqs` store both read `AWS_ACCESS_KEY_ID` and
   `AWS_SECRET_ACCESS_KEY`. A site using dply Queue and dply Cache cannot hold
   two key pairs without editing `config/cache.php`, which breaks the
   stock-driver promise decision 2 is built on. A credential therefore cannot be
   resource-scoped.

   `QueueCredential` migrates onto it. Grants read `queue:<ns>:push,pop` and
   `cache:<id>:read,write`. The secret stays **encrypted, not hashed** — SigV4 is
   an HMAC over a shared secret. Epoch-based bulk revocation and the resolver's
   negative caching carry over.

   `SigV4Verifier` moves to the kernel and `SERVICE` stops being a const: the
   service is read from the credential scope (`…/sqs/aws4_request` vs
   `…/dynamodb/aws4_request`) and becomes a *routing* input, with the grant check
   asserting it matches the resource addressed. Its docblock on why it does not
   call the SDK's signer, and its SDK-as-oracle test, move with it; the test
   gains a `dynamodb` case.

   Per-site credentials were rejected: `managed-services-tier.md` decision 5
   explicitly supports a site-less namespace held by an external app, and
   per-site credentials have nowhere to put that customer.

7. **The shared tier is free for every organization, bounded by a hard quota.**
   No paid capacity tiers.

   This diverges from Queue's rule and the divergence is deliberate — see
   Amendments. It also declines the paid-quota-tier design considered here:
   priced against a dedicated cluster at $15/mo for 1 GB and roughly 40× the
   speed, a paid shared tier is dominated. Anyone willing to pay for cache
   capacity is better served by the dedicated tier, so building Stripe roles, a
   tier-change UI, and a billability-flip notification would add machinery for a
   segment with no evidence behind it. Adding tiers later is additive; the
   quota-exceeded event is the evidence to wait for.

   **Quota: 64 MiB.** Generous for what decision 13 says the tier is *for* —
   locks and counters are bytes — and small enough that page-caching workloads
   reach it and get pushed to the tier that serves them properly. Maintained
   live, not sampled from `pg_total_relation_size`: a quota discovered ten
   minutes late is not a quota.

   **Amended during M1.** This originally said "a counter column updated in the
   same statement as the write", meaning a column on `managed_caches`. That is
   wrong, and wrong specifically in the configuration this ADR recommends: the
   counter would have to be maintained by the item store, which lives on the
   `dply_cache` connection — a *separate database* — and Postgres cannot update
   across databases. It would have worked in the shared configuration and failed
   silently in the isolated one.

   The counter therefore lives in `dply_cache_usage`, beside the items it
   counts, maintained by **statement-level** triggers with transition tables. It
   is statement-level rather than row-level so a sweep deleting five thousand
   expired items takes one lock on one counter row instead of five thousand.
   Maintaining it in triggers rather than in the store service is what stops it
   drifting: the sweeper, a flush, and a customer write all account for
   themselves without having to remember to.

   The quota *check* reads that counter before the write rather than inside it,
   so two concurrent writes can both pass at the boundary and overshoot by one
   item each. Deliberate: making it exact means serializing every write to a
   cache behind one row lock, which is a worse property than a bounded overshoot
   on a limit whose entire job is to stop unbounded growth.

   Revenue comes from the dedicated tier, which already bills through
   `CloudResourceCostCalculator`.

8. **`PostgresQueueLockStore` and the four `/locks/*` routes are retired.**
   They have no caller and no path to one that does not reintroduce the client
   library decision 2 exists to avoid. `DynamoDbLock` ships in the framework and
   works over the new endpoint with zero injected code on every runtime. Two
   lock stores with different semantics — Queue's 24h `MAX_TTL_SECONDS` and
   `ON CONFLICT … WHERE expires_at <= now()` takeover versus DynamoDB's
   conditional writes — would let one logical lock be held twice, which is worse
   than no lock store.

   The reasoning in that class about having no read-then-write window is not
   lost; it moves to the cache's lock path, where the same scrutiny applies.

   `ServerlessQueueDoctorCommand` currently tells operators to "move this
   function to dply Queue, which ships a shared lock store." That message
   changes in the same commit, or the doctor starts recommending a 404.

9. **A new `Modules/Cache`; shared machinery moves to the kernel.**
   `ServiceCredential` joins `app/Models`, the SigV4 verifier joins
   `app/Services`. Queue and Cache depend on kernel, never on each other.

   A Cache module depending on Queue was rejected: the credential model would
   live in the module least entitled to own it, and every later AWS-compatible
   service would import from Queue — which is how a module becomes a de facto
   kernel without anyone deciding it should.

   Folding into `Modules/Database` was rejected: that module owns *provisioning
   backends*, and the shared tier has nothing in common with `NeonBackend`
   except the word Redis. The dedicated tier's delegation is the right amount of
   coupling. *(2026-09-25: Neon was removed from dply-edge; databases are dply
   pods behind the same gateway as Valkey.)*

10. **Path 1 folds in, path 2 is adopted, paths 3 and 4 are left alone.**

    Leaving path 2 in place would ship two front doors for a byte-identical
    resource, which is the discoverability failure `managed-services-tier.md`
    was written to fix. Adoption is nearly free: a `Cache` row wrapping an
    existing `CloudDatabase`, no data movement and no provider calls.
    `/cloud/databases` stops offering `engine=redis` on create (`DatabaseCreate::ENGINES`).

    Path 3 is server provisioning — an install profile on a box the customer
    owns. Letting a `Cache` adopt a Redis VM as a third tier is a v2 extension
    point, not a v1 migration. Path 4 is handled by decision 4: a VM site
    pointing at Redis on its own server has no `Cache` row and should not.

    **Existing serverless caches are grandfathered.** Folding path 1 creates
    `CloudDatabase` rows, which `CloudResourceCostCalculator` bills — so the
    refactor would start charging customers who did nothing. `managed-services-tier.md`
    decision 7's notify-on-flip rule was written for a flip caused by a
    *customer* action; notifying someone that internal restructuring started
    billing them is a different promise. Adopted rows are stamped and excluded
    from the calculator. The stamp is indefinite debt, but it is honest debt
    with a clear predicate.

11. **Verbs: create, delete, scale, metrics, flush. No tunnel.**

    `cache:tunnel` exists in Vapor because AWS forces cache clusters into
    private subnets. DO Managed Valkey is publicly reachable over TLS with a
    password — which is why `RedisConnectionTls` exists — and the shared tier is
    an HTTPS endpoint. Neither has anything to tunnel to.

    **Scale is two mechanisms behind one word.** Dedicated means *resize* via
    the existing `ResizeManagedDatabaseJob`, with `CannotResizeManagedDatabase`
    already encoding which backends cannot. Shared means a quota change, which
    is instant and touches no infrastructure. The UI must not show a
    provisioning spinner for the second.

    **Amended during M3.** Resize is not reimplemented on the cache surface at
    all — the dedicated cache page links to the cluster's existing management
    page instead. Duplicating the control would be a second surface for one
    resource, next to the backups and deletion that already live there, and the
    ADR's own decision 3 says the dedicated tier delegates rather than grows its
    own path. Shared caches have no scale control, because decision 16 removed
    the paid quota tiers there was going to be something to change.

    **Flush is a dashboard operation.** `DynamoDbStore::flush()` throws because
    DynamoDB cannot truncate a table — but dply owns the shared store, so the
    control plane can do what the driver cannot. This is the most-wanted cache
    operation and it is one `DELETE`.

    Metrics ship for the shared tier only, where dply *is* the endpoint and hit
    rate, ops/min, and resident bytes are exact. Dedicated-tier metrics would
    come from DigitalOcean's API and are deferred rather than half-shipped.

    **Dashboard first.** Vapor is CLI-first because it has no dashboard; dply's
    centre of gravity is the workspace. `cache.read` / `cache.write` scopes are
    reserved in `config/product/cli.php` now; commands follow in v1.1.

12. **Naming follows dply Queue exactly**, because `cache.*` in
    `config/features.php` is already the per-engine install namespace consumed
    by `CacheEngineAvailability`, with a server-workspace Cache tab behind it.

    | Thing | Value |
    |---|---|
    | Product | dply Cache |
    | Nav label | Caches, third in the Services row |
    | Route | `/caches`, session-scoped |
    | Flag | `surface.cache` |
    | Config | `config/product/cache_service.php` |

    The config is **not** `config/product/cache.php`, for the reason
    `queue_service.php` is not `queue.php`: one configures the cache dply runs,
    the other the cache dply sells.

    The nav entry lands in `services-index-nav.blade.php` immediately; both nav
    components guard on `Route::has()` *and* the flag, so a parked entry does
    not render.

    **Amended during M5.** This originally specified a `billing_enabled` guard
    mirroring Queue's. It was built, and then removed, because decisions 7 and
    16 had already made it dormant: the shared tier is free with no tiers to
    switch on, and the dedicated tier bills as a `CloudDatabase` through
    machinery that is already live and correctly gated by the grandfather stamp.
    The dial gated one `isBillable()` method that nothing called.

    `managed-services-tier.md` decision 6 is explicit that dormant pricing dials
    "are how someone later ships a surprise charge" — so leaving a switch whose
    only effect would be to start billing, wired to nothing and defaulting off,
    is precisely the thing that ADR deleted `monthly_included_jobs` to avoid.

13. **The shared tier is a correctness cache, not a performance cache**, and
    the docs say so.

    An HTTPS round trip is 10–40ms against a local Redis `GET` at ~0.5ms. A page
    doing twenty cache reads goes from ~10ms of cache time to ~400ms. That is
    accepted, because the shared tier's competitor is not Redis — it is the
    per-invocation array store, i.e. nothing. Its job is to make
    `ShouldBeUnique`, `WithoutOverlapping`, `RateLimited`, and cross-container
    coordination *work at all*.

    Available mitigations, in order of cost: the SDK's Guzzle handler already
    reuses the TLS connection within a process, so a function pays one handshake
    per invocation; `Cache::many()`/`putMany()` map to the Batch operations, so
    N keys cost one round trip; `Cache::memo()` (`MemoizedStore`) dedupes repeated
    reads within a request but is a facade method, not a store config, so it is
    an opt-in code change. Region co-location is out of v1 scope.

    The docs page carries the performance envelope explicitly with a
    "don't do this in a loop" example, and the metrics panel shows ops/min so
    misuse is visible.

14. **The credential grant is the tenancy authority; `TableName` only selects.**
    `DYNAMODB_CACHE_TABLE` is client-controlled, so a `TableName` that chose the
    tenant would let anyone read any cache by editing one line of `.env`.

    `TableName` is the cache's **opaque id**, not its user-chosen name. The env
    var is machine-injected by attach, so readability buys nothing, while a
    guessable name would make a human-chosen string load-bearing in an
    authorization path. The `name` still exists for the dashboard and `/caches`
    URLs.

    Error rules:
    - Unknown table and unauthorized table return an identical
      `ResourceNotFoundException`. Distinguishing them is an enumeration oracle.
    - Over-quota is **`ValidationException`**, never
      `ProvisionedThroughputExceededException` — the latter is in the SDK's
      retryable set, so a customer at quota would get silent exponential backoff
      and see hangs instead of failures. A quota that manifests as latency is
      not a quota.
    - Every error is DynamoDB-shaped JSON (`__type` + `message`). A Blade error
      page reaches the SDK as an unparseable protocol error.

## Capability surface

The two tiers differ, and the differences are the honest upgrade drivers.

| | Shared | Dedicated |
|---|---|---|
| `get` / `put` / `increment` | yes | yes |
| `Cache::lock()` | yes (`DynamoDbLock`) | yes |
| `Cache::tags()` | **throws** — `DynamoDbStore` does not extend `TaggableStore` | yes |
| `Cache::flush()` from the app | **throws** by driver design | yes |
| Flush from the dashboard | yes | yes |
| Latency | 10–40ms | ~0.5ms |

`tags()` throwing at runtime must be documented before launch, not discovered
in production.

## Boundaries

| Concern | Owner |
|---|---|
| Cache resource, tiers, attachment | `Modules/Cache` |
| Shared-tier storage, quota, TTL sweep | `Modules/Cache` (`dply_cache` connection) |
| Dedicated cluster provisioning / resize | `Modules/Database` via `CloudDatabase` |
| Credential minting, grants, SigV4 | kernel — `ServiceCredential`, `SigV4Verifier` |
| What a dedicated cache costs | `subscription.standard.cloud_database_cents` |
| What a shared cache costs | nothing; it is free by decision 7 |
| Cache engines installed on BYO servers | `cache.*` flags, `CacheEngineAvailability` — unchanged |
| VM-site cache driver choice | `SiteBinding` type `cache` — unchanged, now also written as a projection |

## Amendments to `docs/adr/managed-services-tier.md`

- **Decision 4** ("Queue is free when it serves Serverless and paid otherwise")
  is **reopened, not merely diverged from**. Its premise is that Cloud, BYO, and
  Edge customers "have working alternatives." For cache that premise is
  materially weaker: the per-container store defect
  `ServerlessQueueDoctorCommand` describes applies to any multi-replica runtime,
  not just functions, so the population structurally needing a shared cache is
  larger than the population decision 4 exempts. Billing the *default* from byte
  one would leave Cloud sites choosing between a paywall and a store that is
  quietly broken. Decision 7 here makes the shared cache free for everyone. That
  Queues and Caches sit adjacent in the Services row under different free-tier
  rules is a product incoherence, and the honest resolution is that decision 4
  is the one to revisit — tracked in Open.
- **Decision 2** ("Services is nav, not a type") holds at N=4. Cache does not
  share a billing shape with any of the other three, which is the point that
  decision made. The comment in `services-index-nav.blade.php` naming three
  services and three billing shapes is updated.
- **Decision 9's** lock store is retired here; see decision 8.

## Sequencing

| M | Scope | Touches | Visible |
|---|---|---|---|
| **M0** | Kernel `ServiceCredential` + grants; SigV4 service routing; retire `PostgresQueueLockStore` and the four `/locks/*` routes; migrate Queue; fix the doctor message | Queue, kernel | no |
| **M1** | `Cache` model, `cache_site` pivot, `dply_cache` connection, UNLOGGED table, six handlers, quota accounting, TTL sweep, `CacheStoreIsolation` | Cache | no |
| **M2** | `/caches` index + show, create/delete, attach/detach, flush, `surface.cache`, Services nav, docs page | Cache, shell | yes |
| **M3** | Dedicated tier: `CloudDatabase` delegation, resize, `/cloud/databases` drops Redis, adopt existing rows | Cache, Cloud, Database | yes |
| **M4** | Serverless fold-in: migrate `meta['serverless']['cache']`, grandfather stamp, retire `CachePanel` + `ProvisionServerlessCacheJob`, auto-provision on first deploy | Cache, Serverless | yes |
| **M5** | Schedule the TTL sweep; `dply:cache:doctor`; delete the dormant billing dial; the decision 4 revisit | Cache, Billing | yes |

**M0 ships standalone** despite delivering no customer value. It is small — a
model, a grants column, a resolver change, four route deletions — and it is the
highest-risk-if-wrong piece in the plan, sitting in the security path with two
products depending on its shape. Reviewing a grant check inside a large M1
branch next to a new data plane is how a subtle authorization bug gets waved
through.

**M4 must follow M3.** Folding in the serverless caches rehomes live Valkey
clusters onto `Cache` → `CloudDatabase`, and that machinery *is* the dedicated
tier. Reversing the order leaves those clusters with nowhere to land.

Decision 7 removes most of what M5 was going to be: no Stripe roles, no cache
tier prices, no flip notification. What remained turned out to be the two
operational promises decision 5 made and the earlier milestones had not kept —
the TTL sweep was written but never scheduled, and the `CacheStoreIsolation`
doctor line existed as a class with nothing to print it.

## Consequences

**Queue** loses its lock store, its four lock routes, and its own credential
model. `QueueCredentialResolver` is rewritten against `ServiceCredential`.
`SigV4Verifier` leaves the module. Nothing customer-visible changes, because
`surface.queue` is off and no customer holds a key — which is exactly why this
is the cheapest moment it will ever be.

**Serverless** loses `CachePanel` and `ProvisionServerlessCacheJob`.
`ServerlessCostEstimator::cacheMonthly()` and the `cache` block in
`config/serverless/pricing.php` follow the cluster to the dedicated tier's
estimate. `ServerlessEnvironmentPreparer::mergeKeys()` gains the cache env map;
the `REDIS_*` keys it writes today are replaced by `CACHE_STORE=dynamodb`,
`DYNAMODB_ENDPOINT`, `DYNAMODB_CACHE_TABLE`, and the shared AWS pair.

**Cloud** loses Redis from the database create form and gains nothing else;
existing `engine=redis` rows are wrapped, not moved.

**Kernel** gains a hub model and a security-path service. `ModuleBoundaryTest`
is unaffected — nothing here points a module at the shell.

The customer-facing env for a shared cache is six keys, all machine-written:
`CACHE_STORE`, `DYNAMODB_ENDPOINT`, `DYNAMODB_CACHE_TABLE`,
`AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION`.

An upgrade from shared to dedicated changes `CACHE_STORE` from `dynamodb` to
`redis`, so it is a redeploy, not a hot swap. Vapor has the same seam.

## Implementation notes

- **A nullable dependency with a default hides a circular dependency.** M4's
  first attempt gave `ServerlessEnvironmentPreparer` a
  `?ServerlessCacheProvisioner $x = null`, on the reasoning that it kept the
  class constructible without the Cache module. What it actually did was hide a
  cycle — Deploy wanted Cache, Cache's attach wanted Deploy — which made
  container resolution fail, and because a default was available Laravel
  substituted `null` rather than throwing. The auto-wiring compiled, passed a
  test that resolved the provisioner directly, and would have done **nothing at
  all in production**.

  The fix is both halves: break the cycle by moving the shared piece
  (`SiteEnvFile`) into the kernel — the same remedy `ModuleBoundaryTest` prints
  for a module→shell edge — and then make the dependency **required**, so it can
  never silently be absent again. A nullable-with-default dependency on a
  behaviour you rely on is not defensive; it converts a loud failure into a
  silent one.


- **A test handler must reproduce Guzzle's error contract.**
  `Aws\WrappedHttpHandler` treats *any fulfilled promise* as success and turns
  only a **rejected** one into an `AwsException`. The real `GuzzleHandler` gets
  that free, because Guzzle's `http_errors` raises on 4xx. A test `http_handler`
  that fulfils on a 400 therefore makes every error path silently pass:
  ConditionalCheckFailed reads as success, so `add()` always returns true and a
  lock is never actually held. `tests/Feature/Cache/DynamoDbCompatibilityTest`
  rejects on `>= 400` for this reason, and the four conditional-operation tests
  are meaningless without it.

- **Expression forms are matched, not parsed.** `DynamoDbStore` emits exactly
  four `UpdateExpression` strings and four `ConditionExpression` strings. The
  adapter compares against those literals and answers anything else with a
  `ValidationException`. A general expression evaluator would be a large amount
  of security-relevant surface built to serve eight known strings, and
  mis-evaluating a condition on a lock is far worse than refusing it.

## Open

- **Queue's decision 4** — whether Queue's Serverless-only free tier survives
  now that Cache is free for everyone. Reopened above, not settled here.
- **`business` entitlement in `config/product/queue_service.php`** reads
  `max_namespaces => 0` where `free` reads `1`. Either `0` is an unlimited
  sentinel or the largest plan is denied the product. Unresolved; the Cache
  entitlement block should not copy the ambiguity.
- **Dedicated-tier metrics** — deferred with decision 11; needs a DigitalOcean
  metrics integration that does not exist.
- **Redis VMs as a third tier** — decision 10 leaves `DedicatedRedisVm` alone.
  Adoption is the obvious v2 move.
- **Region co-location** for the shared endpoint — declined in v1 by decision
  13; the ops/min metric is the signal that would justify revisiting.
