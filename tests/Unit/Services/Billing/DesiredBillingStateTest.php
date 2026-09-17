<?php

namespace Tests\Unit\Services\Billing\DesiredBillingStateTest;

use App\Modules\Billing\Services\DesiredBillingState;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Billing\Services\StandardSubscriptionCreator;

const FREE = ['key' => 'free', 'label' => 'Free', 'price_cents' => 0, 'max_servers' => 1];

const PRO = ['key' => 'pro', 'label' => 'Pro', 'price_cents' => 1900, 'max_servers' => 10];

test('free plan with no edge sites bills nothing', function () {
    $state = DesiredBillingState::fromPlanAndUsage(plan: FREE);

    expect($state->planKey)->toBe('free');
    expect($state->planPriceCents)->toBe(0);
    expect($state->monthlyTotalCents)->toBe(0);
    expect($state->isFree())->toBeTrue();
});

test('edge sites add a flat per site subtotal', function () {
    // Free + 3 sites × $2 = $6
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 3,
        edgeUnitCents: 200,
    );

    expect($state->edgeCount)->toBe(3);
    expect($state->edgeSsrCount)->toBe(0);
    expect($state->edgeSubtotalCents)->toBe(600);
    expect($state->monthlyTotalCents)->toBe(600);
});

test('edge ssr sites mix with static at the higher unit price', function () {
    // Free + 1 static ($2) + 1 SSR ($7) = $9
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 2,
        edgeUnitCents: 200,
        edgeSsrCount: 1,
        edgeSsrUnitCents: 700,
    );

    expect($state->edgeCount)->toBe(2);
    expect($state->edgeSsrCount)->toBe(1);
    expect($state->edgeBaseCount())->toBe(1);
    expect($state->edgeSubtotalCents)->toBe(900);
    expect($state->monthlyTotalCents)->toBe(900);
});

test('edge delivery usage adds on top and is not plan eligible', function () {
    // Pro ($19) + 1 edge ($2) + $5 usage = $26
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: PRO,
        edgeCount: 1,
        edgeUnitCents: 200,
        edgeUsageSubtotalCents: 500,
    );

    expect($state->edgeUsageSubtotalCents)->toBe(500);
    expect($state->monthlyTotalCents)->toBe(2600);
});

test('edge sites and usage combine in the total', function () {
    // Free + 2 static ($4) + 1 SSR ($7) + $3 usage = $14
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 3,
        edgeUnitCents: 200,
        edgeSsrCount: 1,
        edgeSsrUnitCents: 700,
        edgeUsageSubtotalCents: 300,
    );

    expect($state->edgeSubtotalCents)->toBe(1100);
    expect($state->managedSubtotalCents())->toBe(1100);
    expect($state->monthlyTotalCents)->toBe(1400);
    expect($state->isFree())->toBeFalse();
});

test('negative edge counts and usage are clamped', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: -1,
        edgeUnitCents: 200,
        edgeUsageSubtotalCents: -500,
    );

    expect($state->edgeCount)->toBe(0);
    expect($state->edgeSubtotalCents)->toBe(0);
    expect($state->edgeUsageSubtotalCents)->toBe(0);
    expect($state->monthlyTotalCents)->toBe(0);
});

test('ssr count never exceeds the edge count', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 1,
        edgeUnitCents: 200,
        edgeSsrCount: 5,
        edgeSsrUnitCents: 700,
    );

    expect($state->edgeSsrCount)->toBe(1);
    expect($state->edgeBaseCount())->toBe(0);
    expect($state->edgeSubtotalCents)->toBe(700);
});

test('to array round trips for queue payloads', function () {
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 2,
        edgeUnitCents: 200,
        edgeSsrCount: 1,
        edgeSsrUnitCents: 700,
    );

    $array = $state->toArray();

    expect($array['plan_key'])->toBe('free');
    expect($array['plan_price_cents'])->toBe(0);
    expect($array['edge_count'])->toBe(2);
    expect($array['edge_ssr_count'])->toBe(1);
    expect($array['edge_subtotal_cents'])->toBe(900);
    expect($array['monthly_total_cents'])->toBe(900);
    // Retired product lines no longer appear in the payload.
    expect($array)->not->toHaveKey('server_count');
    expect($array)->not->toHaveKey('realtime_tier_quantities');
});

