<?php

namespace Tests\Unit\SitePolicyTest;

use App\Enums\SiteType;
use App\Models\EdgeSiteMember;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Policies\SitePolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->policy = new SitePolicy;
});

test('create denies without current organization', function () {
    $user = User::factory()->create();
    expect($user->currentOrganization())->toBeNull();

    expect($this->policy->create($user))->toBeFalse();
});

test('create allows when org under limit', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    expect($this->policy->create($user))->toBeTrue();
});

test('create denies deployer', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'deployer']);
    session(['current_organization_id' => $org->id]);

    expect($this->policy->create($user))->toBeFalse();
});

test('edge site admin can manage members; deployer cannot', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($owner->id, ['role' => 'owner']);
    $org->users()->attach($member->id, ['role' => 'member']);

    $server = Server::factory()->create([
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $owner->id,
        'organization_id' => $org->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web'],
    ]);

    EdgeSiteMember::query()->create([
        'site_id' => $site->id,
        'user_id' => $member->id,
        'role' => EdgeSiteMember::ROLE_DEPLOYER,
        'invited_by_user_id' => $owner->id,
    ]);

    expect($this->policy->manageMembers($member, $site->fresh()))->toBeFalse();

    $site->edgeSiteMembers()->where('user_id', $member->id)->firstOrFail()->update([
        'role' => EdgeSiteMember::ROLE_ADMIN,
    ]);

    expect($this->policy->manageMembers($member, $site->fresh()))->toBeTrue();
});
