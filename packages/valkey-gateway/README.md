# valkey-gateway

> Now also runs dply's scale-to-zero **databases** (ruling r-r5h70qp951w28qrh, T-020). Postgres is in; MySQL and MongoDB are next. See *Databases* below.

dply's own managed Valkey (ruling r-72p0gkdn9dqwxqha, ticket T-021). One Go
service is both the TLS proxy and the operator:

- **One pod per tenant**, with `maxmemory` and a matching container memory limit.
  One app cannot evict another's keys.
- **Clients connect with TLS to `{tenant}.{domain}:6380`.** The gateway reads the
  SNI name, wakes the tenant if it is asleep while the client waits, and pipes bytes.
- **Flex tenants sleep** after `sleep_after` seconds with no client traffic. The
  gateway streams every key (`SCAN` / `DUMP` / `PEXPIRETIME`) to S3 (R2 in
  production) and deletes the pod. It also snapshots every 15 minutes while awake.
  The next connection restores the keys with `RESTORE … ABSTTL`, so a key never
  outlives its TTL while asleep.
- **A warm pool makes wakes fast.** `POOL=250:2,1024:1` keeps ready, empty pods
  per size. A wake adopts one (a label patch with a resourceVersion check, so two
  gateways cannot take the same pod), turns on the tenant's login, and restores
  its keys. With no warm pod of that size, it starts a new pod instead.
- **Logins are ACLs.** Every pod starts with the gateway's `dply-admin` user and
  `default` off. On wake the gateway turns `default` on with the tenant's password
  and without `CONFIG`, `DEBUG`, `ACL`, `REPLICAOF`, `SHUTDOWN`, `SAVE`, `SYNC`,
  `MONITOR` and similar. A container that restarts in place gets its login and
  keys back from the reaper.
- **Pro tenants** (`persistent: true`) never sleep. They use AOF on a volume.
- **Kubernetes is the only state.** A tenant is a Secret `vk-{id}`; an awake
  tenant also has a Pod `vk-{id}`.

Control API (bearer token): `PUT /tenants/{id}` `{password, memory_mb,
sleep_after, persistent}`, `GET /tenants/{id}`, `POST /tenants/{id}/sleep`,
`DELETE /tenants/{id}`, and `GET /usage`.

**Billing.** Each tenant Secret carries `dply.dev/awake-since` while a pod runs
and `dply.dev/awake-seconds` as the total of finished stretches. `GET /usage`
returns the running total per tenant. The app's hourly
`dply:edge:collect-valkey-usage` adds the change to `edge_redis_usage.awake_seconds`.
`EdgeRedisCost` prices it per second by class, capped at the monthly price.

## Redis over REST

`rest.go` serves Redis over HTTPS on `REST_ADDR` (default `:8443`, `off`
disables it), so plain `fetch()` and the common Redis REST clients work
against a tenant from Workers and serverless functions:

```bash
curl https://{tenant}.{domain}:8443/get/k -H 'Authorization: Bearer {tenant password}'
```

- `POST /` with `["SET","k","v"]`, path style `GET /set/k/v`, `POST /pipeline`
  and `POST /multi-exec`; replies are `{"result": …}` / `{"error": …}`, and
  `Dply-Encoding: base64` (or `Upstash-Encoding`) is honoured.
- The token is checked before the tenant wakes. Connections are pooled per
  tenant and pod IP.
- Blocking, pub/sub and connection-state commands are refused.
- **Lua:** `EVAL`, `EVALSHA`, `SCRIPT` and `FCALL` pass through. Some clients'
  scripts start `#!lua flags=allow-key-locking`, which Valkey rejects; unknown
  flags are dropped and the client's SHA is mapped to the rewritten script's,
  so `EVALSHA` keeps hitting. A sleeping tenant loses its script cache; clients
  resend with `EVAL` on `NOSCRIPT`.
- **Billing:** commands are counted per tenant and added to the
  `dply.dev/rest-commands` annotation every 30 s; `GET /usage` returns
  `rest_commands` next to `awake_seconds`.

On the local cluster, `deploy/local-verify-rest.sh` (after `deploy/local-up.sh`)
checks every shape, Lua, refusals, a bad token, a REST request waking a
sleeping tenant, and the count in `/usage`.

Tests: `go test ./...`. The live ones need a Valkey and, for the SDK check,
the npm packages:

