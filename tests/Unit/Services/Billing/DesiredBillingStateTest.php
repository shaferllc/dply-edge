<?php

namespace Tests\Unit\Services\Billing\DesiredBillingStateTest;

use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\StandardSubscriptionCreator;

const NONE = ['key' => 'none', 'label' => 'No plan', 'price_cents' => 0];

const PRO = ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 2000];

test('no plan and no usage bills nothing', function () {
    $state = DesiredBillingState::fromPlanAndUsage(plan: NONE);

    expect($state->planKey)->toBe('none')
        ->and($state->planPriceCents)->toBe(0)
        ->and($state->monthlyTotalCents)->toBe(0)
        ->and($state->isFree())->toBeTrue();
});

test('sites carry no fee: only the plan and usage past the credit bill', function () {
    $state = DesiredBillingState::fromPlanAndUsage(plan: PRO, edgeCount: 40, usage: ['delivery' => 2500], usageCreditCents: 2000);

    expect($state->edgeCount)->toBe(40)
        ->and($state->usageLineCents())->toBe(2500)
        ->and($state->creditAppliedCents())->toBe(2000)
        ->and($state->monthlyTotalCents)->toBe(2000 + 500)
        ->and($state->managedSubtotalCents())->toBe(2000);
});

test('usage lines keep their invoice order and drop zero or negative lines', function () {
    $state = DesiredBillingState::fromPlanAndUsage(plan: PRO, edgeCount: -1, usage: ['platform' => 5, 'compute' => 10, 'builds' => 0, 'data' => -3, 'bogus' => 99]);

    expect($state->usageLines())->toBe(['compute' => 10, 'platform' => 5])
        ->and($state->edgeCount)->toBe(0)
        ->and($state->monthlyTotalCents)->toBe(2015);
});

test('to array round trips for queue payloads', function () {
    $array = DesiredBillingState::fromPlanAndUsage(plan: PRO, edgeCount: 2, usage: ['valkey' => 300], usageCreditCents: 2000)->toArray();

    expect($array['plan_key'])->toBe('pro')
        ->and($array['edge_count'])->toBe(2)
        ->and($array['usage'])->toBe(['valkey' => 300])
        ->and($array['credit_applied_cents'])->toBe(300)
        ->and($array['monthly_total_cents'])->toBe(2000)
        ->and($array)->not->toHaveKey('edge_subtotal_cents')
        ->and($array)->not->toHaveKey('edge_ssr_count');
});

test('team bills extra seats; the price list is the plan line plus seats, monthly only', function () {
    config([
        'subscription.standard.stripe.tier_team' => 'price_team',
        'subscription.standard.stripe.team_seat' => 'price_seat',
        'subscription.standard.stripe.edge' => 'price_edge',
        'subscription.standard.stripe.edge_ssr' => 'price_ssr',
    ]);
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'team', 'label' => 'Team', 'price_cents' => 4900],
        edgeCount: 60,
        seatCount: 12, includedSeats: 10, extraSeatUnitCents: 500,
        usage: ['compute' => 100], usageCreditCents: 5000,
    );
    $creator = app(StandardSubscriptionCreator::class);

    expect($state->extraSeatCount)->toBe(2)
        ->and($state->monthlyTotalCents)->toBe(4900 + 1000)
        ->and($creator->buildPriceList($state))->toBe([
            ['price' => 'price_team', 'quantity' => 1],
            ['price' => 'price_seat', 'quantity' => 2],
        ])
        ->and(fn () => $creator->buildPriceList($state, 'year'))->toThrow(\InvalidArgumentException::class)
        ->and($creator->buildPriceList(DesiredBillingState::fromPlanAndUsage(plan: NONE, edgeCount: 1)))->toBe([]);
});

test('a pre-tier subscription moves to pro, or team when pro’s seat cap is too small', function () {
    $pick = fn (int $seats) => OrganizationBillingStateComputer::cheapestPaidTier($seats);

    expect($pick(1))->toBe('pro')
        ->and($pick(3))->toBe('pro')
        ->and($pick(4))->toBe('team');
});
