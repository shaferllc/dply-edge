<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Push-only queue: jobs go to Cloudflare Queues through the site Worker, and
 * come back as batches on /_dply/queue. Nothing is ever popped here.
 */
class DplyQueue extends Queue implements QueueContract
{
    public function __construct(
        private readonly string $appUrl,
        private readonly string $token,
        private readonly string $default,
    ) {}

    public function size($queue = null): int
    {
        return 0;
    }

    public function pendingSize($queue = null): int
    {
        return 0;
    }

    public function delayedSize($queue = null): int
    {
        return 0;
    }

    public function reservedSize($queue = null): int
    {
        return 0;
    }

    public function creationTimeOfOldestPendingJob($queue = null): ?int
    {
        return null;
    }

    public function push($job, $data = '', $queue = null)
    {
        return $this->enqueueUsing($job, $this->createPayload($job, $this->getQueue($queue), $data), $queue, null,
            fn ($payload, $queue) => $this->pushRaw($payload, $queue));
    }

    public function pushRaw($payload, $queue = null, array $options = [])
    {
        return $this->send($payload, $queue, (int) ($options['delay'] ?? 0));
    }

    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->enqueueUsing($job, $this->createPayload($job, $this->getQueue($queue), $data), $queue, $delay,
            fn ($payload, $queue, $delay) => $this->send($payload, $queue, $this->secondsUntil($delay)));
    }

    public function pop($queue = null)
    {
        return null;
    }

    public function getQueue(?string $queue): string
    {
        return $queue ?: $this->default;
    }

    private function send(string $payload, ?string $queue, int $delay): ?string
    {
        if ($this->appUrl === '' || $this->token === '') {
            throw new RuntimeException('dply queue: DPLY_APP_URL and DPLY_QUEUE_TOKEN must be set (dply injects them on Edge containers).');
        }

        Http::withHeaders(['x-dply-queue-token' => $this->token])
            ->timeout(10)
            ->post($this->appUrl.'/_dply/queue/send', [
                'queue' => $this->getQueue($queue),
                'body' => json_decode($payload, true),
                'delay' => max(0, $delay),
            ])
            ->throw();

        return json_decode($payload, true)['uuid'] ?? null;
    }
}
