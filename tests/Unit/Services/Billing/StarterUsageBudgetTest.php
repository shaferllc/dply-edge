<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\StarterUsageBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a trial under its credit can still build', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);

    $status = app(StarterUsageBudget::class)->status($org);

    expect($status['exhausted'])->toBeFalse()
        ->and($status['limit_cents'])->toBe(500)
        ->and($status['used_cents'])->toBe(0)
        ->and(app(StarterUsageBudget::class)->alertKind($status))->toBeNull();
});

test('build time draws the trial cap at customer price, by the second', function () {
    config(['subscription.standard.trial.spending_limit_cents' => 1, 'dply.edge.usage_billing.margin_percent' => 20]);
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
    $server = Server::factory()->for($org)->create();
    $site = Site::factory()->for($org)->for($server)->create();
    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'build_seconds' => 590,
    ]);

    $status = app(StarterUsageBudget::class)->status($org);

    // 590 s at $0.005/min cost = 4.92¢, +20% = 5.9¢ → 6¢ (no credit on a trial)
    expect($status['used_cents'])->toBe(6)
        ->and($status['exhausted'])->toBeTrue()
        ->and(app(StarterUsageBudget::class)->alertKind($status))->toBe('over');
});

test('a paid org is never capped', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    $org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $org->id]);

    expect(app(StarterUsageBudget::class)->status($org->fresh())['limit_cents'])->toBeNull();
});
