# messages-worker

dply Messages: an HTTP message queue on Cloudflare. Apps attach it as a
resource (Add a resource → Messages) and get `MESSAGES_URL`, `MESSAGES_TOKEN`
and the signing keys. Behind the `resource-messages` flag. Headers are read as
`Dply-*`; the equivalent `Upstash-*` names also work, for existing clients.

**Status: built and tested locally, not deployed.** `wrangler.toml` has
placeholder ids.

## How it works

- `POST /v2/publish/{url}` (and `/v2/batch`) stores the message in the
  organization's Durable Object (`OrgState`, SQLite) and puts `{org, id}` on
  the **delivery queue**, delayed up to Cloudflare Queues' 12 h cap. Messages
  due later, and cron schedules, wait on the Durable Object's alarm, which
  queues them when due.
- The **queue consumer** asks the Durable Object whether the message is still
  pending (a cancel just deletes it), signs it (`Dply-Signature`, also sent as `Upstash-Signature`: HS256 JWT,
  `sub` the URL, `body` the base64url SHA-256), and delivers it.
  2xx → done (+ callback). Otherwise retry with backoff
  (min(1 day, e^(2.5·n)) s) until `Dply-Retries` runs out → DLQ
  (+ failure callback). Delivery is **at least once**.
- Supported: `Dply-Delay`, `-Not-Before`, `-Retries` (≤ 5), `-Method`,
  `-Timeout`, `-Callback`, `-Failure-Callback`, `-Deduplication-Id`,
  `-Content-Based-Deduplication`, `Dply-Forward-*`; schedules (`Dply-Cron`,
  5 fields, UTC; list/get/delete/pause/resume); messages get/cancel (single and
  `?messageIds=`); DLQ list/delete/retry; `/v2/keys`. Not supported: URL
  groups/topics, queues with parallelism, flow control, `CRON_TZ`, LLM/email APIs.
- **Limits** (per organization, from its account record, else defaults): 1 MB
  body, 7-day delay, 5 retries, 100 schedules, 100 publishes/s, 5-minute
  timeout. Destinations must be http(s) and not dply's own hosts
  (`BLOCKED_HOST_SUFFIXES`), localhost, `.internal`/`.local`, or a private or
  reserved IP literal. DNS rebinding is not checked (Workers cannot resolve).
- **dply** manages it through `/_operator` (Bearer `OPERATOR_TOKEN`):
  `PUT /_operator/orgs/{id}` `{enabled, currentSigningKey, nextSigningKey,
  limits?}`, `DELETE /_operator/orgs/{id}` (purges everything),
  `PUT|DELETE /_operator/tokens/{sha256(token)}` `{org}`, and
  `GET /_operator/orgs/{id}/usage` (running totals: published, delivered, …).
  A paused organization (`enabled: false`) cannot publish, its schedules do not
  fire, and queued deliveries go to the DLQ, retryable after resuming.

## Test

```
npm install
npm test          # unit: durations, cron, destinations, signatures (real Receiver)
npm run test:e2e  # wrangler dev (local KV, DO, Queues) + a local receiver + the real Client/Receiver
```

## Deploy (platform account)

1. `wrangler kv namespace create dply-messages-accounts`, `wrangler queues create dply-messages-delivery`,
   `wrangler queues create dply-messages-dead`; paste the KV id into `wrangler.toml`.
2. `wrangler secret put OPERATOR_TOKEN` (a long random string).
3. `wrangler deploy`, then give it a host (for example `messages.dply.io`).
4. In dply: `DPLY_MESSAGES_URL=https://messages.dply.io`, `DPLY_MESSAGES_OPERATOR_TOKEN=…`,
   then `php artisan dply:feature messages {org}` for the organizations that get it.
