<?php

declare(strict_types=1);

namespace Tests\Feature\Organizations\OrganizationMemberManagementTest;

use App\Actions\Organizations\ManageOrganizationMembers;
use App\Enums\SiteType;
use App\Livewire\Organizations\Members;
use App\Livewire\Profile\DeleteAccount;
use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Models\EdgeSiteMember;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use App\Notifications\OrganizationMembershipNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Notification::fake();
    Organization::flushMemberRoleCache();

    $this->org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $this->owner = User::factory()->create();
    $this->org->users()->attach($this->owner->id, ['role' => 'owner']);
    $this->manage = app(ManageOrganizationMembers::class);
});

function addMember(Organization $org, string $role): User
{
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);
    Organization::flushMemberRoleCache();

    return $user;
}

function roleOf(Organization $org, User $user): ?string
{
    Organization::flushMemberRoleCache();

    return $org->fresh()->memberRole($user);
}

// actor role => target role => new role => allowed
dataset('role changes', [
    'owner demotes admin' => ['owner', 'admin', 'member', true],
    'owner promotes viewer to admin' => ['owner', 'viewer', 'admin', true],
    'owner demotes another owner' => ['owner', 'owner', 'admin', true],
    'admin changes member' => ['admin', 'member', 'deployer', true],
    'admin promotes to admin' => ['admin', 'viewer', 'admin', true],
    'admin grants owner' => ['admin', 'member', 'owner', false],
    'admin demotes owner' => ['admin', 'owner', 'admin', false],
    'member changes deployer' => ['member', 'deployer', 'viewer', false],
    'deployer changes member' => ['deployer', 'member', 'viewer', false],
    'viewer changes member' => ['viewer', 'member', 'viewer', false],
]);

test('who can change whose role', function (string $actorRole, string $targetRole, string $newRole, bool $allowed) {
    $actor = $actorRole === 'owner' ? $this->owner : addMember($this->org, $actorRole);
    $target = addMember($this->org, $targetRole);

    try {
        $this->manage->changeRole($this->org, $actor, $target, $newRole);
        expect($allowed)->toBeTrue();
        expect(roleOf($this->org, $target))->toBe($newRole);
    } catch (AuthorizationException) {
        expect($allowed)->toBeFalse();
        expect(roleOf($this->org, $target))->toBe($targetRole);
    }
})->with('role changes');

// actor role => target role => allowed
dataset('removals', [
    'owner removes admin' => ['owner', 'admin', true],
    'owner removes another owner' => ['owner', 'owner', true],
    'admin removes member' => ['admin', 'member', true],
    'admin removes admin' => ['admin', 'admin', true],
    'admin removes owner' => ['admin', 'owner', false],
    'member removes viewer' => ['member', 'viewer', false],
    'deployer removes member' => ['deployer', 'member', false],
]);

test('who can remove whom', function (string $actorRole, string $targetRole, bool $allowed) {
    $actor = $actorRole === 'owner' ? $this->owner : addMember($this->org, $actorRole);
    $target = addMember($this->org, $targetRole);

    try {
        $this->manage->remove($this->org, $actor, $target);
        expect($allowed)->toBeTrue();
        expect(roleOf($this->org, $target))->toBeNull();
    } catch (AuthorizationException) {
        expect($allowed)->toBeFalse();
        expect(roleOf($this->org, $target))->toBe($targetRole);
    }
})->with('removals');

test('the last owner cannot be demoted, removed, or leave', function () {
    expect(fn () => $this->manage->changeRole($this->org, $this->owner, $this->owner, 'admin'))
        ->toThrow(ValidationException::class);
    expect(fn () => $this->manage->remove($this->org, $this->owner, $this->owner))
        ->toThrow(ValidationException::class);
    expect(roleOf($this->org, $this->owner))->toBe('owner');
});

test('a member can leave and it is audited as leaving', function () {
    $member = addMember($this->org, 'deployer');

    $this->manage->remove($this->org, $member, $member);

    expect(roleOf($this->org, $member))->toBeNull();
    expect(AuditLog::where('action', 'member.left')->where('user_id', $member->id)->exists())->toBeTrue();
    Notification::assertNothingSentTo($member);
});

test('leaving through the Members page clears the current organization', function () {
    $member = addMember($this->org, 'member');

    session(['current_organization_id' => $this->org->id]);
    Livewire::actingAs($member)
        ->test(Members::class, ['organization' => $this->org])
        ->call('leave')
        ->assertRedirect(route('dashboard'));

    expect(session('current_organization_id'))->toBeNull();
    expect(roleOf($this->org, $member))->toBeNull();
});

