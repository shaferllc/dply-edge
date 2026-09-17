<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/** One message from a Cloudflare Queues batch. */
class DplyJob extends Job implements JobContract
{
    public function __construct(
        Container $container,
        private readonly string $rawBody,
        private readonly int $attempts,
        private readonly string $messageId,
        string $connectionName,
        string $queue,
    ) {
        $this->container = $container;
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return $this->messageId;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function attempts(): int
    {
        return max(1, $this->attempts);
    }
}
