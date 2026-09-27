<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgePlatformUsage;
use App\Models\Organization;
use App\Modules\Billing\Support\UsagePrice;
use Carbon\CarbonInterface;

/**
 * Workers AI, Browser Rendering and Vectorize on the bill (the "ai" usage
 * line). Reads edge_platform_usage: the `meter:{site}` rows EdgeMeter adds to
 * from every proxied call, and the collector's `vectorize:{index}` rows.
 * Priced by UsagePrice from dply.edge.usage_billing.{ai,browser,vector}_*.
 *
 * Vectorize bills a month's queried dimensions as (queried vectors + stored
 * vectors) × dimensions, so each index's peak stored dimensions count once as
 * queried as well as stored.
 */
class EdgeMeteredUsageCost
{
    /**
     * @return array{ai_neurons: float, browser_ms: int, vector_query_dims: int, vector_stored_dims: int, services: array{ai: int, browser: int, vectors: int}, cents: int}
     */
    public function forOrganization(Organization $organization, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = EdgePlatformUsage::query()
            ->where('organization_id', $organization->id)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->groupBy('resource')
            ->selectRaw('COALESCE(SUM(ai_neurons), 0) AS ai_neurons, COALESCE(SUM(browser_ms), 0) AS browser_ms, COALESCE(SUM(vector_query_dims), 0) AS vector_query_dims, COALESCE(MAX(vector_stored_dims), 0) AS vector_stored_dims')
            ->toBase()
            ->get();

        $usage = ['ai_neurons' => 0.0, 'browser_ms' => 0, 'vector_query_dims' => 0, 'vector_stored_dims' => 0];
        foreach ($rows as $row) {
            $usage['ai_neurons'] += (float) $row->ai_neurons;
            $usage['browser_ms'] += (int) $row->browser_ms;
            $usage['vector_query_dims'] += (int) $row->vector_query_dims;
            $usage['vector_stored_dims'] += (int) $row->vector_stored_dims;
        }
        $millicents = self::millicents($usage);

        return $usage + [
            'services' => array_map(static fn (float $mc): int => UsagePrice::cents($mc), $millicents),
            'cents' => UsagePrice::cents(array_sum($millicents)),
        ];
    }

    /**
     * Cost (before the margin) per service, millicents.
     *
     * @param  array{ai_neurons?: float|int, browser_ms?: int, vector_query_dims?: int, vector_stored_dims?: int}  $usage
     * @return array{ai: float, browser: float, vectors: float}
     */
    public static function millicents(array $usage): array
    {
        $stored = (float) ($usage['vector_stored_dims'] ?? 0);

        return [
            'ai' => (float) ($usage['ai_neurons'] ?? 0) / 1000 * UsagePrice::cost('ai_neurons_millicents_per_thousand'),
            'browser' => (float) ($usage['browser_ms'] ?? 0) / 3_600_000 * UsagePrice::cost('browser_millicents_per_hour'),
            'vectors' => ((float) ($usage['vector_query_dims'] ?? 0) + $stored) / 1_000_000 * UsagePrice::cost('vector_queried_millicents_per_million_dims')
                + $stored / 100_000_000 * UsagePrice::cost('vector_stored_millicents_per_hundred_million_dims'),
        ];
    }
}
