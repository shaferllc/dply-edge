# Spike: PHP container behind a Workers for Platforms dispatcher

Answers three questions before dply builds container support
(ruling pending; see the chat that created this):

1. Does a Cloudflare Container deployed with `wrangler deploy --dispatch-namespace`
   answer through `env.DISPATCHER.get(script).fetch()`? (undocumented)
2. Can a container open raw TCP to an external Postgres on 5432?
3. Does `docker build` work from inside the Node build image with the host socket?

Throwaway — delete `spikes/` once answered.

## Needs

- Docker running (`docker info` works)
- Workers Paid on the account
- `CLOUDFLARE_API_TOKEN` with: Workers Scripts Edit, Workers for Platforms Edit,
  Containers Edit, Durable Objects Edit (account `CLOUDFLARE_ACCOUNT_ID`)
- `DATABASE_URL` — any reachable Postgres, e.g. a free Neon database
  (`postgres://user:pass@host:5432/db?sslmode=require`)

## Run

    CLOUDFLARE_ACCOUNT_ID=... CLOUDFLARE_API_TOKEN=... DATABASE_URL=postgres://... ./run.sh

## Clean up

    ./run.sh cleanup
