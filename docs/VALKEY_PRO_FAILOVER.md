# Valkey Pro failover: design and the decision it needs

**Decision needed:** whether Pro Valkey gets a standby replica, and whether
customers pay for it. Everything else below is ready to build.

## Today

A Pro store (`pro_5g`, `pro_12g`) is one always-on pod with an append-only
file on a DigitalOcean volume, on the `pro-16` pool. If its node dies,
Kubernetes reschedules the pod, but the volume first has to detach from the
dead node. DigitalOcean takes several minutes to release it (force-detach is
around 6 minutes). **Expect 3–8 minutes of "connection refused"**, then the
store comes back with its data up to the last fsync (at most a second).

Flex stores are not affected the same way: they have no volume, snapshot to
R2 when they sleep, and a wake takes under a second on any node.

## With a replica

- A second pod of the same size on **another node** (anti-affinity), with its
  own volume, running `REPLICAOF <primary>`.
- The gateway is already the single active leader (`leader.go`), so it is the
  arbiter: when the primary stops answering its health check for ~10 s, it
  runs `REPLICAOF NO ONE` on the replica, points the tenant at it, and
  re-creates a new replica. No split brain: only the active gateway decides,
  and a returning old primary is demoted before it takes traffic.
- **Failover time: ~10–15 s** instead of minutes.
- **What can be lost:** replication is asynchronous, so writes from the last
  fraction of a second before the crash. For caches this is nothing; for
  queues, a job pushed in that window. Laravel's `WAIT 1 0` after pushes would
  close that at a latency cost; not worth it by default.

Work: replica pod + volume per Pro tenant, a health loop in the active
gateway, promotion and re-pairing, `ROLE` checks on reconnect, and local
checks in `deploy/local-verify.sh` (the local cluster now runs Pro tenants,
`PRO_NODE_POOLS=off`). Roughly 2–3 days including failure tests (kill the
primary's node, partition, gateway failover mid-promotion).

## Cost

A replica doubles the tenant's memory and disk on the Pro pool: `pro_5g`
costs dply about twice as much to run. Options:

1. **Include it in Pro:** simplest for customers, cuts Pro margin in half.
2. **"High availability" add-on:** a checkbox on Pro stores at roughly the
   size's price again. Customers who need it pay for it.
3. **Don't build it yet:** Pro stores already keep their data; the cost of a
   node failure is minutes of downtime, which may be fine until a customer asks.

## Recommendation

Option 2 when the first customer runs a Pro store in production; option 3
until then. There are no Pro tenants on the cluster today.
