<?php

namespace Tests\Feature\Livewire\Billing\UsageByAppTest;

use App\Models\EdgeDeployment;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Livewire\Show as BillingShow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('app rows plus the not-per-app row add up to the header usage number', function () {
    config(['dply.edge.usage_billing.enabled' => true]);
    $admin = User::factory()->create();
    $org = Organization::factory()->newSignup()->create();
    $org->users()->attach($admin->id, ['role' => 'admin']);
    $server = Server::factory()->for($org)->create(['status' => Server::STATUS_READY]);

    foreach (['alpha' => 9_000_000, 'beta' => 2_000_000] as $name => $requests) {
        $site = Site::factory()->for($org)->for($server)->create([
            'name' => $name,
            'status' => Site::STATUS_EDGE_ACTIVE,
            'edge_backend' => 'dply_edge',
        ]);
        EdgeUsageSnapshot::query()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'period_start' => now()->toDateString(),
            'period_end' => now()->toDateString(),
            'requests' => $requests,
            'bytes_egress' => 40 * 1024 ** 3,
            'r2_storage_bytes' => 0,
            'r2_class_a_ops' => 0,
            'r2_class_b_ops' => 0,
            'source' => 'manual',
        ]);
        EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $org->id,
            'status' => EdgeDeployment::STATUS_FAILED,
            'storage_prefix' => 'edge/test/'.$name,
            'build_seconds' => 1800,
        ]);
    }

    $component = Livewire::actingAs($admin)->test(BillingShow::class, ['organization' => $org]);
    $usage = $component->instance()->usageByApp;
    $header = $component->instance()->usageState->usageLineCents();

    expect($header)->toBeGreaterThan(0)
        ->and(array_column($usage['apps'], 'name'))->toBe(['alpha', 'beta'])
        ->and(array_sum(array_column($usage['apps'], 'total')) + $usage['other']['total'])->toBe($header)
        ->and($usage['total_cents'])->toBe($header)
        ->and(collect($usage['daily'])->sum(fn (array $d): float => $d['builds']))->toBeGreaterThan(0.0)
        ->and(collect($usage['daily'])->sum(fn (array $d): float => $d['delivery']))->toBeGreaterThan(0.0)
        ->and(collect($usage['daily'])->pluck('date')->every(fn (string $d): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)))->toBeTrue();
    $component->assertSee('alpha')->assertSee('All usage this period');
});
