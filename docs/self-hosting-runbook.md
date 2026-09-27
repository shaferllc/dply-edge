# dply on dply: break-glass runbook

dply runs on its own platform: the Laravel app is a **container app** on
Cloudflare Containers (`DPLY_RUNTIME=container`, script `dply-ctr-<site id>`,
public at `https://edge.dply.io`), its Postgres is a **dply database** and its
Redis a **dply Valkey**, both behind the gateway on the DOKS cluster
`dply-pods` (nyc3). Customer builds need Docker, so they run on the **builder
pool** on the same cluster (`DPLY_RUNTIME=builder`, `deploy/builders/`).
Architecture and the container-safety changes: `docs/self-hosting.md`.

Day to day, dply deploys itself like any customer app: push to `main`, the
self site (made by `dply:self:register`) builds on a builder and rolls out.
Everything below is for when that path is not available.

**Migrations on dashboard deploys.** Migrate-on-boot is off for dply (it would
run on every cold start of every instance), and a dashboard deploy does not
migrate. So: a release with migrations ships with `dply:self:deploy` (which
migrates once, before rollout), or, for additive migrations only, push as
usual and then Resources → Database → **Migrate** in dply's own workspace.

**Every command here works without dply running.** They need this checkout,
PHP 8.4 + `composer install`, git, Docker with buildx, and the production env
file (from 1Password or restored from escrow, below). Never cache config on
the machine you run them from (`php artisan config:clear`).

| What depends on what | If it is down |
|---|---|
| Valkey/database **gateway** (DOKS) | dply is down (no DB, no queue, no cache). Recover it first: [Gateway](#the-gatewaycluster-is-down). |
| Cloudflare (Workers, Containers, R2) | dply is down; nothing to do but wait. |
| Builders | Customer builds and dply's own dashboard deploys queue. dply keeps serving. `dply:self:deploy` still works (it builds on your machine). |
| GitHub | Push-to-deploy stops. `dply:self:deploy --repo=<local path>` still works. |

---

## 0. Get the production env file

```bash
# On a machine with the OFFLINE age identity (never on a server):
export SECRET_VAULT_IDENTITY_PATH=~/secure/identity.txt
php artisan secrets:restore --source=platform-env --revision=latest --to=/tmp/dply-prod.env --force
php artisan secrets:restore --source=platform-env --list          # other versions
```

The option is `--revision` (not `--version`, as older docs said). The vault's
store credentials (`SECRET_VAULT_OBJECT_*`) must be in the env of the machine
running this. Keep `/tmp/dply-prod.env` off disk when done (`shred -u`).

The file must be **self-contained**: `APP_KEY`, `DB_*`/`DB_URL` (the dply
database, TLS), `REDIS_URL` (`rediss://…:6380`), `DPLY_EDGE_R2_*`,
`DPLY_EDGE_CF_API_TOKEN`, `DPLY_EDGE_CF_ACCOUNT_ID`, the dispatch namespace,
`DPLY_EDGE_CONTAINER_DEPLOY_API_TOKEN`. `dply:self:deploy --env-file` runs
itself again in a child process whose environment *is* that file, so every
Cloudflare/R2/APP_KEY value is production's, never your laptop's `.env`.

---

## 1. First start (once): bootstrap on a fresh database

The self-hosted dply starts on a **fresh, empty database** (ruling
r-nra2jn3k5jek65hz): no data is copied from the VM control plane. dply's own
site, database and Valkey must exist before dply can run, so the first start
happens entirely from **the owner's machine** (this checkout, PHP 8.4 +
`composer install`, git, Docker with buildx, `psql`), never from a running
dply. Nothing here needs the old VM.

Prerequisites: the DOKS cluster and gateway are up (they already serve
customers); the builders are optional for this (section 7).

