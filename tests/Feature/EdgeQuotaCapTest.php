<?php

use App\Enums\QuotaSurface;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
 * The plan tier resolves from a BYO server count that is always 0 since the
 * Edge cut, so every org read as Free (3 Edge apps) even while paying. A paying
 * org must be uncapped; the Free ceiling is only the "no card to start" allowance.
 */

test('an org without a subscription is capped at the free allowance', function () {
    $org = Organization::factory()->create();

    expect($org->quotaLimit(QuotaSurface::Edge))->toBe(3)
        ->and($org->quotaLimitMessage(QuotaSurface::Edge))->toContain('without a card');
});

test('a paying org has no edge ceiling', function () {
    config(['subscription.standard.stripe.edge' => 'price_test_edge']);
    $org = Organization::factory()->create();

    DB::table('subscriptions')->insert([
        'id' => (string) Str::ulid(),
        'organization_id' => $org->id,
        'type' => 'default',
        'stripe_id' => 'sub_test_'.Str::random(16),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test_edge',
        'quantity' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($org->quotaLimit(QuotaSurface::Edge))->toBeNull()
        ->and($org->canCreateOnSurface(QuotaSurface::Edge))->toBeTrue()
        ->and($org->quotaLimitMessage(QuotaSurface::Edge))->toBe('');
});
