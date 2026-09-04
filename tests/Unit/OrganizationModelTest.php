<?php

namespace Tests\Unit\OrganizationModelTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('has member returns true for attached user', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    expect($org->hasMember($user))->toBeTrue();
});

test('has member returns false for non attached user', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    expect($org->hasMember($user))->toBeFalse();
});

test('has admin access returns true for owner', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    expect($org->hasAdminAccess($user))->toBeTrue();
});

test('wants deploy email notifications defaults true', function () {
    $org = Organization::factory()->create();

    expect($org->fresh()->wantsDeployEmailNotifications())->toBeTrue();
});

test('wants deploy email notifications respects disabled column', function () {
    $org = Organization::factory()->create(['deploy_email_notifications_enabled' => false]);

    expect($org->wantsDeployEmailNotifications())->toBeFalse();
});

test('has admin access returns true for admin', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'admin']);

    expect($org->hasAdminAccess($user))->toBeTrue();
});

test('has admin access returns false for member', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    expect($org->hasAdminAccess($user))->toBeFalse();
});

test('user is deployer', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);

    expect($org->userIsDeployer($user))->toBeTrue();
    expect($org->hasMember($user))->toBeTrue();
    expect($org->hasAdminAccess($user))->toBeFalse();
});

test('plan tier label defaults to trial without a pro subscription', function () {
    $org = new Organization;

    expect($org->planTierLabel())->toBe('Trial');
});

test('plan tier label returns standard when org is on standard', function () {
    $org = new class extends Organization
    {
        public function onStandardSubscription(): bool
        {
            return true;
        }
    };

    expect($org->planTierLabel())->toBe('Standard');
});

test('plan tier label returns enterprise when org is on enterprise', function () {
    $org = new class extends Organization
    {
        public function onEnterpriseSubscription(): bool
        {
            return true;
        }
    };

    expect($org->planTierLabel())->toBe('Enterprise');
});

test('enterprise takes precedence over standard', function () {
    $org = new class extends Organization
    {
        public function onEnterpriseSubscription(): bool
        {
            return true;
        }

        public function onStandardSubscription(): bool
        {
            return true;
        }
    };

    expect($org->planTierLabel())->toBe('Enterprise');
});

test('on any paid plan is true for each paid plan', function () {
    $trialOrg = new Organization;
    expect($trialOrg->onAnyPaidPlan())->toBeFalse();

    $standardOrg = new class extends Organization
    {
        public function onStandardSubscription(): bool
        {
            return true;
        }
    };
    expect($standardOrg->onAnyPaidPlan())->toBeTrue();

    $enterpriseOrg = new class extends Organization
    {
        public function onEnterpriseSubscription(): bool
        {
            return true;
        }
    };
    expect($enterpriseOrg->onAnyPaidPlan())->toBeTrue();
});

test('max servers is unlimited on any paid plan', function () {
    $standardOrg = new class extends Organization
    {
        public function onStandardSubscription(): bool
        {
            return true;
        }
    };

    expect($standardOrg->maxServers())->toBe(PHP_INT_MAX);
    expect($standardOrg->maxServersDisplay())->toBe('Unlimited');
});

test('max servers is unlimited for all orgs', function () {
    // Server count is uncapped — trial-state gating (canDeploy / acceptsMetrics)
    // does the abuse-protection work; the plan is just metered by count.
    $trialOrg = new Organization;

    expect($trialOrg->maxServers())->toBe(PHP_INT_MAX);
    expect($trialOrg->maxServersDisplay())->toBe('Unlimited');
});



test('unlimited plans never block site creation', function () {
    config(['subscription.standard.plans' => [
        'business' => ['label' => 'Business', 'price_cents' => 3900, 'max_servers' => null, 'max_sites' => null],
    ]]);

    $org = Organization::factory()->create();

    expect($org->planSiteLimit())->toBeNull();
    expect($org->maxSites())->toBe(PHP_INT_MAX);
    expect($org->maxSitesDisplay())->toBe('Unlimited');
    expect($org->canCreateSite())->toBeTrue();
    expect($org->siteLimitMessage())->toBe('');
});

test('org creation starts a 14 day trial', function () {
    config(['subscription.standard.trial_days' => 14]);
    $org = Organization::factory()->create();

    expect($org->trial_ends_at)->not->toBeNull();
    expect($org->trial_ends_at->isFuture())->toBeTrue();
    expect(now()->diffInSeconds($org->trial_ends_at, false))->toEqualWithDelta(14 * 86400, 5);
});

test('org creation respects explicitly set trial', function () {
    $explicit = now()->addDays(30);
    $org = Organization::factory()->create(['trial_ends_at' => $explicit]);

    expect($org->trial_ends_at->timestamp)->toEqualWithDelta($explicit->timestamp, 2);
});

test('on dply trial is true while trial is future', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->addDay()]);

    expect($org->onDplyTrial())->toBeTrue();
});

test('on dply trial is false after trial expires', function () {
    $org = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);

    expect($org->onDplyTrial())->toBeFalse();
});
