<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgePlatformUsage;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Called by CollectEdgePlatformUsageCommand. Reads Cloudflare GraphQL
 * Analytics for usage the shared account pays for but nothing billed:
 * Workers CPU per script, Durable Objects per script, and customer R2
 * buckets. Writes edge_platform_usage, one row per script or bucket per day.
 *
 * Attribution by name:
 *   dply-ctr-{site} / dply-state-{site}         → that site
 *   dply-ssr-{site tail 6}-… / dply-mw-{tail}-… → the one site with that tail
 *   R2 bucket dply-{org}-…                       → that org (object_storage)
 * Anything else (the platform's own Worker, dply-realtime, artifact buckets
 * dply-edge-{org}) is ours and skipped. Images transformations come per script
 * (imagesTransformationsAdaptiveGroups.billableEventCount). Workers AI and
 * Browser Rendering are not here: aiInferenceAdaptiveGroups and the
 * browserRendering* datasets have no script dimension, so they are metered
 * per call by dply's proxy instead (EdgeMeter). Vectorize queries are too;
 * what an org's indexes store is read here (vectorize:{index} rows).
 *
 * Each dataset is its own query. A failed one is logged and reported, and
 * leaves its columns as they were; it never zeroes what an earlier run found.
 */
final class EdgePlatformUsageCollector
{
    /** Same split as EdgeCloudflareClient::fetchR2BucketUsage. */
    private const R2_CLASS_A = ['PutObject', 'CopyObject', 'ListObjects', 'CreateMultipartUpload', 'UploadPart', 'CompleteMultipartUpload', 'DeleteObject', 'AbortMultipartUpload'];

    private const R2_CLASS_B = ['GetObject', 'HeadObject'];

    /** @var array{sites: array<string, array{organization_id: string, site_id: string}>, tails: array<string, list<string>>, orgs: array<string, string>}|null */
    private ?array $owners = null;

    public function __construct(private ?EdgeCloudflareClient $client = null) {}

    /**
     * @return array{resources: int, failed: list<string>}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $client = $this->client ?? EdgeCloudflareClient::fromConfig();
        $this->owners = null;
        $day = $date->copy()->utc()->startOfDay();
        $vars = [
            'date' => $day->toDateString(),
            'since' => $day->toIso8601String(),
            'until' => $day->copy()->addDay()->toIso8601String(),
        ];

        /** @var array<string, array<string, int|float>> $usage resource => columns */
        $usage = [];
        $failed = [];
        $datasets = [
            'workers' => fn () => $this->workers($client, $vars),
            'durable_objects' => fn () => $this->durableObjects($client, $vars),
            'r2' => fn () => $this->r2($client, $vars),
            'images' => fn () => $this->images($client, $vars),
            'vectorize' => fn () => $this->vectorize($client, $day),
        ];
        foreach ($datasets as $dataset => $read) {
            try {
                foreach ($read() as $resource => $columns) {
                    $usage[$resource] = ($usage[$resource] ?? []) + $columns;
                }
            } catch (Throwable $e) {
                $failed[] = $dataset;
                Log::error('Edge platform usage: dataset failed, its columns left as they were', ['dataset' => $dataset, 'date' => $vars['date'], 'error' => $e->getMessage()]);
            }
        }

        $resources = 0;
        foreach ($usage as $resource => $columns) {
            $owner = $this->owner((string) $resource);
            if ($owner === null) {
                continue;
            }
            $resources++;
            if ($dryRun) {
                continue;
            }
            // Full-day totals: re-running a day overwrites with the same or larger numbers.
            EdgePlatformUsage::query()->updateOrCreate(
                ['resource' => $resource, 'date' => $vars['date']],
                $owner + $columns,
            );
        }

