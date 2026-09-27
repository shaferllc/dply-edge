# Edge build isolation (operator)

Customer builds run the repo's own scripts (`pnpm install` lifecycle hooks,
`vite build`, `npx esbuild` for middleware) in `docker run` on the build
worker. Treat every build as hostile. This page says what the app enforces
and what the host has to add.

Code: `EdgeBuildRunner::sandboxFlags()` / `sandboxScript()` (also used by
`EdgeMiddlewareBundler`). Config: `edge.build.sandbox` in
`config/product/edge.php`.

## Enforced by the app

| Control | How | Knob |
|---|---|---|
| No capabilities | `--cap-drop=ALL` | — |
| No setuid escalation | `--security-opt=no-new-privileges` | — |
| Non-root | `--user <worker uid:gid>`, `HOME=/tmp`, `NPM_CONFIG_PREFIX=/tmp/.npm-global`; `corepack enable` is rewritten to `--install-directory "$NPM_CONFIG_PREFIX/bin"` | `DPLY_EDGE_BUILD_USER` = `host` (default), `root`, or `uid:gid` |
| Resources | `--memory` + `--memory-swap` (no swap), `--cpus`, `--pids-limit` | `DPLY_EDGE_BUILD_MEMORY` (4g), `DPLY_EDGE_BUILD_CPUS` (2), `DPLY_EDGE_BUILD_PIDS_LIMIT` (2048); empty = no limit |
| Per-org package caches | `{package_store_dir}/org-{organization_id}/{npm,pnpm,yarn}`; no org → no cache | `DPLY_EDGE_BUILD_PACKAGE_STORE_DIR` |
| Own network, no build↔build traffic | `--network=dply-builds`, created on first use with `enable_icc=false`, bridge `dply-builds0`, subnet `172.30.0.0/16` | `DPLY_EDGE_BUILD_NETWORK` (`''` = default bridge), `DPLY_EDGE_BUILD_BRIDGE`, `DPLY_EDGE_BUILD_SUBNET` |
| Name sinkholes | `--add-host` → 127.0.0.1 for `host.docker.internal`, `gateway.docker.internal`, `metadata.google.internal`, `metadata`, `instance-data` | `edge.build.sandbox.sinkhole_hosts` |

The only mounts are the checkout (`/src`) and the org's own caches. Nothing
else from the host is mounted.

Root mode (`DPLY_EDGE_BUILD_USER=root`) is an escape hatch for an image that
must install system packages. It adds back `CHOWN DAC_OVERRIDE FOWNER SETUID
SETGID`, because root with no capabilities can't write the host-owned mounts.
A worker that itself runs as root always uses root mode.

## NOT enforced by the app: host firewall (do this on every build worker)

Name sinkholes don't stop IP literals. Without the rules below, a build can
still reach:

- the cloud metadata service at `169.254.169.254`, which exposes droplet user
  data and credentials;
- host-local services through the bridge gateway (`172.30.0.1`): Postgres,
  Redis, the valkey-gateway API and anything else bound to `0.0.0.0`;
- private and VPC ranges.

The app can't safely edit the host firewall, so an operator runs these once
as root. They assume the defaults (`dply-builds0`, `172.30.0.0/16`).

```sh
# Create the network up front so the bridge name exists (the app would
# create the same thing on the first build).
docker network inspect dply-builds >/dev/null 2>&1 || docker network create \
  --driver bridge \
  -o com.docker.network.bridge.enable_icc=false \
  -o com.docker.network.bridge.name=dply-builds0 \
  --subnet 172.30.0.0/16 dply-builds

# 1) Builds can't reach the host itself (gateway IP, any port). Docker's
#    embedded DNS is 127.0.0.11 inside the container, so this doesn't break DNS.
iptables -I INPUT -i dply-builds0 -j DROP

# 2) Builds can't reach metadata, link-local, private or CGNAT ranges.
#    Insert in reverse order so ESTABLISHED ends up first.
for net in 169.254.0.0/16 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 100.64.0.0/10; do
  iptables -I DOCKER-USER -i dply-builds0 -d "$net" -j DROP
done
iptables -I DOCKER-USER -i dply-builds0 -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT

# IPv6, if the host has it: builds only need public v6.
ip6tables -I INPUT -i dply-builds0 -j DROP
ip6tables -I DOCKER-USER -i dply-builds0 -d fc00::/7 -j DROP
ip6tables -I DOCKER-USER -i dply-builds0 -d fe80::/10 -j DROP

# Persist (Debian/Ubuntu).
apt-get install -y iptables-persistent && netfilter-persistent save
```

Check it from inside a build container. Each command should fail or time out:

```sh
docker run --rm --network dply-builds curlimages/curl -m 3 http://169.254.169.254/
docker run --rm --network dply-builds curlimages/curl -m 3 http://172.30.0.1:5432/
docker run --rm --network dply-builds curlimages/curl -sI -m 5 https://registry.npmjs.org/  # must succeed
```

