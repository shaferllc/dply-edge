# dply/laravel

Laravel queues on Cloudflare Queues for apps deployed as **dply Edge containers**.

```bash
composer require dply/laravel
```

Then set `QUEUE_CONNECTION=dply` in the site's Environment. Nothing else to run —
there is no `queue:work` process:

- `dispatch(new SendInvoice($order))` sends the job to the site's Cloudflare
  Queue through its Worker (`DPLY_APP_URL` + `DPLY_QUEUE_TOKEN`, both injected
  by dply).
- The site Worker consumes the queue and POSTs each batch to `/_dply/queue`,
  which runs the jobs in the container. Failed jobs are retried by Cloudflare
  with Laravel's usual `tries` / `failed()` handling.

The queue binding defaults to `JOBS` (Edge → Jobs); pick another with
`DPLY_QUEUE=MY_BINDING` or `->onQueue('MY_BINDING')`.

Limits: 128 KB per job payload, 24 h max delay (Cloudflare Queues).
