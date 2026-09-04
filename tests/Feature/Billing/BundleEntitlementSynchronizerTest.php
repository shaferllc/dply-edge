<?php

declare(strict_types=1);

use App\Enums\BundleTransition;
use App\Models\Organization;
use App\Models\OrganizationBundleEntitlement;
use App\Modules\Billing\Events\BundleEntitlementChanged;
use App\Modules\Billing\Services\BundleEntitlementSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

// Without this the entitlement rows written by the earlier tests in this file
// survive into the next one, and the "inert" assertion counts them.
uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('bundle.enabled', true);
    Event::fake([BundleEntitlementChanged::class]);
});

/** A real org row wrapped so its entitlement predicate is controllable. */
function orgQualifying(Organization $org, bool $qualifies): Organization
{
    $mock = Mockery::mock($org)->makePartial();
    $mock->shouldReceive('qualifiesForBundledProducts')->andReturn($qualifies);

    return $mock;
}

it('is inert while the perk is dark', function (): void {
    config()->set('bundle.enabled', false);
    $org = Organization::factory()->create();

    expect(app(BundleEntitlementSynchronizer::class)->sync(orgQualifying($org, true)))->toBeNull();
    // Scoped to this org: a global count picks up rows any other test in the
    // run left behind and makes this assertion order-dependent.
    expect(OrganizationBundleEntitlement::query()->where('organization_id', $org->id)->count())->toBe(0);
    Event::assertNotDispatched(BundleEntitlementChanged::class);
});

it('walks provision → idempotent → suspend → resume', function (): void {
    $sync = app(BundleEntitlementSynchronizer::class);
    $org = Organization::factory()->create();

    expect($sync->sync(orgQualifying($org, true)))->toBe(BundleTransition::Provisioned);
    expect($sync->sync(orgQualifying($org, true)))->toBeNull(); // already active — no-op

    expect(OrganizationBundleEntitlement::where('organization_id', $org->id)->value('status'))
        ->toBe(OrganizationBundleEntitlement::STATUS_ACTIVE);

    expect($sync->sync(orgQualifying($org, false)))->toBe(BundleTransition::Suspended);
    expect($sync->sync(orgQualifying($org, false)))->toBeNull(); // already suspended — no-op
    expect($sync->sync(orgQualifying($org, true)))->toBe(BundleTransition::Resumed);

    Event::assertDispatchedTimes(BundleEntitlementChanged::class, 3);
});

it('purges only after the retention window', function (): void {
    config()->set('bundle.retention_days', 75);
    $sync = app(BundleEntitlementSynchronizer::class);
    $org = Organization::factory()->create();

    OrganizationBundleEntitlement::create([
        'organization_id' => $org->id,
        'status' => OrganizationBundleEntitlement::STATUS_SUSPENDED,
        'suspended_at' => now()->subDays(10),
    ]);
    expect($sync->purgeExpired($org))->toBeFalse(); // inside window

    OrganizationBundleEntitlement::where('organization_id', $org->id)->update(['suspended_at' => now()->subDays(90)]);
    expect($sync->purgeExpired($org))->toBeTrue(); // past window
    expect(OrganizationBundleEntitlement::where('organization_id', $org->id)->value('status'))
        ->toBe(OrganizationBundleEntitlement::STATUS_DELETED);
});
