# Edge local development (Valet + fake edge)

Use **fake edge** to build and serve Edge sites on your Mac without Cloudflare R2, KV, or a Worker. Traffic for `{slug}.{testing-domain}` is handled by this Laravel app via `ResolveEdgeCustomDomain` middleware and `FakeEdgeBackend` disk artifacts.

## Quick start

1. In `.env`:

   ```dotenv
   APP_URL=https://dplyi.test
   FEATURE_SURFACE_EDGE=true
   DPLY_FAKE_EDGE=true
   DPLY_EDGE_TESTING_DOMAINS=dplyi.test
   ```

   Use the **same host as `APP_URL`** (or any domain already linked in Valet). New Edge sites get hostnames like `my-app.dplyi.test`.

2. Run queue worker (builds run async):

   ```bash
   php artisan queue:work
   ```

3. Create an Edge site, wait for the build, then open the live URL from the site dashboard (for example `https://my-app.dplyi.test`).

4. Verify setup:

   ```bash
   php artisan dply:edge:doctor
   ```

## Option A — Valet `.test` (recommended)

Valet’s dnsmasq already resolves `*.test` to `127.0.0.1`. Subdomains of a linked site are routed to the same project.

### A1 — Subdomain of your existing link (simplest)

If the app is already at `https://dplyi.test`:

```bash
cd /path/to/dply
valet link dplyi   # if not already linked
```

```dotenv
APP_URL=https://dplyi.test
DPLY_EDGE_TESTING_DOMAINS=dplyi.test
```

Edge sites → `https://{slug}.dplyi.test`. Valet routes all `*.dplyi.test` to this project.

### A2 — Dedicated Edge testing domain

```bash
cd /path/to/dply
valet link --domain=edge
```

```dotenv
APP_URL=https://dplyi.test
DPLY_EDGE_TESTING_DOMAINS=edge.test
```

Edge sites → `https://{slug}.edge.test`. Valet routes all `*.edge.test` to this project.

> **Note:** Sites created **before** changing `DPLY_EDGE_TESTING_DOMAINS` keep their old hostname in `meta.edge.routing.hostname`. Create a new site or update that field after changing the domain.

## Option B — Keep `dply.host` locally (dnsmasq)

Use this when you want hostnames to match production (`{slug}.dply.host`).

### 1. dnsmasq

Homebrew dnsmasq (Valet installs this):

```bash
echo 'address=/.dply.host/127.0.0.1' | sudo tee /opt/homebrew/etc/dnsmasq.d/dply-edge-local.conf
sudo brew services restart dnsmasq
```

Confirm:

```bash
dig +short my-site.dply.host @127.0.0.1
# 127.0.0.1
```

macOS must use `127.0.0.1` for DNS (Valet’s `valet install` normally configures this).

### 2. Route traffic to Laravel

Valet only auto-configures `.test` by default. For `*.dply.host`, proxy through Valet:

```bash
cd /path/to/dply
valet secure dplyi   # optional; use https for APP_URL
valet proxy dply.host http://dplyi.test --secure
```

Or add a custom Nginx site under `~/.config/valet/Nginx/` that passes `*.dply.host` to the same `public/` root as your main link.

```dotenv
DPLY_EDGE_TESTING_DOMAINS=dply.host
```

## How it works

| Piece | Role |
|-------|------|
| `DPLY_FAKE_EDGE=true` | Skips Cloudflare API; uses `FakeEdgeBackend` |
| `DPLY_EDGE_TESTING_DOMAINS` | Apex domain for `{slug}.{domain}` hostnames |
| `ResolveEdgeCustomDomain` | Intercepts non-`APP_URL` hosts, looks up Edge site, serves artifacts |
| `storage/app/edge-fake/` | Local artifact storage |
| `php artisan queue:work` | Runs `BuildEdgeSiteJob` / publish jobs |

Production Edge traffic is served by the Cloudflare Worker, not this app. Set `DPLY_FAKE_EDGE=false` and configure R2/KV/Worker per [edge-production-setup.md](./edge-production-setup.md).

## Troubleshooting

| Symptom | Fix |
|---------|-----|
| Dashboard shows hostname but browser 404 / wrong site | DNS not pointing at this machine, or Valet not routing that host — run `dply:edge:doctor` |
| Build stuck | `queue:work` not running |
| Hostname still `*.dply.host` after env change | Site was created with old domain; create a new site or edit `meta.edge.routing.hostname` |
| TLS warning on `.test` | Run `valet secure dplyi` (or your link name) |
| `dply:edge:doctor` wildcard DNS fails | See Option A or B above |

## Related

- [edge-production-setup.md](./edge-production-setup.md) — real Cloudflare Edge
- [edge-roadmap.md](./edge-roadmap.md)
