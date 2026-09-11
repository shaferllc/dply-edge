<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\OrganizationBillingSnapshotWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('snapshot writer persists daily organization billing snapshot', function () {
    $org = Organization::factory()->create();
    Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'created_at' => now()->subDays(5),
    ]);
    $edgeHost = Server::factory()->for($org)->create([
        'status' => Server::STATUS_READY,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
        'created_at' => now()->subDays(5),
    ]);
    Site::factory()->for($org)->for($edgeHost)->create([
        'status' => Site::STATUS_EDGE_ACTIVE,
        'edge_backend' => 'dply_edge',
        'created_at' => now()->subDays(5),
    ]);

    $snapshot = app(OrganizationBillingSnapshotWriter::class)->writeForOrganization($org, now()->startOfDay());

    expect($snapshot->monthly_total_cents)->toBeGreaterThan(0)
        ->and($snapshot->edge_usage_cents)->toBeInt()
        ->and($snapshot->category_breakdown)->toBeArray()
        // The fleet that bills is the Edge site count — nothing else.
        ->and($snapshot->fleet_counts)->toBe([
            'edge' => 1,
        ]);
});

test('snapshot writer upserts per organization and date', function () {
    $org = Organization::factory()->create();
    $date = now()->startOfDay();
    $writer = app(OrganizationBillingSnapshotWriter::class);

    $first = $writer->writeForOrganization($org, $date);
    $second = $writer->writeForOrganization($org, $date);

    expect($first->id)->toBe($second->id);
    expect($org->billingSnapshots()->count())->toBe(1);
});
