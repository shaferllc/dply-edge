<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace\Concerns\Resources;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Modules\Billing\Services\EdgePlatformUsageCost;
use App\Modules\Edge\Jobs\DeleteEdgeKvKeysByPrefixJob;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Support\Facades\Cache;

/**
 * Resources sheet: Key-value (KV) and object storage (R2) additions. Mixed into
 * {@see Resources}; the open connection
 * is $this->openResourceConnection() (set by openResource()).
 */
trait ManagesStorageResources
{
    /**
     * Where Cloudflare may place a new bucket ('' lets it choose). A
     * jurisdiction (eu, fedramp) is not offered: every later API call and
     * binding would need the jurisdiction too, and neither sends it yet.
     */
    public const R2_LOCATION_HINTS = [
        '' => 'Automatic',
        'wnam' => 'Western North America',
        'enam' => 'Eastern North America',
        'weur' => 'Western Europe',
        'eeur' => 'Eastern Europe',
        'apac' => 'Asia-Pacific',
        'oc' => 'Oceania',
    ];

    /** Seconds one "Empty and delete" click spends deleting, inside PHP's 30s request limit. */
    private const R2_EMPTY_SECONDS = 20;

    public string $kvPrefix = '';

    /** Next page of keys, or null when the list is complete. */
    public ?string $kvCursor = null;

    /** Seconds before a demo write expires; empty keeps it. */
    public string $kvDemoTtl = '';

    /** @var array{expiration: ?int, metadata: mixed}|null Last demo read's key details. */
    public ?array $kvDemoMeta = null;

    public string $objectPrefix = '';

    /** Next page of objects, or null when the list is complete. */
    public ?string $objectCursor = null;

    /** @var array{storage: int, class_a: int, class_b: int, objects: int, cents: int}|null This month, for the open bucket. */
    public ?array $objectUsage = null;

    public string $objectLocationHint = '';

    /** Keys under the searched prefix, from the first listing page ("12" or "1,000+"); null until asked. */
    public ?string $kvDeleteCount = null;

    /** The prefix typed again to confirm a delete by prefix. */
    public string $kvDeleteConfirm = '';

    public function previewKvPrefixDelete(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag('kvDelete');
        $this->kvDeleteCount = null;
        $this->kvDeleteConfirm = '';
        $connection = $this->kvConnection();
        $prefix = trim($this->kvPrefix);
        if ($connection === null) {
            return;
        }
        if ($prefix === '') {
            $this->addError('kvDelete', __('Search for a prefix first. To remove every key, delete the store.'));

            return;
        }
        try {
            $page = EdgeCloudflareClient::fromConfig()->listKvKeysPage($connection['target'], null, $prefix, 1000);
        } catch (\Throwable) {
            $this->addError('kvDelete', __('The store did not answer.'));

            return;
        }
        if ($page['keys'] === []) {
            $this->addError('kvDelete', __('No keys start with that.'));

            return;
        }
        $this->kvDeleteCount = $page['cursor'] === null ? number_format(count($page['keys'])) : '1,000+';
    }

    public function deleteKvPrefix(): void
    {
        $this->authorize('update', $this->site);
        $this->resetErrorBag('kvDelete');
        $connection = $this->kvConnection();
        $prefix = trim($this->kvPrefix);
        if ($connection === null || $prefix === '' || $this->kvDeleteCount === null) {
            return;
        }
        if ($this->kvDeleteConfirm !== $prefix) {
            $this->addError('kvDelete', __('Type the prefix exactly to confirm.'));

            return;
        }
        $running = DeleteEdgeKvKeysByPrefixJob::progress($connection['target']);
        if (is_array($running) && ! $running['done']) {
            $this->addError('kvDelete', __('A delete is already running on this store.'));

            return;
        }
        DeleteEdgeKvKeysByPrefixJob::start($connection['target'], $prefix);
        $this->kvDeleteCount = null;
        $this->kvDeleteConfirm = '';
    }

    /** @return array{prefix: string, removed: int, done: bool, failed: ?string}|null */
    public function kvPrefixDeletion(): ?array
    {
        $connection = $this->kvConnection();

        return $connection === null ? null : DeleteEdgeKvKeysByPrefixJob::progress($connection['target']);
    }

    public function loadMoreKvKeys(): void
    {
        $this->authorize('update', $this->site);
        $connection = $this->kvConnection();
        if ($connection === null || $this->kvCursor === null) {
            return;
        }
        try {
            $page = EdgeCloudflareClient::fromConfig()->listKvKeysPage($connection['target'], $this->kvCursor, trim($this->kvPrefix));
        } catch (\Throwable) {
            $this->addError('kvSettings', __('The store did not answer.'));

            return;
        }
        $this->kvKeys = collect([...$this->kvKeys, ...$page['keys']])->unique('name')->values()->all();
        $this->kvCursor = $page['cursor'];
    }

    public function loadMoreObjects(): void
    {
        $this->authorize('update', $this->site);
        $connection = $this->objectConnection();
        if ($connection === null || $this->objectCursor === null) {
            return;
        }
        try {
            $page = EdgeCloudflareClient::fromConfig()->listR2ObjectsPage($connection['target'], $this->objectCursor, trim($this->objectPrefix));
        } catch (\Throwable) {
            $this->addError('object', __('The bucket did not answer.'));

            return;
        }
        $this->objectList = [...$this->objectList, ...$page['objects']];
        $this->objectCursor = $page['cursor'];
    }

