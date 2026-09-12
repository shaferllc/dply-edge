# dply Edge roadmap

Status: **History.** Phases 0–4 shipped; the plan continued in [edge-roadmap-next.md](edge-roadmap-next.md) (also closed). Kept as a record — check the code, not this file, for current behaviour.

First-party Netlify-style platform: git-connected builds, branch previews, CDN delivery via Cloudflare R2 + Workers.

## Phases

### Phase 0 — Foundation ✅

- ADR + feature flag `surface.edge`
- Edge index/create UI scaffold
- Data model: `edge_deployments`, site helpers

### Phase 1 — Core deploy loop ✅

- Docker build runner + runtime detection
- R2 artifact upload + KV host map publish
- CF Worker package (`packages/edge-worker`)
- Fake edge mode for local/CI

### Phase 2 — Product UX ✅ (2026-05-23)

- Site workspace edge dashboard (deploy history, redeploy, rollback)
- GitHub webhooks: inbound controller + **auto-provision** from Build settings (enable/disable, account picker, last event)
- Build logs captured to `{storage_prefix}/build.log` and shown in Logs panel
- Overview hero auto-deploy status; previews show PR number

### Phase 3 — Custom domains ✅ (2026-05-23)

- `EdgeCustomDomainProvisioner` — pending → verify → ready DNS flow
- CNAME target + copy, Verify DNS button, status badges in Domains UI
- `VerifyEdgeCustomDomainsJob` scheduled every 15 minutes
- Pending custom domains gated in fake-edge middleware (503 pending page)
- TLS via Custom Hostnames (SSL for SaaS) on managed delivery — Phase 3b ✅ (`EdgeCloudflareClient` CRUD + provisioner + SSL poll in `VerifyEdgeCustomDomainsJob`)

### Phase 4 — SSR (split)

#### Phase 4a — Hybrid origin-fetch ✅ (2026-05-23)

- `runtime_mode = 'hybrid'` + `meta.edge.origin` (url, routes)
- Worker proxies to origin on R2 miss for matching routes
- Create flow: hybrid mode + SSR origin URL
- Build settings shows hybrid origin (read-only in v1)

#### Phase 4b — Worker-native SSR ✅ (2026-05-24)

- `EdgeBuildRunner` runs `@opennextjs/cloudflare build` inside Docker for `runtime_mode = 'ssr'`; emits `.open-next/assets/` + `.open-next/worker.js`
- `EdgeSsrBundleUploader` ships the bundled Worker entry into a Workers for Platforms dispatch namespace as `dply-ssr-{site-tail}-{deploy-tail}`
- Platform `packages/edge-worker` gains a `DISPATCHER` binding; `runtime_mode=ssr` hostnames are routed via `env.DISPATCHER.get(scriptName).fetch(request)`
- `php artisan dply:edge:infra:bootstrap` auto-creates the dispatch namespace; SSR site creation blocks until `DPLY_EDGE_CF_DISPATCH_NAMESPACE_ID` is set
- Per-deployment scripts are wiped on site teardown so the namespace doesn't accumulate orphans
- See [edge-ssr.md](edge-ssr.md) for the operator runbook
- See [edge-product-boundary ADR](adr/edge-product-boundary.md)

## Infra prerequisites

See **[edge-production-setup.md](edge-production-setup.md)** for the full runbook.

- Cloudflare: R2 bucket, Workers KV namespace, Worker deployed (`php artisan dply:edge:bootstrap`, `php artisan edge:worker:deploy`)
- Wildcard DNS on testing domains (`*.dply.host`) → Worker
- Queue workers with Docker for builds
- Env: `DPLY_EDGE_*` (see `config/edge.php`); validate with `php artisan dply:edge:doctor --probe`

## Next phases

See **[edge-roadmap-next.md](edge-roadmap-next.md)** for planned Phases 5–12 (CLI + API, `dply.toml`, rollback/promote UI, password-protected previews, monorepo + build cache, live logs, edge middleware/bindings, import from Vercel/Netlify/Pages, team roles).

## Related

- [edge-product-boundary ADR](adr/edge-product-boundary.md)
- Cloud preview pattern: `app/Actions/Cloud/CreateCloudPreviewSite.php`
