# dply-rails

Active Job on Cloudflare Queues for Rails apps deployed as **dply Edge containers**.

```ruby
# Gemfile
gem "dply-rails"
```

```ruby
# config/environments/production.rb
config.active_job.queue_adapter = :dply
```

No Sidekiq or worker process to run:

- `SendInvoiceJob.perform_later(order)` sends the job to the site's Cloudflare
  Queue through its Worker (`DPLY_APP_URL` + `DPLY_QUEUE_TOKEN`, injected by dply).
- The site Worker consumes the queue and POSTs batches to `/_dply/queue`; the
  gem's middleware runs each job. Jobs that raise are retried by Cloudflare
  (Active Job's own `retry_on` still applies first).

Crons added under **Crons** run their handler as a rake task (POST
`/_dply/schedule` from a Cloudflare Cron Trigger). **Run now** on the Crons
tab runs a listed task once in the live app; rake tasks print no output.

## Key-value store

Attach a key-value store under Resources. The next deploy injects
`DPLY_KV_HOST`. Unless `REDIS_URL` is set, `Rails.cache` uses that store:

```ruby
Rails.cache.write("session", "hello")
Rails.cache.read("session")
Rails.cache.delete("session")
```

`Dply::Rails::Kv` is the same store when you want it by name. `read_multi`
reads up to 100 keys per request, and `Rails.cache.clear` removes every key.
`increment` and `decrement` raise: the store is eventually consistent, so
counters and rate limiting need Valkey (Redis) or State.

`queue_as` names map to queue bindings (default `JOBS`, set `DPLY_QUEUE` to change).
Limits: 128 KB per job, 24 h max `wait`.

## Changelog

### 0.2.0

- `Rails.cache.clear` removes every key on the key-value store.
- `Rails.cache.read_multi` reads up to 100 keys per request.
- `Rails.cache.increment` / `decrement` raise with a message pointing to
  Valkey (Redis) or State, instead of Rails' generic "does not support".
