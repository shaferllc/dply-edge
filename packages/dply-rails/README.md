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
`/_dply/schedule` from a Cloudflare Cron Trigger).

`queue_as` names map to queue bindings (default `JOBS`, set `DPLY_QUEUE` to change).
Limits: 128 KB per job, 24 h max `wait`.