test('removal revokes tokens, drops app and team places, recounts seats, audits and notifies', function () {
    $member = addMember($this->org, 'member');
    $server = Server::factory()->create(['user_id' => $this->owner->id, 'organization_id' => $this->org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id, 'user_id' => $this->owner->id,
        'organization_id' => $this->org->id, 'type' => SiteType::Static,
    ]);
    EdgeSiteMember::create(['site_id' => $site->id, 'user_id' => $member->id, 'role' => 'admin']);
    $team = $this->org->createDefaultTeamIfMissing();
    $team->users()->attach($member->id, ['role' => 'member']);
    $token = ApiToken::createToken($member, $this->org, 'ci')['token'];
    $otherOrg = Organization::factory()->create();
    $otherOrg->users()->attach($member->id, ['role' => 'member']);
    $otherToken = ApiToken::createToken($member, $otherOrg, 'elsewhere')['token'];

    $this->manage->remove($this->org, $this->owner, $member);

    expect($token->fresh()->revoked_at)->not->toBeNull()
        ->and($token->fresh()->isValid())->toBeFalse()
        ->and($otherToken->fresh()->revoked_at)->toBeNull()
        ->and(EdgeSiteMember::where('user_id', $member->id)->exists())->toBeFalse()
        ->and($team->users()->where('user_id', $member->id)->exists())->toBeFalse()
        ->and($this->org->fresh()->seatCount())->toBe(1);

    Queue::assertPushed(SyncOrganizationBillingJob::class, fn ($job) => $job->organizationId === $this->org->id);
    expect(AuditLog::where('action', 'member.removed')->exists())->toBeTrue()
        ->and(AuditLog::where('action', 'api_token.revoked')->exists())->toBeTrue();
    Notification::assertSentTo($member, OrganizationMembershipNotice::class, fn ($n) => $n->kind === 'removed');

    // A revoked token stays dead after a re-invite.
    $this->org->users()->attach($member->id, ['role' => 'member']);
    expect($token->fresh()->isValid())->toBeFalse();
});

test('a role change is audited and emailed; viewer demotion drops app roles and recounts seats', function () {
    $member = addMember($this->org, 'member');
    $site = Site::factory()->create([
        'server_id' => Server::factory()->create(['organization_id' => $this->org->id])->id,
        'organization_id' => $this->org->id, 'type' => SiteType::Static,
    ]);
    EdgeSiteMember::create(['site_id' => $site->id, 'user_id' => $member->id, 'role' => 'deployer']);

    $this->manage->changeRole($this->org, $this->owner, $member, Organization::VIEW_ONLY_ROLE);

    expect(EdgeSiteMember::where('user_id', $member->id)->exists())->toBeFalse()
        ->and($this->org->fresh()->seatCount())->toBe(1);
    Queue::assertPushed(SyncOrganizationBillingJob::class);
    $log = AuditLog::where('action', 'member.role_changed')->first();
    expect($log->old_values['role'])->toBe('member')->and($log->new_values['role'])->toBe('viewer');
    Notification::assertSentTo($member, OrganizationMembershipNotice::class, fn ($n) => $n->role === 'viewer');
});

test('promoting a viewer respects the seat cap', function () {
    config(['dply.max_organization_members' => 1]);
    $viewer = addMember($this->org, Organization::VIEW_ONLY_ROLE);

    expect(fn () => $this->manage->changeRole($this->org, $this->owner, $viewer, 'member'))
        ->toThrow(ValidationException::class);
    expect(roleOf($this->org, $viewer))->toBe('viewer');
});

test('an owner can transfer ownership and step down', function () {
    $admin = addMember($this->org, 'admin');

    $this->manage->transferOwnership($this->org, $this->owner, $admin, stepDown: true);

    expect(roleOf($this->org, $admin))->toBe('owner')
        ->and(roleOf($this->org, $this->owner))->toBe('admin')
        ->and(AuditLog::where('action', 'organization.ownership_transferred')->exists())->toBeTrue();
});

test('only an owner can transfer ownership', function () {
    $admin = addMember($this->org, 'admin');
    $member = addMember($this->org, 'member');

    expect(fn () => $this->manage->transferOwnership($this->org, $admin, $member))
        ->toThrow(AuthorizationException::class);
});

test('Members page: downgrade asks first, upgrade applies, non-admins are read-only', function () {
    $member = addMember($this->org, 'member');

    Livewire::actingAs($this->owner)
        ->test(Members::class, ['organization' => $this->org])
        ->call('promptChangeRole', (string) $member->id, 'viewer')
        ->assertSet('showConfirmActionModal', true)
        ->assertSet('confirmActionModalMethod', 'changeRole');
    expect(roleOf($this->org, $member))->toBe('member');

    Livewire::actingAs($this->owner)
        ->test(Members::class, ['organization' => $this->org])
        ->call('promptChangeRole', (string) $member->id, 'admin')
        ->assertSet('showConfirmActionModal', false);
    expect(roleOf($this->org, $member))->toBe('admin');

    $deployer = addMember($this->org, 'deployer');
    Livewire::actingAs($deployer)
        ->test(Members::class, ['organization' => $this->org])
        ->assertDontSeeHtml('promptRemoveMember')
        ->assertSeeHtml('promptLeave')
        ->call('removeMember', (string) $member->id)
        ->assertForbidden();
});

test('account deletion refuses for the only owner of an org with other members', function () {
    addMember($this->org, 'member');

    Livewire::actingAs($this->owner)
        ->test(DeleteAccount::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_password']);

    expect($this->owner->fresh())->not->toBeNull();
});

test('account deletion deletes an org the user is alone in', function () {
    $this->org->forceFill(['comped_until' => null])->save();

    Livewire::actingAs($this->owner)
        ->test(DeleteAccount::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount')
        ->assertHasNoErrors()->assertRedirect('/');

    expect($this->owner->fresh())->toBeNull()
        ->and(Organization::find($this->org->id))->toBeNull();
});

test('account deletion refuses when a solo org still has apps', function () {
    Server::factory()->create(['organization_id' => $this->org->id]);

    Livewire::actingAs($this->owner)
        ->test(DeleteAccount::class)
        ->set('delete_password', 'password')
        ->call('deleteAccount')
        ->assertHasErrors(['delete_password']);

    expect($this->owner->fresh())->not->toBeNull();
});
