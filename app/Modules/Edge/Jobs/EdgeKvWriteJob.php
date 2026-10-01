<?php

declare(strict_types=1);

namespace App\Modules\Edge\Jobs;

use App\Modules\Edge\Services\EdgeKvInstant;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * One KV Instant write (EdgeKvInstant). Throttled to one per second per
 * namespace (the KV Instant limit; the "kv-instant" limiter in
 * EdgeServiceProvider), and a key already waiting is not queued again: the
 * value is read from the database when the job runs, so the later change is
 * the one written, once ($0.10 a write).
 */
final class EdgeKvWriteJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Released while throttled; give up after retryUntil(). */
    public int $tries = 0;

    /** @param  array<int, string>  $args */
    public function __construct(
        public string $namespace,
        public string $key,
        public string $kind,
        public array $args = [],
    ) {}

    public function uniqueId(): string
    {
        return $this->namespace.':'.$this->key;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('kv-instant')];
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(EdgeKvInstant $kv): void
    {
        $kv->apply($this->namespace, $this->key, $this->kind, $this->args);
    }
}