        return ['resources' => $resources, 'failed' => $failed];
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, array{cpu_ms: int}>
     */
    private function workers(EdgeCloudflareClient $client, array $vars): array
    {
        $account = $client->accountAnalytics(<<<'GRAPHQL'
        query WorkersCpu($accountTag: string!, $since: Time!, $until: Time!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              workersInvocationsAdaptive(limit: 10000, filter: { datetime_geq: $since, datetime_lt: $until }) {
                dimensions { scriptName }
                sum { cpuTimeUs }
              }
            }
          }
        }
        GRAPHQL, ['since' => $vars['since'], 'until' => $vars['until']]);

        $out = [];
        foreach ((array) ($account['workersInvocationsAdaptive'] ?? []) as $group) {
            $script = (string) data_get($group, 'dimensions.scriptName', '');
            // ponytail: a State script's CPU is billed as Durable Object duration, so it is skipped here.
            if ($script === '' || str_starts_with($script, 'dply-state-')) {
                continue;
            }
            $out[$script]['cpu_ms'] = ($out[$script]['cpu_ms'] ?? 0) + (int) round((float) data_get($group, 'sum.cpuTimeUs', 0) / 1000);
        }

        return $out;
    }

    /**
     * Requests carry the script name; the periodic (duration, rows) and storage
     * datasets only carry the namespace, so they map through namespaceScripts.
     *
     * @param  array<string, string>  $vars
     * @return array<string, array<string, int|float>>
     */
    private function durableObjects(EdgeCloudflareClient $client, array $vars): array
    {
        $blank = ['do_requests' => 0, 'do_gb_seconds' => 0.0, 'do_rows_read' => 0, 'do_rows_written' => 0, 'do_storage_bytes' => 0];
        $invocations = (array) ($client->accountAnalytics(<<<'GRAPHQL'
        query DoRequests($accountTag: string!, $date: Date!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              durableObjectsInvocationsAdaptiveGroups(limit: 10000, filter: { date_geq: $date, date_leq: $date }) {
                dimensions { namespaceId scriptName }
                sum { requests }
              }
            }
          }
        }
        GRAPHQL, ['date' => $vars['date']])['durableObjectsInvocationsAdaptiveGroups'] ?? []);
        $scripts = $this->namespaceScripts($invocations);

        $out = [];
        foreach ($invocations as $group) {
            $script = (string) data_get($group, 'dimensions.scriptName', '');
            if ($script !== '') {
                $out[$script] ??= $blank;
                $out[$script]['do_requests'] += (int) data_get($group, 'sum.requests', 0);
            }
        }

        $account = $client->accountAnalytics(<<<'GRAPHQL'
        query DoDuration($accountTag: string!, $date: Date!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              durableObjectsPeriodicGroups(limit: 10000, filter: { date_geq: $date, date_leq: $date }) {
                dimensions { namespaceId }
                sum { duration rowsRead rowsWritten }
              }
              durableObjectsStorageGroups(limit: 10000, filter: { date_geq: $date, date_leq: $date }) {
                dimensions { namespaceIds }
                max { storedBytes }
              }
            }
          }
        }
        GRAPHQL, ['date' => $vars['date']]);
        // ponytail: a namespace with no requests today has no script to map to, so its
        // storage that day is skipped; the month's cost takes the peak day. Upgrade: the
        // REST list of Durable Object namespaces gives namespace → script directly.
        foreach ((array) ($account['durableObjectsPeriodicGroups'] ?? []) as $group) {
            $script = $scripts[(string) data_get($group, 'dimensions.namespaceId', '')] ?? null;
            if ($script === null) {
                continue;
            }
            $out[$script] ??= $blank;
            // duration is Cloudflare's billed GB-s (128 MB per active object).
            $out[$script]['do_gb_seconds'] += (float) data_get($group, 'sum.duration', 0);
            $out[$script]['do_rows_read'] += (int) data_get($group, 'sum.rowsRead', 0);
            $out[$script]['do_rows_written'] += (int) data_get($group, 'sum.rowsWritten', 0);
        }
        foreach ((array) ($account['durableObjectsStorageGroups'] ?? []) as $group) {
            // The storage dataset groups by a list of namespaces (namespaceIds), not one;
            // a group naming several is a shared total we cannot split, so it is skipped.
            $namespaces = (array) data_get($group, 'dimensions.namespaceIds', []);
            $script = count($namespaces) === 1 ? ($scripts[(string) $namespaces[0]] ?? null) : null;
            if ($script === null) {
                continue;
            }
            $out[$script] ??= $blank;
            $out[$script]['do_storage_bytes'] = max($out[$script]['do_storage_bytes'], (int) data_get($group, 'max.storedBytes', 0));
        }

        return $out;
    }

    /**
     * @param  array<int, mixed>  $invocations
     * @return array<string, string> namespaceId => scriptName
     */
    private function namespaceScripts(array $invocations): array
    {
        $map = [];
        foreach ($invocations as $group) {
            $namespace = (string) data_get($group, 'dimensions.namespaceId', '');
            $script = (string) data_get($group, 'dimensions.scriptName', '');
            if ($namespace !== '' && $script !== '') {
                $map[$namespace] = $script;
            }
        }

        return $map;
    }

    /**
     * Stored dimensions (vectors × dimensions) of each org's Vectorize index.
     *
     * @return array<string, array{vector_stored_dims: int}>
     */
    private function vectorize(EdgeCloudflareClient $client, CarbonInterface $day): array
    {
        // ponytail: stored size is a snapshot of now, so only today's run
        // records it; the month bills each index's biggest day.
        if (! $day->isSameDay(now()->utc())) {
            return [];
        }
        $out = [];
        foreach ($client->listVectorizeIndexes() as $index) {
            $name = (string) ($index['name'] ?? '');
            if (preg_match('/^dply-[0-9a-z]{26}-/', $name) !== 1) {
                continue;
            }
            $info = (array) ($client->getVectorizeIndex($name)['info'] ?? []);
            $out['vectorize:'.$name] = ['vector_stored_dims' => (int) ($info['vectorCount'] ?? 0) * (int) ($info['dimensions'] ?? data_get($index, 'config.dimensions', 0))];
        }

        return $out;
    }

    /**
     * Cloudflare bills unique transformations per month; billableEventCount is
     * its sampled estimate of those, per calling script.
     *
     * @param  array<string, string>  $vars
     * @return array<string, array{images_transformations: int}>
     */
    private function images(EdgeCloudflareClient $client, array $vars): array
    {
        $account = $client->accountAnalytics(<<<'GRAPHQL'
        query Images($accountTag: string!, $since: Time!, $until: Time!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              imagesTransformationsAdaptiveGroups(limit: 10000, filter: { datetime_geq: $since, datetime_lt: $until }) {
                dimensions { scriptName }
                sum { billableEventCount }
              }
            }
          }
        }
        GRAPHQL, ['since' => $vars['since'], 'until' => $vars['until']]);

        $out = [];
        foreach ((array) ($account['imagesTransformationsAdaptiveGroups'] ?? []) as $group) {
            $script = (string) data_get($group, 'dimensions.scriptName', '');
            if ($script !== '') {
                $out[$script]['images_transformations'] = ($out[$script]['images_transformations'] ?? 0) + (int) data_get($group, 'sum.billableEventCount', 0);
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, array{r2_storage_bytes: int, r2_class_a_ops: int, r2_class_b_ops: int}>
     */
    private function r2(EdgeCloudflareClient $client, array $vars): array
    {
        $account = $client->accountAnalytics(<<<'GRAPHQL'
        query R2Buckets($accountTag: string!, $since: Time!, $until: Time!) {
          viewer {
            accounts(filter: { accountTag: $accountTag }) {
              r2StorageAdaptiveGroups(limit: 10000, filter: { datetime_geq: $since, datetime_lt: $until }) {
                dimensions { bucketName }
                max { payloadSize metadataSize }
              }
              r2OperationsAdaptiveGroups(limit: 10000, filter: { datetime_geq: $since, datetime_lt: $until }) {
                dimensions { bucketName actionType }
                sum { requests }
              }
            }
          }
        }
        GRAPHQL, ['since' => $vars['since'], 'until' => $vars['until']]);

        $blank = ['r2_storage_bytes' => 0, 'r2_class_a_ops' => 0, 'r2_class_b_ops' => 0];
        $out = [];
        foreach ((array) ($account['r2StorageAdaptiveGroups'] ?? []) as $group) {
            $bucket = (string) data_get($group, 'dimensions.bucketName', '');
            if ($bucket === '') {
                continue;
            }
            $out[$bucket] ??= $blank;
            $bytes = (int) data_get($group, 'max.payloadSize', 0) + (int) data_get($group, 'max.metadataSize', 0);
            $out[$bucket]['r2_storage_bytes'] = max($out[$bucket]['r2_storage_bytes'], $bytes);
        }
        foreach ((array) ($account['r2OperationsAdaptiveGroups'] ?? []) as $group) {
            $bucket = (string) data_get($group, 'dimensions.bucketName', '');
            $action = (string) data_get($group, 'dimensions.actionType', '');
            $column = in_array($action, self::R2_CLASS_A, true) ? 'r2_class_a_ops' : (in_array($action, self::R2_CLASS_B, true) ? 'r2_class_b_ops' : null);
            if ($bucket === '' || $column === null) {
                continue;
            }
            $out[$bucket] ??= $blank;
            $out[$bucket][$column] += (int) data_get($group, 'sum.requests', 0);
        }

        return $out;
    }

    /**
     * @return array{organization_id: string, site_id: ?string}|null
     */
    private function owner(string $resource): ?array
    {
        $owners = $this->owners ??= $this->loadOwners();

        if (preg_match('/^dply-(?:ctr|state)-([0-9a-z]{26})$/', $resource, $m) === 1) {
            $site = $m[1];
        } elseif (preg_match('/^dply-(?:ssr|mw)-([0-9a-z]{6})-/', $resource, $m) === 1) {
            $matches = $owners['tails'][$m[1]] ?? [];
            // Two sites sharing a six-character tail: we cannot tell whose it is.
            $site = count($matches) === 1 ? $matches[0] : null;
        } elseif (preg_match('/^(?:vectorize:)?dply-([0-9a-z]{26})-/', $resource, $m) === 1 && isset($owners['orgs'][$m[1]])) {
            return ['organization_id' => $owners['orgs'][$m[1]], 'site_id' => null];
        } else {
            return null;
        }

        return $site !== null && isset($owners['sites'][$site])
            ? $owners['sites'][$site]
            : null;
    }

    /**
     * ponytail: loads every site id once per run; fine at thousands of sites, index by
     * script name on deploy if it ever is not.
     *
     * @return array{sites: array<string, array{organization_id: string, site_id: string}>, tails: array<string, list<string>>, orgs: array<string, string>}
     */
    private function loadOwners(): array
    {
        $sites = [];
        $tails = [];
        foreach (Site::query()->toBase()->get(['id', 'organization_id']) as $row) {
            $id = strtolower((string) $row->id);
            $sites[$id] = ['organization_id' => (string) $row->organization_id, 'site_id' => (string) $row->id];
            $tails[substr($id, -6)][] = $id;
        }
        $orgs = [];
        foreach (Organization::query()->pluck('id') as $id) {
            $orgs[strtolower((string) $id)] = (string) $id;
        }

        return ['sites' => $sites, 'tails' => $tails, 'orgs' => $orgs];
    }
}
