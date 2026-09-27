<?php

declare(strict_types=1);

namespace Tests\Feature\PlanTierGatesTest;

use App\Enums\SiteType;
use App\Livewire\Organizations\Activity;
use App\Livewire\Organizations\Members;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use App\Modules\Edge\Services\EdgeCustomDomainProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** Tier allowances enforced where each thing happens (rulings r-zdescb7y05vp1bxx, r-f17p5zgeh120cm5t: no Free plan). */
beforeEach(function () {
    config([
        'edge.fake.enabled' => true,
        'edge.custom_hostnames.enabled' => true,
        'dply.max_organization_members' => null,
        'subscription.standard.stripe.tier_pro' => 'price_tier_pro',
        'subscription.standard.stripe.tier_team' => 'price_tier_team',
    ]);
    $this->org = Organization::factory()->noPlan()->create();
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
    expect($this->org->billingTier())->toBe('none')
        ->and(onTier($this->org, 'team')->billingTier())->toBe('team');
});

test('seats hard-cap on pro and bill past the allowance on team; free is unlimited', function () {
    expect($this->org->effectiveMemberSeatCap())->toBeNull()
        ->and(onTier(Organization::factory()->create(), 'pro')->effectiveMemberSeatCap())->toBe(3)
        ->and(onTier(Organization::factory()->create(), 'team')->effectiveMemberSeatCap())->toBeNull();
});

test('view-only members and their invitations are free: not billed, not against the seat cap', function () {
    $org = onTier(Organization::factory()->create(), 'pro'); // 3 seats, hard cap
    $owner = User::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach(User::factory()->create()->id, ['role' => Organization::VIEW_ONLY_ROLE]);
    $org->users()->attach(User::factory()->create()->id, ['role' => Organization::VIEW_ONLY_ROLE]);
    OrganizationInvitation::createFor($org, 'viewer@example.com', Organization::VIEW_ONLY_ROLE, $owner);
    OrganizationInvitation::createFor($org, 'member@example.com', 'member', $owner);
    $org = $org->fresh();

    expect($org->seatCount())->toBe(1)
        ->and($org->seatsWithPendingInvites())->toBe(2)
        ->and(app(OrganizationBillingStateComputer::class)->computeForTier($org, 'pro')->seatCount)->toBe(1);

    // Two seats used of three: one more member fits, the next does not.
    $this->actingAs($owner);
    Livewire::test(Members::class, ['organization' => $org])
        ->set('invite_email', 'second@example.com')->set('invite_role', 'member')->call('inviteMember')
        ->assertHasNoErrors();
    Livewire::test(Members::class, ['organization' => $org->fresh()])
        ->set('invite_email', 'third@example.com')->set('invite_role', 'member')->call('inviteMember')
        ->assertHasErrors('invite_email');
});

test('custom domains follow the plan, and an org without one gets none', function () {
    $provisioner = app(EdgeCustomDomainProvisioner::class);

    expect(fn () => $provisioner->provision(liveSite($this->org), 'one.example.com'))
        ->toThrow(\RuntimeException::class, 'no plan');

    $trial = liveSite(Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]));
    $provisioner->provision($trial, 'one.example.com');
    $provisioner->provision($trial->fresh(), 'one.example.com'); // re-provisioning is not a new domain
    $provisioner->provision($trial->fresh(), 'two.example.com');

    // One cap only: org-wide, across every app.
    config(['subscription.standard.tiers.pro.custom_domains' => 3]);
    $second = liveSite($trial->organization);
    $provisioner->provision($second, 'three.example.com');
    expect(fn () => $provisioner->provision($second->fresh(), 'four.example.com'))
        ->toThrow(\RuntimeException::class, '3 custom domains across the organization');

    config(['subscription.standard.tiers.pro.custom_domains' => 20]);
    $pro = liveSite(onTier(Organization::factory()->create(), 'pro'));
    $provisioner->provision($pro, 'one.pro.example.com');
    $provisioner->provision($pro->fresh(), 'two.pro.example.com');
    expect(config('subscription.standard.tiers.pro'))->not->toHaveKey('custom_domains_per_site');

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