```
docker run -d --rm --name vg-rest-test -p 16379:6379 valkey/valkey:8-alpine valkey-server --requirepass testpass
(mkdir -p /tmp/upstash-sdk && cd /tmp/upstash-sdk && npm init -y && npm i @upstash/redis @upstash/ratelimit)
VALKEY_TEST_ADDR=127.0.0.1:16379 VALKEY_TEST_PASSWORD=testpass UPSTASH_SDK_DIR=/tmp/upstash-sdk go test -count=1 ./...
```

## Local run (OrbStack Kubernetes)

```
deploy/local-up.sh       # build, deploy gateway + SeaweedFS S3 (stands in for R2)
deploy/local-verify.sh   # end-to-end checks
```

Verified locally on 2026-09-24; rerun 2026-09-26 with the Postgres checks, all
passing. Local clusters have no `pro-16` / `pro-64` pools, so `deploy/local.yaml`
sets `PRO_NODE_POOLS=off` and Pro tenants run on the local node (production
leaves it unset). Checks:

- first connection starts the tenant, it reads back, and its awake time is counted;
- the tenant gets NOPERM on `CONFIG`;
- a key's expiry survives the sleep, and the wake adopts a warm pod in under a second;
- another tenant can't see the data, a wrong password is refused, and an unknown host is dropped;
- `maxmemory` is enforced (OOM);
- an idle tenant sleeps, a snapshot is stored, and waking restores the data;
- a pro tenant keeps its data across a pod restart.

Timings on OrbStack, including a `docker exec` per call:

| | before the pool | with the pool |
|---|---|---|
| first start | ~1.9–2.5 s | ~0.2–1 s |
| wake with restore | ~2.5–3.2 s | ~0.5–0.7 s |
| pro first start (volume provisioning) | ~6–12 s | same (pro is not pooled) |

Most of what is left is the Kubernetes API list and patch in `adopt`
(`DEBUG_TIMING=1` logs each step). An informer holding the pool in memory
would remove the list.

## Pool and failover

- Warm pool pods come from a watch (`poolwatch.go`), so a wake that adopts one
  doesn't wait on a Kubernetes LIST. Adoption stays a compare-and-swap PATCH.
- `POOL` is each size's floor. A size keeps as many warm pods as it handed out
  in the last 15 minutes, up to four times its floor.
- Two gateways run active/standby (`leader.go`): they share a Lease, the holder
  labels its pod `role=active`, and the Service only sends traffic there, so the
  in-memory idle tracking stays correct. Takeover: ~5 s on shutdown, ~15 s when the
  active one freezes or its node dies (verified locally 2026-09-25 with SIGTERM,
  SIGKILL and a paused container). Active/active would need shared activity tracking.
- Production runs on DOKS nyc3 (`deploy/valkey`): wildcard certificate for
  `*.cache.dply.io` / `*.db.dply.io`, R2 snapshots, ports 6380 (TCP), 8443
  (REST) and the database ports on the load balancer.

## Databases (T-020)

One pod per database, created on first connection, with its data on a
node-local volume (`local-path`), so the pod is pinned to its node. `dbagent`
(this module, `dbagent/`) is PID 1 in the pod; the gateway calls it to start,
stop, and set the app's login. Sleep **parks** the pod: the database process
stops cleanly and the pod's memory *request* drops to 16 Mi in place
(Kubernetes 1.33 in-place resize), freeing the node's room. The limit stays:
the kubelet will not set a limit below current use, and a stopped database
still has its files in reclaimable page cache. Wake grows the request back and
starts the process. The pod and its disk never move, so there is no attach.

- Postgres: clients use `sslmode=require` to `{id}.{domain}:5432`. The gateway
  answers the SSLRequest (and PG17 direct TLS), routes on SNI, and pipes to the
  pod. Plain-text connections are refused. The app logs in as `app` (not a
  superuser) to database `app`; `dply_admin` only logs in over the pod's socket.
- Image: `docker build -f dbagent/Dockerfile.postgres -t dply/postgres:17 .`
- Tenant: `PUT /tenants/{id}` with `"engine": "postgres", "disk_gb": N`.

Verified locally 2026-09-24 (`deploy/local-verify.sh`, 13 Postgres checks):

| | time |
|---|---|
| first start (new pod, volume, initdb) | ~7–16 s, once per database |
| awake query (TLS + auth) | ~130 ms |
| wake from parked | ~300 ms at the client; ~140 ms in the gateway (resize 5 ms, start 110 ms, login 19 ms) |

Locally the Service exposes Postgres on 15432, because the Mac usually has its
own Postgres on 5432.

Not done: MySQL (auth-terminating proxy), MongoDB, wal-g to R2, node-loss
restore, per-GB billing, Laravel side, moving Neon and PlanetScale users.
