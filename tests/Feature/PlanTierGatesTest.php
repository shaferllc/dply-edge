<?php

declare(strict_types=1);

namespace Tests\Feature\PlanTierGatesTest;

use App\Enums\SiteType;
use App\Livewire\Organizations\Activity;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Tier allowances enforced where each thing happens (ruling r-zdescb7y05vp1bxx). */
beforeEach(function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
        'dply.max_organization_members' => null,
        'subscription.standard.stripe.tier_pro' => 'price_tier_pro',
        'subscription.standard.stripe.tier_team' => 'price_tier_team',
    ]);
    $this->org = Organization::factory()->create();
});

function onTier(Organization $org, string $tier): Organization
{
    Subscription::factory()->withPrice('price_tier_'.$tier)->active()->create(['organization_id' => $org->id]);

    return $org->fresh();
}

function liveSite(Organization $org): Site
{
    $server = Server::factory()->create(['organization_id' => $org->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);

    return Site::factory()->create([
        'server_id' => $server->id, 'organization_id' => $org->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['routing' => ['hostname' => 'gate.dply.host']]],
    ]);
}

test('the tier is read from the subscription price', function () {
    expect($this->org->billingTier())->toBe('free')
        ->and(onTier($this->org, 'team')->billingTier())->toBe('team');
});

test('seats hard-cap on pro and bill past the allowance on team; free is unlimited', function () {
    expect($this->org->effectiveMemberSeatCap())->toBeNull()
        ->and(onTier(Organization::factory()->create(), 'pro')->effectiveMemberSeatCap())->toBe(3)
        ->and(onTier(Organization::factory()->create(), 'team')->effectiveMemberSeatCap())->toBeNull();
});

test('free sites get ten custom domains, pro sites get more', function () {
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    $free = liveSite($this->org);
    $provisioner->provision($free, 'one.example.com');
    $provisioner->provision($free->fresh(), 'one.example.com'); // re-provisioning is not a new domain
    $provisioner->provision($free->fresh(), 'two.example.com');

    config(['subscription.standard.tiers.free.custom_domains_per_site' => 1]);
    $capped = liveSite(Organization::factory()->create());
    $provisioner->provision($capped, 'one.example.com');
    expect(fn () => $provisioner->provision($capped->fresh(), 'two.example.com'))
        ->toThrow(\RuntimeException::class, 'Upgrade to Pro');

    $pro = liveSite(onTier(Organization::factory()->create(), 'pro'));
    $provisioner->provision($pro, 'one.example.com');
    $provisioner->provision($pro->fresh(), 'two.example.com');

    expect($pro->fresh()->edgeMeta()['routing']['custom_domains'])->toHaveCount(2);
});

test('the audit log page is locked below team', function () {
    $admin = User::factory()->create();
    $this->org->users()->attach($admin->id, ['role' => 'owner']);

    Livewire::actingAs($admin)->test(Activity::class, ['organization' => $this->org])
        ->assertSee('The audit log is on the Team plan');

    $team = onTier($this->org, 'team');
    Livewire::actingAs($admin)->test(Activity::class, ['organization' => $team])
        ->assertDontSee('The audit log is on the Team plan');
});