```bash
# 0. The container env file (keep it outside the repo, mode 600): the VM's
#    .env as a starting point, minus DB_*/REDIS_* (bootstrap writes those), with
#    APP_URL=https://edge.dply.io, SESSION_DRIVER=redis, LOG_CHANNEL=stderr,
#    PLATFORM_ADMIN_EMAILS=<you>, and DPLY_VALKEY_API_URL / DPLY_VALKEY_TOKEN
#    (the gateway API; deploy/valkey/.secrets/api-token). docs/self-hosting.md
#    has the env matrix.
export ENVF=~/secure/dply-container.env

# 1. Bootstrap: picks the self site id; creates dply's Postgres (stays on) and
#    Valkey through the gateway, named after that id; writes their credentials
#    into $ENVF; migrates the empty database from this machine (the schema dump
#    loads with psql); creates the owner, the "dply" organization (comped) and
#    the self site with that id, adopting the two resources. Idempotent.
php artisan dply:self:bootstrap --env-file=$ENVF --owner=<you@…> --dry-run
php artisan dply:self:bootstrap --env-file=$ENVF --owner=<you@…>

# 2. First deploy: builds the FrankenPHP image (composer.json
#    extra.dply.php-server), migrates (a no-op now), rolls out, checks
#    <platform hostname>/up. Saves the Worker snapshot to R2.
php artisan dply:self:deploy --env-file=$ENVF --dry-run
php artisan dply:self:deploy --env-file=$ENVF

# 3. Cut over: attach edge.dply.io to the self site (Cloudflare custom
#    hostname), then point DNS at it. From here health checks include it.
php artisan dply:self:register --env-file=$ENVF --attach-domain

# 4. Log in: "Forgot password" for the owner email. Put $ENVF in 1Password.
```

Escrow gap: the scheduled `secrets:escrow --source=platform-env` now runs on a
builder and escrows *that host's* `.env`, not this container env file
(docs/self-hosting.md, Known limits). Until that is fixed, $ENVF in 1Password
is the copy section 0 restores from.

Minimum order, and why: **ids before resources** (tenant ids derive from the
site id) → **resources before schema** (the database must exist) → **schema
before the site row** (register needs tables) → **site before deploy**
(`dply:self:deploy` writes the Worker project from the site and the script is
`dply-ctr-<site id>`) → **healthy deploy before cutover**.

Everything after this is ordinary: pushes to `main` deploy from dply's own
dashboard (builders), `dply:self:deploy` stays the break-glass path.

## 2. Deploy (break-glass)

```bash
git fetch origin
php artisan dply:self:deploy --env-file=/tmp/dply-prod.env --dry-run            # every step + command
php artisan dply:self:deploy --env-file=/tmp/dply-prod.env                      # origin/main
php artisan dply:self:deploy --env-file=/tmp/dply-prod.env --ref=<sha|tag>
```

What it does, in order (the dry run prints each command, secrets masked):

1. Resolves the ref in this checkout (`git fetch` first) and clones that commit.
2. Generates `Dockerfile.dply` with the same generator customers get
   (`EdgeContainerDockerfile::prepare`); dply's composer.json sets
   `extra.dply.php-server = frankenphp`, so it is a FrankenPHP image.
3. Writes the Worker project: from the self site when the database answers
   (identical to a dashboard deploy: queue workers, scheduler, bindings), else
   from the R2 snapshot of the last deploy.
4. **Migrations:** builds the image locally (`linux/amd64`) and runs
   `php artisan migrate --force` once in it, against production, before any
   traffic reaches the new code. A failure stops here; production is untouched.
   We chose this over migrate-on-boot (runs on every cold start and every
   instance) and over a job inside the new container (it would already be
   serving). Migrations must stay backward-compatible for one release: the old
   code runs against the new schema until the rollout completes, and a
   rollback never migrates down.
5. Runs the deployer image (wrangler) exactly as a dashboard deploy does, but
   on your machine's default Docker builder (dply's code is trusted; the
   customer sandbox network only exists on builders).
6. Waits for Cloudflare's rollout, then `GET <platform hostname>/up` must
   answer 200 (about 2 minutes of retries), and `https://edge.dply.io/up` too
   once that domain is attached to the self site. Before cutover it is not
   checked: it is still the old control plane (in maintenance mode, 503).
7. **On failure after rollout: automatic rollback once** to the previous
   successful commit, without migrations. If that fails too it stops and says
   so. `--no-auto-rollback` leaves the failed version up.
8. Records the deploy in R2 (`_dply-self/state.json` in the edge bucket) and,
   when reachable, as an `EdgeDeployment` row on the self site.

