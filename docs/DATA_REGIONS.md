# Where dply data lives: one region or several

**Decision needed.** Today every dply database and Valkey store is in one
DigitalOcean cluster in New York (`nyc3`, `deploy/valkey/terraform`). Cloudflare
places container apps by *region*, not city, so an app's distance to its data
varies from deploy to deploy. This note gives the options and their prices.

## What distance costs (measured on waypost, 2026-09-25/26)

Round trip from inside the container to the dply database, and what it did to a
database-backed queue:

| App landed in | Round trip | Queue (1 worker, 1 process) |
|---|---|---|
| Newark `ewr01/05` (ENAM) | 13 ms | ~6.8 jobs/s |
| Toronto `yyz04` (ENAM) | 52 ms | — |
| Atlanta `atl13` (ENAM) | 72–78 ms | — |
| Dallas `dfw14` (WNAM) | 145 ms | ~2 jobs/s |

A page that makes 10 queries pays the round trip 10 times: ~130 ms in Newark,
~1.5 s from Dallas. Europe or Asia would be worse still.

What dply already does: apps with dply data default to ENAM
(`DPLY_EDGE_DATA_REGION`), every deploy records where it landed, and a far
instance is restarted once or twice to be placed again. That last step is best
effort: Cloudflare put waypost in Atlanta three times in a row.

## Cost of a region

Each cluster needs, before any customer data:

| | Size | $/mo |
|---|---|---|
| flex pool (gateway + flex Valkey), 2 nodes | s-2vcpu-4gb | 48 |
| db pool (databases), 2 nodes | s-2vcpu-4gb | 48 |
| Load balancer | | 12 |
| Control plane | (free; HA +40) | 0 |
| **Baseline** | | **~108** |

Pro Valkey pools (`pro-16`, $84/node) stay at zero nodes until used, as today.
Backups already go to R2, so no per-region storage account.

## Options

### A. Stay in one region (now)

$0 more. Fine while customers are on the US east coast. Apps elsewhere pay
50–150 ms per query, and dply cannot do better than "restart and hope".

### B. Add regions on demand; data follows the app's region

A database or Valkey store is created in the region its app runs in (ENAM →
`nyc3`, WNAM → `sfo3`, WEUR → `ams3`/`fra1`, APAC → `sgp1`), and the app is
pinned to that region. Every query stays in-region: expect 10–25 ms wherever
the app lands inside it.

Work: one Terraform workspace per region (the module is already
region-parameterised), a gateway per region with its own hostnames
(`{id}.db.sfo.dply.io`), `region` on the tenant record and in the app's meta,
and region-aware create/restore in the workspace. Existing data stays where it
is; moving a store between regions is a dump and restore.

Cost: ~$108/mo per region added, only when the first customer needs it.

### C. Europe only, for EU jurisdiction

Same as B, but only an EU cluster (`fra1`), because an app set to the EU
jurisdiction cannot legally keep its data in New York. Cloudflare already keeps
those apps in Europe, so today they are the worst case: EU app, NYC data.

## Recommendation

**B, starting with WNAM (`sfo3`)**, because US-west apps are already landing in
Dallas and paying 145 ms. Add **EU (`fra1`)** with the first EU-jurisdiction
customer (option C is B's first step there). Stay on A until one of those
customers exists: the baseline is ~$108/mo per region with nobody in it.

## Status (2026-09-26)

Built, with one region live (`nyc3`):

- **Control plane is region-aware.** `ValkeyRegions` (config
  `edge.valkey.regions`; the first entry is the original setup, more via
  `DPLY_VALKEY_REGIONS`). Valkey targets carry their region
  (`valkey:{region}:{id}`), databases store `region`, every gateway call routes
  by it, and new data goes to the app's region (`DataRegion`). Apps run in the
  Cloudflare region paired with their data's region.
- **Adding a region** is `regions/sfo3.tfvars` (its own Terraform workspace),
  then `DOMAIN=sfo.dply.io ./apply.sh`, which deploys the gateway, gets its
  certificate and writes `*.cache.sfo` / `*.db.sfo` into **Cloudflare DNS**
  (dply.io's nameservers are Cloudflare's; DigitalOcean's copy of the zone is
  unused), then one entry in `DPLY_VALKEY_REGIONS`.
- Not built: moving an existing store between regions (snapshot/backup in R2,
  restore in the new region, switch the address).

A bigger, free win landed first: Laravel queries on dply Postgres took three
round trips each (prepare, execute, deallocate). dply/laravel now sends them in
one, so every query is ~3× faster wherever the app runs (Toronto: 52 → 17 ms).

