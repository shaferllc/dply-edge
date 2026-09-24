<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Services\StarterUsageBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a free org under the credit can still build', function () {
    $org = Organization::factory()->create();

    $status = app(StarterUsageBudget::class)->status($org);

    expect($status['exhausted'])->toBeFalse()
        ->and($status['limit_cents'])->toBe(500)
        ->and($status['used_cents'])->toBe(0)
        ->and(app(StarterUsageBudget::class)->alertKind($status))->toBeNull();
});

test('build minutes draw the free credit', function () {
    config(['subscription.standard.tiers.free.spending_limit_cents' => 1]);
    $org = Organization::factory()->create();
    $server = Server::factory()->for($org)->create();
    $site = Site::factory()->for($org)->for($server)->create();
    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'build_seconds' => 120,
    ]);

    $status = app(StarterUsageBudget::class)->status($org);

    expect($status['used_cents'])->toBe(2)
        ->and($status['exhausted'])->toBeTrue()
        ->and(app(StarterUsageBudget::class)->alertKind($status))->toBe('over');
});
