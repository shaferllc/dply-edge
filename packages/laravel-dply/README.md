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

## Key-value store

Attach a key-value store under Resources. The next deploy injects
`DPLY_KV_HOST` and, unless Redis is attached, `CACHE_STORE`. Then:

```php
Cache::put('session', 'hello');
Cache::get('session');
Cache::forget('session');
```

The store name is the resource name, lowercased: `Cache::store('testing')`.
A counter that must be exact belongs on State. Values expire only when the
TTL is at least 60 seconds.

## Object storage

Attach a bucket under Resources. The next deploy injects `DPLY_STORAGE_HOST`
and, unless you already set it, `FILESYSTEM_DISK`. Then the same calls you
use for S3 talk to that bucket:

```php
Storage::put('uploads/photo.jpg', $bytes);
Storage::get('uploads/photo.jpg');
Storage::disk('s3')->delete('uploads/photo.jpg');
```

A saved `AWS_ACCESS_KEY_ID` keeps your own `s3` disk. The bucket is still
`Storage::disk('uploads')` (the name you gave the resource, lowercased).

## Scheduler

Turn on **Container → Run the Laravel scheduler every minute** and dply calls
`schedule:run` on POST `/_dply/schedule` from a Cloudflare Cron Trigger. Crons
added under **Crons** run their handler as an artisan command.

Limits: 128 KB per job payload, 12 h max delay (Cloudflare Queues).