Notes:

- `172.16.0.0/12` contains the build subnet. The rule only matches traffic
  leaving `dply-builds0` for another destination, and ICC is already off, so
  that's intended. If `DPLY_EDGE_BUILD_SUBNET` overlaps a VPC you rely on,
  change it before the network exists.
- **An existing `dply-builds` network keeps the options it was created with.**
  To change the bridge, subnet or ICC setting, run
  `docker network rm dply-builds` while no build is running.

## Rollout

- **Delete the old shared caches.** Before this change every org wrote to
  `{package_store_dir}/{npm,pnpm,yarn}`, so they may already be poisoned:
  `rm -rf storage/app/edge-pkg-store/{npm,pnpm,yarn}`. Per-org caches refill
  on each org's first build (one cold install).
- Checkouts written by earlier root builds are root-owned. `EdgeRepoCloner`
  already falls back to an alpine `rm -rf` for those.

## Known gaps (outside this change)

- **Container apps** are covered in their own section below, with the gaps
  that remain on that path.
- Egress to the public internet stays open, because installs need the
  registries. Restricting it means an allowlisting proxy.

## Container image builds (container apps)

Code: `EdgeContainerDeployer::deployerCommand()`, `deployerScript()`, and
`scopeCacheMounts()`. Config: `edge.build.containers.*` in
`config/product/edge.php`. Tests:
`tests/Unit/Support/Edge/EdgeContainerBuildSandboxTest.php`.

wrangler (4.133 in the deployer image) builds the image with
`docker build --load --platform linux/amd64 --provenance=false -f - <context>`.
It has no builder setting. The docker CLI routes `docker build` to buildx,
though, and buildx honours `BUILDX_BUILDER`. So the deployer:

1. runs `docker buildx create --name dply-builds --driver docker-container`
   with `--driver-opt network=dply-builds`, a pinned `image=`, `memory`,
   `memory-swap` and `cpu-quota`, if its throwaway client state doesn't
   know the builder. If the `buildx_buildkit_dply-builds0` container already
   exists, buildx reuses it.
2. runs wrangler with `BUILDX_BUILDER=dply-builds`. That step is chained
   with `&&`, so a failed builder setup fails the deploy. It never falls
   back to a build on the host daemon.

`--load` exports the finished image into the host daemon. wrangler's
`image inspect`, `tag` and `push` work unchanged after that.

### Enforced by the app

| Control | How | Knob |
|---|---|---|
| Customer `RUN` steps run outside the host daemon's builder | BuildKit in the `buildx_buildkit_dply-builds0` container. RUN steps share its network namespace on `dply-builds`, so the firewall rules above cover them. | `DPLY_EDGE_CONTAINER_BUILDER` (`dply-builds`; `''` = host default builder, unsandboxed) |
| Resource cap | `memory`, `memory-swap` (no swap) and `cpu-quota` on the builder container | `DPLY_EDGE_CONTAINER_BUILDER_MEMORY` (8g), `DPLY_EDGE_CONTAINER_BUILDER_CPUS` (4) |
| Pinned BuildKit | `--driver-opt image=` | `DPLY_EDGE_CONTAINER_BUILDER_IMAGE` (`moby/buildkit:v0.32.2`) |
| No token or socket inside the build | `CLOUDFLARE_API_TOKEN` is only `-e` on the deployer. wrangler passes no `--build-arg`s (no `image_vars`), and `secrets.json` sits outside the build context (`{workRoot}/container-worker`; the context is `{workRoot}/src`). The docker socket isn't mounted into BuildKit. | — |
| Narrow deploy token | Separate from the platform token when set | `DPLY_EDGE_CONTAINER_DEPLOY_API_TOKEN` (empty = falls back to `DPLY_EDGE_CF_API_TOKEN`) |
| Per-org BuildKit cache mounts | Every `RUN --mount=type=cache` id is prefixed `dply-<hmac(org, APP_KEY)>-`; that includes explicit ids such as `id=pnpm` from the pnpm docs, and the default id (the target path). Cache ids are global on the builder, so without the prefix one org could poison another's npm, pnpm or composer cache. | — |
| Only trusted code next to the socket | The deployer runs wrangler plus the scaffold's pinned `@cloudflare/*` deps, with `npm install --ignore-scripts`. No customer code runs in it. | — |
| Shared PHP base image | `ext-` names from composer.json/lock must match `^[a-z0-9][a-z0-9_-]*$` before they reach `RUN install-php-extensions`. `EdgePhpBaseImage` builds that image on the host and pushes it for every org. | — |