    /**
     * Resources' card estimate hook ({kind}CostCents): this bucket's own
     * usage, not the app's deploy files. Null (no estimate shown) when
     * Cloudflare does not answer.
     */
    /**
     * The card figure comes from the cache only: render never waits on
     * Cloudflare. A miss fetches after the response, so the next load has it.
     */
    public function objectStorageCostCents(array $connection): ?int
    {
        $bucket = (string) ($connection['target'] ?? '');
        $hit = $bucket === '' ? null : Cache::get('edge-r2-bucket-usage:'.$bucket.':'.now()->format('Y-m'));
        if (! is_array($hit)) {
            if ($bucket !== '') {
                dispatch(fn () => $this->objectBucketUsage($bucket))->afterResponse();
            }

            return null;
        }

        return ($hit['ok'] ?? false) ? $hit['usage']['cents'] : null;
    }

    /**
     * Delete every object (a page at a time), then the bucket. Only for a
     * bucket this organization created. There is no bulk delete in the API,
     * so it stops after R2_EMPTY_SECONDS and says to run it again.
     * ponytail: one DELETE per object; a queued job if buckets get large.
     */
    public function emptyAndDeleteConnection(): void
    {
        $this->authorize('update', $this->site);
        $connection = collect(EdgeContainerConnections::for($this->site))->firstWhere('host', $this->deleteConnectionHost);
        if (! is_array($connection) || $connection['kind'] !== 'object_storage' || $this->site->organization === null) {
            return;
        }
        $this->resetErrorBag('connectionDelete');

        $removed = 0;
        try {
            if (! EdgeContainerConnections::owns('object_storage', $connection['target'], $this->site->organization)) {
                $this->addError('connectionDelete', __('This organization did not create this bucket, so it is not emptied. Delete resource detaches it.'));

                return;
            }
            $client = EdgeCloudflareClient::fromConfig();
            $deadline = microtime(true) + self::R2_EMPTY_SECONDS;
            // Always the first page: what was on it is gone now.
            while (microtime(true) < $deadline && ($objects = $client->listR2ObjectsPage($connection['target'])['objects']) !== []) {
                foreach ($objects as $object) {
                    if (microtime(true) >= $deadline) {
                        break;
                    }
                    $client->deleteR2Object($connection['target'], $object['key']);
                    $removed++;
                }
            }
            $left = $client->listR2ObjectsPage($connection['target'])['objects'] !== [];
        } catch (\Throwable $e) {
            $this->addError('connectionDelete', __('Removed :count files, then stopped: :message', ['count' => number_format($removed), 'message' => $e->getMessage()]));

            return;
        }
        if ($left) {
            $this->addError('connectionDelete', __('Removed :count files. More remain. Run Empty and delete again.', ['count' => number_format($removed)]));

            return;
        }

        $this->deleteConnection();
    }

    /** @return array{storage: int, class_a: int, class_b: int, objects: int, cents: int}|null */
    private function objectBucketUsage(string $bucket): ?array
    {
        if ($bucket === '') {
            return null;
        }
        $key = 'edge-r2-bucket-usage:'.$bucket.':'.now()->format('Y-m');
        try {
            $hit = Cache::get($key);
            if (is_array($hit)) {
                return ($hit['ok'] ?? false) ? $hit['usage'] : null;
            }
            $totals = EdgeCloudflareClient::fromConfig()->fetchR2BucketUsage(now()->startOfMonth(), now(), $bucket);
            $usage = [
                'storage' => $totals->r2StorageBytes,
                'class_a' => $totals->r2ClassAOps,
                'class_b' => $totals->r2ClassBOps,
                'objects' => $totals->r2ObjectCount,
                'cents' => $this->r2UsageCents($totals->r2StorageBytes, $totals->r2ClassAOps, $totals->r2ClassBOps),
            ];
            Cache::put($key, ['ok' => true, 'usage' => $usage], now()->addMinutes(15));

            return $usage;
        } catch (\Throwable) {
            // Remember the miss briefly so every render does not ask again.
            try {
                Cache::put($key, ['ok' => false], now()->addMinutes(2));
            } catch (\Throwable) {
            }

            return null;
        }
    }

    /**
     * R2 at the billing rates (dply.edge.usage_billing, as EdgeUsageCostCalculator
     * uses them): one app's included storage and operations, then markup.
     */
    /** Priced exactly as the bill prices customer buckets (EdgePlatformUsageCost). */
    private function r2UsageCents(int $storageBytes, int $classA, int $classB): int
    {
        return app(EdgePlatformUsageCost::class)->cents(['r2_storage_bytes' => $storageBytes, 'r2_class_a_ops' => $classA, 'r2_class_b_ops' => $classB]);
    }

    /** @return array{expiration: ?int, metadata: mixed}|null */
    private function kvKeyDetails(EdgeCloudflareClient $client, string $namespaceId, string $key): ?array
    {
        try {
            foreach ($client->listKvKeysPage($namespaceId, null, $key, 10)['keys'] as $row) {
                if ($row['name'] === $key) {
                    return ['expiration' => $row['expiration'], 'metadata' => $row['metadata']];
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }
}