test('load balancer endpoints bill per endpoint and land in the monthly total', function () {
    // 1 static site ($2) + 3 LB endpoints × $8 = $26
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: FREE,
        edgeCount: 1,
        edgeUnitCents: 200,
        edgeLbEndpointCount: 3,
        edgeLbEndpointUnitCents: 800,
    );

    expect($state->edgeLbEndpointCount)->toBe(3)
        ->and($state->edgeLbSubtotalCents)->toBe(2400)
        ->and($state->monthlyTotalCents)->toBe(2600)
        ->and($state->toArray()['edge_lb_endpoint_count'])->toBe(3);
});

test('tier state bills the fee, extra sites past the allowance, ssr, seats and build overage', function () {
    // Team: $49 + 52 static (2 extra × $2) + 1 SSR ($7) + 7 seats (2 extra × $5) + $3 build overage
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'team', 'label' => 'Team', 'price_cents' => 4900],
        edgeCount: 53,
        edgeUnitCents: 200,
        edgeSsrCount: 1,
        edgeSsrUnitCents: 700,
        includedSites: 50,
        seatCount: 7,
        includedSeats: 5,
        extraSeatUnitCents: 500,
        buildMinutes: 3600,
        buildMinuteOverageCents: 300,
    );

    expect($state->extraSiteCount)->toBe(2)
        ->and($state->edgeSubtotalCents)->toBe(1100)
        ->and($state->extraSeatCount)->toBe(2)
        ->and($state->usageLineCents())->toBe(300)
        ->and($state->monthlyTotalCents)->toBe(4900 + 1100 + 1000 + 300);
});

test('price list is the tier line plus every non-zero add-on line, monthly only', function () {
    config([
        'subscription.standard.stripe.tier_team' => 'price_team',
        'subscription.standard.stripe.edge' => 'price_edge',
        'subscription.standard.stripe.edge_ssr' => 'price_ssr',
        'subscription.standard.stripe.team_seat' => 'price_seat',
        'subscription.standard.stripe.edge_lb_endpoint' => 'price_lb',
        'subscription.standard.stripe.edge_usage' => 'price_usage',
    ]);
    $state = DesiredBillingState::fromPlanAndUsage(
        plan: ['key' => 'team', 'label' => 'Team', 'price_cents' => 4900],
        edgeCount: 51, edgeUnitCents: 200, includedSites: 50,
        edgeLbEndpointCount: 2, edgeLbEndpointUnitCents: 800,
        seatCount: 5, includedSeats: 5, extraSeatUnitCents: 500,
    );
    $creator = app(StandardSubscriptionCreator::class);

    expect($creator->buildPriceList($state))->toBe([
        ['price' => 'price_team', 'quantity' => 1],
        ['price' => 'price_edge', 'quantity' => 1],
        ['price' => 'price_lb', 'quantity' => 2],
    ])
        ->and(fn () => $creator->buildPriceList($state, 'year'))->toThrow(\InvalidArgumentException::class)
        ->and($creator->buildPriceList(DesiredBillingState::fromPlanAndUsage(plan: FREE, edgeCount: 1)))->toBe([]);
});

test('cheapest paid tier moves small fleets to pro and big or busy ones to team', function () {
    $pick = fn (int $sites, int $seats) => OrganizationBillingStateComputer::cheapestPaidTier($sites, $seats);

    expect($pick(3, 1))->toBe('pro')
        ->and($pick(24, 3))->toBe('pro')   // $20 + 14 × $2 = $48 < $49
        ->and($pick(25, 3))->toBe('team')  // $50 > $49
        ->and($pick(2, 4))->toBe('team');  // Pro seats are a hard cap
});
