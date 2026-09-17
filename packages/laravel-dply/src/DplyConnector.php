<?php

declare(strict_types=1);

namespace Dply\Laravel;

use Illuminate\Contracts\Queue\Queue;
use Illuminate\Queue\Connectors\ConnectorInterface;

class DplyConnector implements ConnectorInterface
{
    /** @param array<string, mixed> $config */
    public function connect(array $config): Queue
    {
        return new DplyQueue(
            rtrim((string) ($config['app_url'] ?? ''), '/'),
            (string) ($config['token'] ?? ''),
            (string) ($config['queue'] ?? 'JOBS'),
        );
    }
}
