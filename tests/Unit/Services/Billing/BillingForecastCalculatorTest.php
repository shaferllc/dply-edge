<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\OrganizationBillingSnapshot;
use App\Modules\Billing\Services\BillingForecastCalculator;
use App\Modules\Billing\Services\DesiredBillingState;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('forecast shows usage, the credit it uses and the estimated charge, and projects month end', function () {
    // $15 plan, $4.50 of usage against a $2 credit → $17.50 estimated.
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'custom', 'label' => 'Custom', 'price_cents' => 1500],
        usage: ['delivery' => 450],
        usageCreditCents: 200,
    );

    $forecast = app(BillingForecastCalculator::class)->calculate(
        state: $state,
        subscriptionInterval: 'month',
        snapshotThirtyDaysAgo: new OrganizationBillingSnapshot(['monthly_total_cents' => 1500]),
        asOf: now()->setDate(2026, 5, 10),
    );

    expect($forecast['usage_cents'])->toBe(450)
        ->and($forecast['credit_cents'])->toBe(200)
        ->and($forecast['estimated_charge_cents'])->toBe(1750)
        ->and($forecast['mrr_cents'])->toBe(1750)
        ->and($forecast['fixed_cents'])->toBe(1500)
        ->and($forecast['projected_edge_usage_cents'])->toBeGreaterThan(450)
        ->and($forecast['projected_month_end_cents'])->toBe(1500 + $forecast['projected_edge_usage_cents'] - 200)
        ->and($forecast['delta_vs_thirty_days_cents'])->toBe(250);
});

test('forecast keeps monthly mrr and null delta without baseline snapshot', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'custom', 'label' => 'Custom', 'price_cents' => 2000, 'max_servers' => null],
    );

    $forecast = app(BillingForecastCalculator::class)->calculate($state, 'month', null, now()->setDate(2026, 5, 20));

    expect($forecast['mrr_cents'])->toBe(2000)
        ->and($forecast['arr_cents'])->toBe(24_000)
        ->and($forecast['delta_vs_thirty_days_cents'])->toBeNull();
});
