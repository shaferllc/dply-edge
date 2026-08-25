<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services;

use App\Models\EdgeUsageSnapshot;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeUsageTotals;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;

/**
 * Persists daily Edge usage snapshots per site. v1 pulls from Cloudflare when
 * credentials + zone are configured; otherwise records zero rows tagged
 * {@see EdgeUsageSnapshot::SOURCE_PLACEHOLDER} so billing hooks stay wired.
 */
class EdgeUsageCollector
{
    public function __construct(
        private ?EdgeCloudflareClient $cloudflare = null,
        private ?EdgeSiteR2StorageEstimator $r2Storage = null,
    ) {}

    private function client(): EdgeCloudflareClient
    {
        return $this->cloudflare ?? EdgeCloudflareClient::fromConfig();
    }

    /**
     * @return array{sites: int, snapshots: int, source: string}
     */
    public function collectForDate(CarbonInterface $date, bool $dryRun = false): array
    {
        $periodStart = $date->copy()->startOfDay();
        $periodEnd = $date->copy()->endOfDay();
        $source = $this->resolveSource();
        $sites = $this->billableEdgeSites();
        $snapshotCount = 0;

        $usageByHostname = $source === EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL
            ? $this->fetchCloudflareUsage($periodStart, $periodEnd, $sites)
            : collect();

        $r2BucketUsage = $source === EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL
            ? $this->fetchR2BucketUsage($periodStart, $periodEnd)
            : EdgeUsageTotals::empty();

        $storageBySite = $this->r2Storage()->storageBytesBySite($sites);
        $totalRequests = max(1, (int) $usageByHostname->sum(fn (EdgeUsageTotals $totals): int => $totals->requests));

        foreach ($sites as $site) {
            $hostnames = $site->edgeUsageHostnames();
            $usage = $this->usageForSite($hostnames, $usageByHostname);

            if ($source === EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL) {
                $ratio = $usage->requests / $totalRequests;
                $usage = $usage->add(new EdgeUsageTotals(
                    r2StorageBytes: $storageBySite[$site->id] ?? 0,
                    r2ClassAOps: (int) round($r2BucketUsage->r2ClassAOps * $ratio),
                    r2ClassBOps: (int) round($r2BucketUsage->r2ClassBOps * $ratio),
                ));
            }
            $primaryHostname = $hostnames[0] ?? null;

            if ($dryRun) {
                $snapshotCount++;

                continue;
            }

            EdgeUsageSnapshot::query()->updateOrCreate(
                [
                    'site_id' => $site->id,
                    'period_start' => $periodStart->toDateString(),
                    'source' => $source,
                ],
                [
                    'organization_id' => $site->organization_id,
                    'period_end' => $periodEnd->toDateString(),
                    'requests' => $usage->requests,
                    'bytes_egress' => $usage->bytesEgress,
                    'r2_storage_bytes' => $usage->r2StorageBytes,
                    'r2_class_a_ops' => $usage->r2ClassAOps,
                    'r2_class_b_ops' => $usage->r2ClassBOps,
                    'meta' => [
                        'hostname' => $primaryHostname,
                        'hostnames' => $hostnames !== [] ? $hostnames : null,
                        'collector' => 'EdgeUsageCollector',
                        'cloudflare_automated' => $source === EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL,
                        'r2_collected' => $source === EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL && ! $r2BucketUsage->isEmpty(),
                    ],
                ],
            );

            $snapshotCount++;
        }

        if ($source === EdgeUsageSnapshot::SOURCE_PLACEHOLDER && $sites->isNotEmpty()) {
            Log::info('edge.usage.collector_placeholder', [
                'date' => $periodStart->toDateString(),
                'sites' => $sites->count(),
                'hint' => 'Set DPLY_EDGE_CF_ACCOUNT_ID, DPLY_EDGE_CF_API_TOKEN, and DPLY_EDGE_CF_ZONE_NAME for automated Cloudflare GraphQL collection.',
            ]);
        }

        return [
            'sites' => $sites->count(),
            'snapshots' => $snapshotCount,
            'source' => $source,
        ];
    }

    /**
     * @return Collection<int, Site>
     */
    private function billableEdgeSites(): Collection
    {
        return Site::query()
            ->where('status', Site::STATUS_EDGE_ACTIVE)
            ->whereNotNull('edge_backend')
            ->where('edge_backend', '!=', '')
            ->get()
            ->reject(fn (Site $site): bool => $site->isEdgePreview())
            ->values();
    }

    private function resolveSource(): string
    {
        if ($this->client()->canCollectAnalytics()) {
            return EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL;
        }

        return EdgeUsageSnapshot::SOURCE_PLACEHOLDER;
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @return Collection<string, EdgeUsageTotals>
     */
    private function fetchCloudflareUsage(
        CarbonInterface $periodStart,
        CarbonInterface $periodEnd,
        Collection $sites,
    ): Collection {
        /** @var array<string, list<string>> $hostnamesByZone */
        $hostnamesByZone = [];

        foreach ($sites as $site) {
            foreach ($site->edgeUsageHostnameZones() as $hostname => $zone) {
                $hostnamesByZone[$zone] ??= [];
                $hostnamesByZone[$zone][] = $hostname;
            }
        }

        if ($hostnamesByZone === []) {
            return collect();
        }

        /** @var Collection<string, EdgeUsageTotals> $usageByHostname */
        $usageByHostname = collect();

        foreach ($hostnamesByZone as $zone => $hostnames) {
            $hostnames = array_values(array_unique($hostnames));

            try {
                $zoneUsage = $this->client()->fetchHttpUsageByHostnames(
                    $hostnames,
                    $periodStart,
                    $periodEnd,
                    $zone,
                );

                foreach ($zoneUsage as $hostname => $totals) {
                    $existing = $usageByHostname->get($hostname, new EdgeUsageTotals);
                    $usageByHostname->put($hostname, $existing->add($totals));
                }
            } catch (\Throwable $e) {
                Log::warning('edge.usage.cloudflare_fetch_failed', [
                    'zone' => $zone,
                    'hostnames' => $hostnames,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $usageByHostname;
    }

    /**
     * @param  list<string>  $hostnames
     * @param  Collection<string, EdgeUsageTotals>  $usageByHostname
     */
    private function usageForSite(array $hostnames, Collection $usageByHostname): EdgeUsageTotals
    {
        $usage = EdgeUsageTotals::empty();

        foreach ($hostnames as $hostname) {
            if ($usageByHostname->has($hostname)) {
                $usage = $usage->add($usageByHostname->get($hostname));
            }
        }

        return $usage;
    }

    private function r2Storage(): EdgeSiteR2StorageEstimator
    {
        return $this->r2Storage ?? app(EdgeSiteR2StorageEstimator::class);
    }

    private function fetchR2BucketUsage(CarbonInterface $periodStart, CarbonInterface $periodEnd): EdgeUsageTotals
    {
        try {
            return $this->client()->fetchR2BucketUsage($periodStart, $periodEnd);
        } catch (\Throwable $e) {
            Log::warning('edge.usage.r2_fetch_failed', [
                'error' => $e->getMessage(),
            ]);

            return EdgeUsageTotals::empty();
        }
    }
}