`--skip-migrate` deploys code only (needed when the database is down).
`--repo=<path or url>` checks out from somewhere else (GitHub down: a local
clone).

## 3. Rollback

```bash
php artisan dply:self:deploy --env-file=/tmp/dply-prod.env --rollback --dry-run   # shows the target
php artisan dply:self:deploy --env-file=/tmp/dply-prod.env --rollback
```

The target is the newest *successful* deploy whose commit differs from the
live one, from dply's database (dashboard deploys too) plus the R2 history,
or R2 alone when the database is down. It rebuilds that commit (BuildKit
cache makes it quick) because the container script always runs its latest
image. Schema is never rolled back.

---

## 4. Restore the database

**Point in time (preferred)** — the gateway keeps wal-g backups of every dply
database. Needs only the gateway API token (`deploy/valkey/.secrets/api-token`,
also in 1Password) and the database id (`database.remote_id` in the self site's
edge meta; the Resources tab shows it):

```bash
curl -fsS -X POST "$DPLY_VALKEY_API_URL/tenants/<db id>/restore" \
  -H "Authorization: Bearer $(tr -d '[:space:]' < deploy/valkey/.secrets/api-token)" \
  -H 'Content-Type: application/json' -d '{"target_time":"2026-09-27T10:00:00Z"}'   # {} = latest
curl -fsS "$DPLY_VALKEY_API_URL/tenants/<db id>/backup" -H "Authorization: Bearer …"  # last backup
```

**From escrow (independent copy, survives the cluster and the backup bucket)**
— the daily age-encrypted `pg_dump` (plain SQL, `--clean --if-exists`):

```bash
export SECRET_VAULT_IDENTITY_PATH=~/secure/identity.txt
php artisan secrets:restore --source=db-dump --list
php artisan secrets:restore --source=db-dump --revision=latest --to=/tmp/dply.sql --force
psql "$DATABASE_URL" -v ON_ERROR_STOP=1 -f /tmp/dply.sql && shred -u /tmp/dply.sql
```

Into a *new* database (the old one is gone): start one from the gateway API
or, once dply is up, the Resources tab; put its `DB_*` in the env file and
`dply:self:deploy --env-file=… --skip-migrate`, then migrate with a normal
deploy.

---

## 5. Rotate secrets

| Secret | How |
|---|---|
| `APP_KEY` | `deploy/SELF_MANAGE.md` W5: set `APP_KEY=<new>` + `APP_PREVIOUS_KEYS=<old>` in the self site's env **and** the builder Secret, deploy (`dply:self:deploy` + `deploy/builders/apply.sh`), `php artisan secrets:reencrypt` until `--assert-complete` passes, drop `APP_PREVIOUS_KEYS`, deploy again. The queue token (`DPLY_QUEUE_TOKEN`) derives from `APP_KEY`, so every container app needs a redeploy too. |
| Cloudflare tokens (`DPLY_EDGE_CF_API_TOKEN`, `DPLY_EDGE_CONTAINER_DEPLOY_API_TOKEN`) | Roll in the Cloudflare dashboard, update the self site env and `deploy/builders/.secrets/builder.env`, redeploy both. Scopes: `docs/edge-build-isolation.md`. |
| dply database / Valkey passwords | Resources tab (or the gateway `PUT /tenants/{id}`), then redeploy the self app and the builders (`apply.sh`), which read `DB_*`/`REDIS_URL`. |
| Gateway API / admin tokens | Replace `deploy/valkey/.secrets/*`, re-run `deploy/valkey/apply.sh`, update `DPLY_VALKEY_TOKEN` in the self env. |
| Escrow age key | New `age-keygen` offline; new recipient in `SECRET_VAULT_RECIPIENTS_PATH`; `secrets:escrow --force` each source. |

After any env change: update the copy in 1Password (see the escrow gap in section 1).

---

## 6. The gateway/cluster is down

dply's database and Valkey live behind it, so dply is down too; the dashboard
cannot help. Work from a laptop with `deploy/valkey/.secrets/do.env`
(`DIGITALOCEAN_TOKEN`, also in 1Password):