Proven locally (OrbStack, Docker 29.4, buildx 0.33). The deployer image ran
the generated script, then wrangler's exact build command, on a generated
PHP `Dockerfile.dply`. The build succeeded and the image loaded into the
daemon. `RUN` saw only `dply-builds` routes and no `CLOUDFLARE_*` env. An
unknown `BUILDX_BUILDER` fails with `no builder "…" found`. Two concurrent
first boots of the builder both succeeded. `wrangler containers build` can't
be used for a build-only check, because it demands an API token.

### Ops (every build worker)

```sh
# Same network and firewall as the static builds above. The builder
# container sits on dply-builds0, so those rules apply to container
# image RUN steps as well.

# Driver opts (network, memory, CPU, image) only apply when the builder
# container is created. After changing them, or before removing or
# recreating the dply-builds network, remove the builder while no deploy
# is running (the next deploy recreates it):
docker rm -f buildx_buildkit_dply-builds0
docker volume rm buildx_buildkit_dply-builds0_state   # optional: drops the build cache

# Cache size: BuildKit's default GC applies; prune by hand if needed.
docker exec buildx_buildkit_dply-builds0 buildctl prune --keep-storage 20000
```

The note above about `docker network rm dply-builds` now also needs
`docker rm -f buildx_buildkit_dply-builds0` first. Otherwise the builder
keeps the network in use and the remove fails.

**Deploy token scopes** (for `DPLY_EDGE_CONTAINER_DEPLOY_API_TOKEN`). These
come from what the deploy does and from wrangler's own OAuth scope list
(`workers_scripts:write`, `containers:write`, `account:read`). **Verify them
with a scoped test token before rotating.**

- Account › Workers Scripts: Edit (script upload into the dispatch namespace,
  Durable Objects, cron triggers, observability)
- Account › Containers: Edit (registry push to `registry.cloudflare.com`,
  container application)
- Account › Queues: Edit (queue consumers in `wrangler.jsonc`)
- Account › Account Settings: Read

KV, R2 and D1 bindings are referenced by id and need no extra scope for the
upload. Add Workers KV Storage, R2 or D1 only if a scoped deploy fails on
them. The app's other Cloudflare calls keep using the platform token.

### Rollout

- **Check the shared PHP base for injected extension names.** Before this
  change, `ext-` names from composer.json went unvalidated into the shared
  base image that `EdgePhpBaseImage` builds and pushes for every org, when
  `DPLY_EDGE_CONTAINER_PHP_BASE_REPO` is set. Run
  `php artisan tinker --execute 'dump(Cache::get("edge:php-base-extra-extensions"));'`.
  If any name fails `^[a-z0-9][a-z0-9_-]*$`, treat those base tags as
  compromised: clear the key, then re-publish with
  `dply:edge:publish-base-images`.

### Not enforced (remaining gaps)

- **The deployer still mounts `/var/run/docker.sock`**, which is root on the
  host. A socket proxy buys little: buildx's docker-container driver needs
  container create, start and exec, and `--load` needs image load, and those
  endpoints are root on the host too. The mitigation is that only trusted
  code runs there now. Removing the socket means running BuildKit and
  wrangler outside Docker, for example on a dedicated build VM.
- **The BuildKit container is `--privileged`**, as buildx's docker-container
  driver requires. This is no worse than the host daemon's builder was: a
  RUN-step escape lands in a privileged container instead of dockerd. To
  harden it, try a rootless BuildKit image through
  `DPLY_EDGE_CONTAINER_BUILDER_IMAGE` (`moby/buildkit:v0.32.2-rootless`).
  That needs unprivileged user namespaces; Ubuntu 24.04 restricts them via
  AppArmor. Test it on a worker before switching.
- **No build-to-build isolation inside the builder.** Concurrent container
  builds share one network namespace, and RUN steps keep BuildKit's default
  capabilities (NET_RAW and others), unlike the `--cap-drop=ALL` static
  builds. One memory cap covers all concurrent builds. An OOM kills
  BuildKit and fails every build in flight.
- **Name sinkholes don't apply.** BuildKit gives RUN steps public resolvers
  (8.8.8.8) and its own `/etc/hosts`. On this path the firewall rules are the
  only metadata control, so they aren't optional on a worker that deploys
  container apps.
- **Registry auth.** Private `FROM` images, such as a private
  `DPLY_EDGE_CONTAINER_PHP_BASE_REPO`, are pulled by the builder with the
  deployer client's credentials. That client has none, and that was already
  true before this change. Keep the base repo public or add a
  credential-helper mount deliberately.
- **Warming is lost.** `dply:edge:warm-build-images` warms the host daemon.
  The builder has its own image store and cache, so the first container
  build after it is created pulls cold.
- **Existing deploys rebuild once.** Scoped cache ids change the generated
  Dockerfile, so the deploy fingerprint changes and each container site's
  next deploy rebuilds its image, starting from cold caches.
