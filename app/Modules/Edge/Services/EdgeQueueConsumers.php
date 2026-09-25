<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeDeliveryContext;
use App\Modules\Edge\Support\FakeEdgeProvision;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Who runs a queue's jobs, and how fast (ruling r-72p0gkdn9dqwxqha, T-019).
 *
 * A Cloudflare queue has one consumer. The earliest-created production app
 * attached to it runs its jobs; every other app only sends.
 *
 *   - A container owner consumes in its own Worker (EdgeContainerDeployer).
 *   - A Worker-site owner (ssr, hybrid) is fed by the platform Worker:
 *     HOST_MAP `queue:{name}` says which live script gets the batch, and the
 *     platform Worker is registered as the queue's consumer.
 *
 * Speed comes from the org's tier: queue_concurrency, queue_batch_wait_seconds.
 */
class EdgeQueueConsumers
{
    public const BATCH_SIZE = 10;

    public const MAX_RETRIES = 5;

    /** The production app that runs this queue's jobs, or null when none is attached. */
    public static function owner(Organization $organization, string $queue): ?Site
    {
        return $organization->sites()
            ->whereNotNull('edge_backend')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->first(fn (Site $site): bool => ! $site->isEdgePreview() && collect(EdgeContainerConnections::for($site))
                ->contains(fn (array $c): bool => $c['kind'] === 'queue' && ! $c['asleep'] && $c['target'] === $queue));
    }

    public static function owns(Site $site, string $queue): bool
    {
        $organization = $site->organization;

        return $organization !== null && self::owner($organization, $queue)?->is($site) === true;
    }

    /**
     * @return array{batch_size: int, max_concurrency?: int, max_retries: int, max_wait_time_ms: int}
     */
    public static function settings(Organization $organization): array
    {
        $tier = $organization->tierAllowances();
        $settings = [
            'batch_size' => self::BATCH_SIZE,
            'max_retries' => self::MAX_RETRIES,
            'max_wait_time_ms' => (int) ($tier['queue_batch_wait_seconds'] ?? 5) * 1000,
        ];
        // null means the Cloudflare maximum, which is what omitting it gives.
        $concurrency = array_key_exists('queue_concurrency', $tier) ? $tier['queue_concurrency'] : 1;
        if ($concurrency !== null) {
            $settings['max_concurrency'] = (int) $concurrency;
        }

        return $settings;
    }

    /** Shared secret between the platform Worker and the site's dply-entry.js. */
    public static function token(Site $site): string
    {
        return hash_hmac('sha256', 'worker-queue:'.$site->id, (string) config('app.key'));
    }

    /**
     * After a Worker site's production publish: point each queue it owns at
     * the live script and make the platform Worker the consumer.
     */
    public function sync(Site $site, EdgeDeployment $deployment, EdgeDeliveryContext $context): void
    {
        if ($site->isEdgePreview() || ! in_array($site->edgeMeta()['runtime_mode'] ?? '', ['ssr', 'hybrid'], true)) {
            return;
        }
        $script = self::liveScript($site, $deployment);
        if ($script === '') {
            return;
        }
        foreach (EdgeContainerConnections::for($site) as $connection) {
            if ($connection['kind'] !== 'queue' || $connection['asleep'] || ! self::owns($site, $connection['target'])) {
                continue;
            }
            try {
                app(EdgeHostMapPublisher::class)->putText('queue:'.$connection['target'], (string) json_encode([
                    'script' => $script,
                    'token' => self::token($site),
                ]), $context);
                $this->register($connection['target'], $context->workerScriptName, $site->organization);
            } catch (\Throwable $e) {
                Log::warning('Queue consumer sync failed', ['site_id' => (string) $site->id, 'queue' => $connection['target'], 'error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Re-apply tier speed to every Worker-site queue this org owns. Containers
     * pick it up on their next deploy. Only calls Cloudflare when the tier changed.
     */
    public function applyTier(Organization $organization): void
    {
        $tier = $organization->billingTier();
        $key = 'queue-consumer-tier:'.$organization->id;
        if (Cache::get($key) === $tier) {
            return;
        }
        foreach ($organization->sites()->whereNotNull('edge_backend')->get() as $site) {
            if (! in_array($site->edgeMeta()['runtime_mode'] ?? '', ['ssr', 'hybrid'], true)) {
                continue;
            }
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] === 'queue' && ! $connection['asleep'] && self::owns($site, $connection['target'])) {
                    try {
                        $context = app(EdgeDeliveryContextResolver::class)->forSite($site);
                        $this->register($connection['target'], $context->workerScriptName, $organization);
                    } catch (\Throwable $e) {
                        Log::warning('Queue tier apply failed', ['site_id' => (string) $site->id, 'error' => $e->getMessage()]);

                        return;
                    }
                }
            }
        }
        Cache::forever($key, $tier);
    }

    private function register(string $queue, string $consumerScript, ?Organization $organization): void
    {
        if ($organization === null || FakeEdgeProvision::enabled()) {
            return;
        }
        $client = EdgeCloudflareClient::fromConfig();
        $row = collect($client->listQueues())->first(fn ($q): bool => is_array($q) && ($q['queue_name'] ?? '') === $queue);
        $id = is_array($row) ? (string) ($row['queue_id'] ?? '') : '';
        if ($id === '') {
            throw new \RuntimeException("Queue {$queue} was not found.");
        }
        $client->putQueueConsumer($id, $consumerScript, self::settings($organization));
    }

    private static function liveScript(Site $site, EdgeDeployment $deployment): string
    {
        $meta = is_array($deployment->meta) ? $deployment->meta : [];

        return trim((string) ($meta['ssr']['script_name'] ?? $meta['middleware']['script_name'] ?? ''));
    }
}
