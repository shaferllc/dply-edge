<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeAppDatabaseTest;

use App\Models\EdgePostgresUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\EdgeAppDatabaseCost;
use App\Modules\Edge\Services\EdgeAppDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function databaseSite(): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'slug' => 'bookstack',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'database' => ['engine' => 'sql', 'name' => 'production']]],
    ]);
}

test('an unpaid app is not given a database', function () {
    Http::fake();
    $site = databaseSite();

    $error = EdgeAppDatabase::sync($site, 'sql', 'postgres');

    expect($error)->toBe('Add a card before starting a database. It is billed to that card.');
    Http::assertNothingSent();
});

test('database usage is compute hours plus storage, rounded once', function () {
    config(['dply.edge.usage_billing.markup_percent' => 0]);
    $site = databaseSite();
    EdgePostgresUsage::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'project_id' => 'pg-1',
        'date' => now()->startOfMonth()->toDateString(),
        'compute_unit_seconds' => 3600,
        'storage_byte_hours' => 1024 ** 3 * now()->daysInMonth * 24,
    ]);

    $cost = app(EdgeAppDatabaseCost::class)->forOrganization($site->organization, now()->startOfMonth(), now()->endOfMonth());

    expect($cost['databases'])->toBe(1)
        ->and($cost['cents'])->toBe((int) round((config('dply.edge.usage_billing.postgres_compute_millicents_per_cu_hour') + config('dply.edge.usage_billing.postgres_storage_millicents_per_gb_month')) / 1000))
        ->and(app(EdgeAppDatabaseCost::class)->presentation()['gigabyte'])->toBe(number_format(config('dply.edge.usage_billing.postgres_storage_millicents_per_gb_month') / 100_000, 2));
});
