<?php

declare(strict_types=1);

namespace App\Actions\Organizations;

use App\Models\ApiToken;
use App\Models\EdgeSiteMember;
use App\Models\Organization;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use App\Notifications\OrganizationMembershipNotice;
use App\Policies\SitePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organization member management: change a role, remove or leave, transfer
 * ownership. Owners and admins manage members; only an owner touches an
 * owner or grants ownership; an organization always keeps one owner.
 *
 * Rule violations throw a ValidationException keyed `member`; acting without
 * the right role throws AuthorizationException.
 */
class ManageOrganizationMembers
{
    public const ROLES = ['owner', 'admin', 'member', 'deployer', Organization::VIEW_ONLY_ROLE];

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function changeRole(Organization $organization, User $actor, User $member, string $role): void
    {
        if (! in_array($role, self::ROLES, true)) {
            $this->fail(__('Choose a valid role.'));
        }

        $actorRole = $organization->memberRole($actor);
        $oldRole = $organization->memberRole($member);
        if (! in_array($actorRole, ['owner', 'admin'], true) || $oldRole === null) {
            throw new AuthorizationException;
        }
        if ($oldRole === $role) {
            return;
        }
        if ($actorRole !== 'owner' && ($oldRole === 'owner' || $role === 'owner')) {
            throw new AuthorizationException(__('Only an owner can change an owner or grant ownership.'));
        }

        $this->setRole($organization, $member, $oldRole, $role);

        audit_log($organization, $actor, 'member.role_changed', $member,
            ['user_id' => (string) $member->id, 'email' => $member->email, 'role' => $oldRole],
            ['role' => $role]);

        if (! $member->is($actor)) {
            $member->notify(new OrganizationMembershipNotice($organization, 'role_changed', $role));
        }
    }

    /**
     * Remove a member, or leave when $member is the actor.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function remove(Organization $organization, User $actor, User $member): void
    {
        $leaving = $member->is($actor);
        $actorRole = $organization->memberRole($actor);
        $oldRole = $organization->memberRole($member);

        if ($oldRole === null || $actorRole === null) {
            throw new AuthorizationException;
        }
        if (! $leaving && ($actorRole !== 'owner' && ($actorRole !== 'admin' || $oldRole === 'owner'))) {
            throw new AuthorizationException;
        }
        if ($oldRole === 'owner' && $this->ownerCount($organization) <= 1) {
            $this->fail($leaving
                ? __('You are the only owner. Make someone else owner before you leave.')
                : __('An organization must keep at least one owner.'));
        }

        $tokens = ApiToken::query()
            ->where('organization_id', $organization->id)
            ->where('user_id', $member->id)
            ->whereNull('revoked_at')
            ->get();

        DB::transaction(function () use ($organization, $member, $tokens): void {
            $this->deleteAppRoles($organization, $member);
            foreach ($organization->teams as $team) {
                $team->users()->detach($member->id);
            }
            WorkspaceMember::query()
                ->where('user_id', $member->id)
                ->whereIn('workspace_id', $organization->workspaces()->select('id'))
                ->delete();
            ApiToken::query()->whereKey($tokens->modelKeys())->update(['revoked_at' => now()]);
            $organization->users()->detach($member->id);
        });
        Organization::flushMemberRoleCache();
        $organization->rememberMemberRoleFor($member, null);

        foreach ($tokens as $token) {
            audit_log($organization, $actor, 'api_token.revoked', null, [
                'token_id' => (string) $token->id,
                'token_name' => $token->name,
                'token_prefix' => $token->token_prefix,
                'user_id' => (string) $member->id,
                'reason' => $leaving ? 'member_left' : 'member_removed',
            ], null);
        }
        audit_log($organization, $actor, $leaving ? 'member.left' : 'member.removed', $member,
            ['user_id' => (string) $member->id, 'email' => $member->email, 'role' => $oldRole], null);

        if ($oldRole !== Organization::VIEW_ONLY_ROLE) {
            SyncOrganizationBillingJob::dispatch($organization->id, 'member_removed')->afterCommit();
        }
        if (! $leaving) {
            $member->notify(new OrganizationMembershipNotice($organization, 'removed'));
        }
    }

    /**
     * Make another member owner; the acting owner optionally steps down to admin.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function transferOwnership(Organization $organization, User $actor, User $newOwner, bool $stepDown = false): void
    {
        $newOwnerRole = $organization->memberRole($newOwner);
        if ($organization->memberRole($actor) !== 'owner' || $newOwnerRole === null) {
            throw new AuthorizationException;
        }
        if ($newOwner->is($actor) || $newOwnerRole === 'owner') {
            $this->fail(__(':name is already an owner.', ['name' => $newOwner->name]));
        }

        DB::transaction(function () use ($organization, $actor, $newOwner, $newOwnerRole, $stepDown): void {
            $this->setRole($organization, $newOwner, $newOwnerRole, 'owner');
            if ($stepDown) {
                $this->setRole($organization, $actor, 'owner', 'admin');
            }
        });

        audit_log($organization, $actor, 'organization.ownership_transferred', $newOwner,
            ['user_id' => (string) $newOwner->id, 'email' => $newOwner->email, 'role' => $newOwnerRole],
            ['role' => 'owner', 'previous_owner_stepped_down' => $stepDown]);

        $newOwner->notify(new OrganizationMembershipNotice($organization, 'role_changed', 'owner'));
    }

    public function ownerCount(Organization $organization): int
    {
        return $organization->users()->wherePivot('role', 'owner')->count();
    }

    /** @throws ValidationException */
    private function setRole(Organization $organization, User $member, string $oldRole, string $role): void
    {
        if ($oldRole === 'owner' && $this->ownerCount($organization) <= 1) {
            $this->fail(__('An organization must keep at least one owner. Make someone else owner first.'));
        }

        $gainsSeat = $oldRole === Organization::VIEW_ONLY_ROLE && $role !== Organization::VIEW_ONLY_ROLE;
        $cap = $organization->effectiveMemberSeatCap();
        if ($gainsSeat && $cap !== null && $organization->seatsWithPendingInvites() >= $cap) {
            $this->fail(__('Your :plan plan includes :max seats (members plus pending invites; view-only members are free). Upgrade on the billing page to add more.', ['plan' => $organization->planTierLabel(), 'max' => $cap]));
        }

        // Viewers can't hold an app role (they stay view-only everywhere).
        if ($role === Organization::VIEW_ONLY_ROLE) {
            $this->deleteAppRoles($organization, $member);
        }

        $organization->users()->updateExistingPivot($member->id, ['role' => $role]);
        Organization::flushMemberRoleCache();
        $organization->rememberMemberRoleFor($member, $role);

        if ($gainsSeat || $role === Organization::VIEW_ONLY_ROLE) {
            SyncOrganizationBillingJob::dispatch($organization->id, 'member_role_changed')->afterCommit();
        }
    }

    private function deleteAppRoles(Organization $organization, User $member): void
    {
        EdgeSiteMember::query()
            ->where('user_id', $member->id)
            ->whereIn('site_id', $organization->sites()->select('id'))
            ->delete();
        SitePolicy::flushAppRoleCache();
    }

    /** @throws ValidationException */
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['member' => $message]);
    }
}
