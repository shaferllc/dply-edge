# dply Edge

Single Laravel application that runs **dply Edge**: first-party Netlify-style static, hybrid and SSR hosting on Cloudflare Workers + R2. Connect a Git repo, get a build, a production URL, a preview per push, custom domains and edge analytics.

> **Start here:** [docs/edge-local-development.md](docs/edge-local-development.md) for local setup, [docs/edge-production-setup.md](docs/edge-production-setup.md) for production, and [docs/EDGE_OVERVIEW.md](docs/EDGE_OVERVIEW.md) for the product.

This is the Edge-only cut of dply — the VM platform (BYO servers, SSH), Cloud apps, Serverless and the other product lines were removed on 2026-08-25. See [CLAUDE.md](CLAUDE.md) for the codebase map.

## What you get

- Git-connected builds with framework presets (Next, Astro, SvelteKit, Remix, Nuxt, Hono, Vite, Eleventy, Hugo, Jekyll, Gatsby, static)
- Preview deploys per push, password / account-gated previews, preview comments
- Per-deploy aliases, rollback and promote, split traffic (A/B)
- `dply.yaml` in-repo config: build, redirects, rewrites, headers, bindings, crons
- Edge middleware, KV / R2 / D1 / Queues bindings, cron triggers, deploy hooks
- Custom domains with managed TLS, firewall, rate limits, bot protection, waiting room
- Live request logs, RUM / Web Vitals, deploy notifications, audit log
- Import from Vercel, Netlify and Cloudflare Pages (`/edge/import`), template gallery (`/edge/templates`)

## Pricing model

Free includes unlimited sites, seats, and builds, with $5 of usage credit. Pro ($20) and Team ($49) include more, then $2/mo for each site past the plan. Worker SSR sites are $7/mo. Usage past a paid plan is metered. Previews count as usage. See [docs/BILLING_AND_PLANS.md](docs/BILLING_AND_PLANS.md).

## Quick start

```bash
composer setup                # install, .env, key, migrate, npm build
composer dev                  # serve + queues + logs + reverb + scheduler
```

PostgreSQL is required (`DB_DATABASE` in `.env`). Builds and publishes run on the queue, so `composer dev` (or `php artisan queue:work`) must be running for deploys to progress.

## API + CLI

- **Edge REST API** under `/api/v1/edge/*` — OpenAPI spec at [`public/openapi/edge.json`](public/openapi/edge.json). Bearer tokens from Settings → API tokens.
- **`dply` CLI** in [`packages/dply-cli/`](packages/dply-cli/): `dply login` (device flow), `dply link`, `dply edge deploy --prod`, `logs --tail`, `env`, `rollback`, `promote`, `domains`, `purge`.
- **Edge Worker** in [`packages/edge-worker/`](packages/edge-worker/) — the Cloudflare Worker that serves every site.

## Stack

- Laravel 13, Livewire 4, Laravel Cashier (Stripe), Reverb
- PostgreSQL (single control-plane DB)
- Cloudflare Workers, R2, KV, dispatch namespaces

## Tests

```bash
composer test                 # Unit + Feature suites
composer test:arch            # Pest arch rules
```

## Security

Protect `APP_KEY` and use HTTPS in production. Do not commit `.env` or real keys.

## License

MIT.