```bash
cd deploy/valkey
source .secrets/do.env
cluster_id=$(cd terraform && terraform output -raw cluster_id)   # or: doctl kubernetes cluster list
doctl kubernetes cluster kubeconfig save "$cluster_id"            # or let apply.sh write ./kubeconfig
kubectl -n dply-valkey get pods,svc; kubectl -n dply-db get pods
kubectl -n dply-valkey logs deploy/valkey-gateway --tail=200
kubectl -n dply-valkey rollout restart deploy/valkey-gateway      # stuck leader / bad pod
./apply.sh <last good gateway image>                              # redeploy (idempotent)
```

- Load balancer IP changed: `apply.sh` rewrites `*.cache.dply.io` and
  `*.db.dply.io` in Cloudflare DNS.
- Certificate expired: `kubectl -n dply-valkey describe certificate valkey-gateway-tls`
  (cert-manager's DNS check needs `DPLY_EDGE_CF_API_TOKEN` with Zone:DNS:Edit).
- Cluster lost: `terraform apply` in `deploy/valkey/terraform` (state is in
  that directory; back it up), `./apply.sh`, then each database restores from
  wal-g in R2 (section 4, `{}` = latest); dply's own from the escrow dump if
  wal-g is gone too. Then `deploy/builders/apply.sh`.
- Once the gateway answers, dply comes back on its own (containers reconnect).
  If not: `php artisan dply:self:deploy --env-file=… --skip-migrate`.

---

## 7. Builders

A pool of identical, stateless build servers on DOKS: pool `builders`
(`s-4vcpu-8gb`, tainted `dply.io/role=builder`, autoscaled 1–4 nodes) in
`deploy/valkey/terraform/main.tf`; one pod per node (`deploy/builders/k8s/builder.yaml`):
the app image with `DPLY_RUNTIME=builder` running Horizon on the build queues
only (`dply-provision`, `dply-builder`), plus a privileged `docker:dind`
sidecar reached over TLS on localhost. **KEDA** scales the Deployment on the
length of `queues:dply-provision` in Valkey (min 1, max 4, 2 waiting builds
per pod), and the cluster autoscaler adds nodes to fit.

Each pod also drains its own lane (`DPLY_EDGE_BUILD_HOST_QUEUE =
dply-provision-<pod>`): publish and cache-snapshot jobs read the build's files
from that pod's disk, and a cancel's `docker kill` must reach its dind, so
those are dispatched there. Removing a pod mid-publish strands its lane; the
deployment is reaped by `dply:edge:reap-stuck-builds`.

Isolation: the NetworkPolicy denies all ingress and allows egress only to DNS,
the Valkey/database gateway (6380, 5432) and the public internet, never
cluster or private ranges or metadata (10/8, 172.16/12, 192.168/16,
100.64/10, 169.254/16). Inside dind the build network and INPUT drop from
`docs/edge-build-isolation.md` apply. Nothing else schedules on the pool.

### Deploy / update

```bash
# Build and push the image (DO registry; doctl registry login first)
TAG=$(git rev-parse --short HEAD)
docker buildx build --platform linux/amd64 -f deploy/builders/Dockerfile \
  -t registry.digitalocean.com/dply-cloud/dply-builder:$TAG --push .

# Node pool only: main.tf also holds pools not applied yet (db-large, db-xl)
cd deploy/valkey/terraform
terraform plan -target=digitalocean_kubernetes_node_pool.builders -out=builders.plan
terraform apply builders.plan
cd -

# KEDA + Secrets + manifests; restarts pods one at a time, each draining first
deploy/builders/apply.sh --dry-run registry.digitalocean.com/dply-cloud/dply-builder:$TAG
deploy/builders/apply.sh registry.digitalocean.com/dply-cloud/dply-builder:$TAG 4
```

`deploy/builders/.secrets/builder.env` (gitignored) is the builders' env.
Least privilege: what builds actually read, nothing else.

| Key(s) | Why |
|---|---|
| `APP_KEY` | builds decrypt the site's env and git credentials (see gap below) |
| `DB_*` (the **app** role, TLS) | build/publish jobs read and update sites and deployments |
| `REDIS_URL`, `REDIS_PREFIX` | the queue; KEDA reads the same list |
| `DPLY_EDGE_R2_*` | artifact upload, build cache |
| `DPLY_EDGE_CF_API_TOKEN`, `DPLY_EDGE_CF_ACCOUNT_ID`, dispatch namespace | host map / KV publish, SSR uploads |
| `DPLY_EDGE_CONTAINER_DEPLOY_API_TOKEN` | wrangler container deploys (narrow scopes) |
| GitHub App / OAuth client ids | cloning private repos |
| `SECRET_VAULT_OBJECT_*` (write-only), `SECRET_VAULT_RECIPIENTS_PATH` | the escrow jobs now queued to builders (age + pg_dump live there) |

Never: database admin/superuser credentials, the age **identity**
(`SECRET_VAULT_IDENTITY_PATH`), `SECRET_VAULT_CRITICAL_PG_PASSWORD`, DO or
gateway admin tokens. `apply.sh` and `bootstrap.sh` refuse an env file with
the first three.

**Known gap (not widened by this change):** a build job loads the site and
calls `EdgeProductionEnv::forSite()` on the builder, so builders hold
`APP_KEY` and the app DB role, and could decrypt any site's env. Narrowing it
means dispatching only that site's build env with the job (encrypted to a
builder-only key) and a token-scoped callback instead of DB access. Separately,
the scheduled escrow jobs now routed to the `dply-builder` queue pg_dump the
whole database from the builders.

