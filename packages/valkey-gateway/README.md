# valkey-gateway

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

## Local run (OrbStack Kubernetes)

```
deploy/local-up.sh       # build, deploy gateway + SeaweedFS S3 (stands in for R2)
deploy/local-verify.sh   # end-to-end checks
```

Verified locally on 2026-09-24. All 17 checks pass:

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

## Not done yet

- An informer for the pool, to take the Kubernetes list off the wake path.
- Pool size per class should follow demand. It is a fixed `POOL` setting today.
- Moving existing Upstash users across. The Laravel client, Resources UI, and per-second billing are in.
- A production cluster (DOKS), with R2 credentials and a real wildcard certificate.
- The gateway itself is one replica. A second one needs shared activity tracking
  (for example, an annotation on the pod) before idle sleep is safe.
