<?php

declare(strict_types=1);

use App\Console\Scheduling\DplySchedule;
use App\Enums\SiteType;
use App\Models\EdgeKvUsage;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeKvCost;
use App\Modules\Billing\Services\EdgeOrganizationUsageReader;
use App\Modules\Billing\Services\EdgeUsageCostCalculator;
use App\Modules\Edge\Services\EdgeKvUsageCollector;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/*
 * What is measured is what is billed: site storage per site, a sleeping KV
 * store's storage, and every day's last hour of delivery usage.
 */

function measuredSite(Organization $org, array $edge = []): Site
{
    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge', 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => $edge],
    ]);
}

test('site storage is each site’s peak, summed, and all of it bills', function () {
    config(['dply.edge.usage_billing.enabled' => true, 'dply.edge.usage_billing.margin_percent' => 0]);
    $org = Organization::factory()->create();
    $gb = 1024 ** 3;
    foreach ([[measuredSite($org), [8 * $gb, 9 * $gb]], [measuredSite($org), [3 * $gb, 2 * $gb]]] as [$site, $days]) {
        foreach ($days as $i => $bytes) {
            EdgeUsageSnapshot::query()->create([
                'organization_id' => $org->id, 'site_id' => $site->id, 'source' => EdgeUsageSnapshot::SOURCE_CLOUDFLARE_GRAPHQL,
                'period_start' => '2026-09-0'.($i + 1), 'period_end' => '2026-09-0'.($i + 1), 'r2_storage_bytes' => $bytes,
            ]);
        }
    }

    $totals = app(EdgeOrganizationUsageReader::class)->totalsForOrganization($org, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));
    $estimate = app(EdgeUsageCostCalculator::class)->estimate($totals);

    // Was MAX over every row (9 GB). No per-site allowance any more.
    expect($totals->r2StorageBytes)->toBe(12 * $gb)
        ->and($estimate['billable_r2_storage_bytes'])->toBe(12 * $gb);
});

test('a sleeping key-value store still bills its usage and storage', function () {
    config(['dply.edge.usage_billing.margin_percent' => 20]);
    $org = Organization::factory()->create();
    $id = str_repeat('a', 32);
    measuredSite($org, ['connections' => [['kind' => 'key_value', 'name' => 'CACHE', 'host' => 'cache.kv.dply.internal', 'target' => $id, 'asleep' => true]]]);
    $client = Mockery::mock(EdgeCloudflareClient::class);
    $client->shouldReceive('kvUsageForDate')->andReturn([$id => ['reads' => 0, 'writes' => 0, 'deletes' => 0, 'lists' => 0, 'storage_bytes' => 3 * 1024 ** 3]]);

    // Asleep since Sep 2: Sep 1's operations were billed before it slept.
    EdgeKvUsage::query()->create(['organization_id' => $org->id, 'namespace_id' => $id, 'date' => '2026-09-01', 'reads' => 0, 'writes' => 1_000_000, 'deletes' => 0, 'lists' => 0, 'storage_bytes' => 3 * 1024 ** 3]);
    (new EdgeKvUsageCollector($client))->collectForDate(Carbon::parse('2026-09-02'));

    $cost = app(EdgeKvCost::class)->forOrganization($org, Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'));

    // Was: the collector skipped it and the cost dropped every row, so $0.
    expect(EdgeKvUsage::query()->where('date', '2026-09-02')->value('storage_bytes'))->toBe(3 * 1024 ** 3)
        ->and($cost['writes'])->toBe(1_000_000)
        // 1M writes $5 + the 3 GB peak $1.50 at cost, +20%. No free GB any more.
        ->and($cost['cents'])->toBe(780);
});

test('delivery usage is collected again in full the next day', function () {
    $schedule = new Schedule;
    DplySchedule::register($schedule);

    $yesterday = collect($schedule->events())->firstWhere('description', 'edge-usage-yesterday');

    expect($yesterday)->not->toBeNull()
        ->and($yesterday->command)->toContain('dply:edge:collect-usage')->not->toContain('--today')
        ->and($yesterday->expression)->toBe('30 1 * * *');
});