### Scale, drain, add, remove

```bash
kubectl -n dply-builders get scaledobject,hpa,pods -o wide        # current state
# More at peak: raise the ceiling (ScaledObject) and the pool max (terraform var)
deploy/builders/apply.sh <image> 6      # + builder_max_nodes = 6 in terraform
# Drain one builder safely: eviction runs its preStop, `dply:builder:drain`
# (pause the build supervisor; wait until this pod's builds and the publishes
# they queue on its lane are done) then `horizon:terminate --wait`. dind stays
# up until Horizon exits. Grace period 7500 s.
kubectl -n dply-builders delete pod <pod>        # or: kubectl drain <node> --ignore-daemonsets
# Pause all builds (maintenance): scale KEDA's floor and ceiling to 0
kubectl -n dply-builders patch scaledobject dply-builder --type merge -p '{"spec":{"minReplicaCount":0,"maxReplicaCount":0}}'
# Resume: re-run apply.sh
```

Not verified on the cluster yet: KEDA's redis scaler sending SNI through the
gateway (it must, for `{id}.cache.dply.io` routing), and dind's MTU. If the
pod's `eth0` MTU is under 1500 (check `kubectl exec … -c dind -- ip link`),
large pulls stall: add `--mtu=<n>` to dind's args and
`-o com.docker.network.driver.mtu=<n>` to the `dply-builds` network.

Adding capacity is automatic (KEDA + autoscaler); "adding a builder" by hand
means raising `maxReplicaCount`/`builder_max_nodes`. Removing is the drain
above. A pod that dies mid-build leaves the build to `dply:edge:reap-stuck-builds`.

**Alert:** each healthy builder's liveness probe runs `dply:runtime:check`
(Docker answers, git ≥ 2.31, work root writable, config) and stamps a
heartbeat in the cache. On the control plane `dply:edge:check-builders` runs
every minute and emails the platform admins (`PLATFORM_ADMIN_EMAILS`) once
when **no builder has checked in for 5 minutes**, and again when one does.

### Fallback: a standalone build server

If the pool is unavailable (cluster down, registry down), run the same role
on a plain Ubuntu droplet. Idempotent; parameterised per box:

```bash
scp deploy/builders/.secrets/builder.env root@<ip>:/root/builder.env
ssh root@<ip> 'bash -s -- --name builder-fallback-1 --concurrency 4' < deploy/builders/bootstrap.sh
```

It installs Docker, git, PHP 8.4, Composer, Node 22, checks out the app,
sets `DPLY_RUNTIME=builder`, applies the build firewall, and runs Horizon
(`dply-builder.service`, drains on stop) plus the heartbeat timer. Remove one:
`systemctl stop dply-builder` (waits for builds), then destroy the droplet.
Later: autoscale fallback droplets through the DigitalOcean API (not built).
