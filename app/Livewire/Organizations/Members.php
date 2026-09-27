<?php

namespace App\Livewire\Organizations;

use App\Actions\Organizations\ManageOrganizationMembers;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Members extends Component
{
    use ConfirmsActionWithModal;

    public Organization $organization;

    public string $invite_email = '';

    public string $invite_role = 'member';

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);

        // The route-bound model is already fresh — just eager-load the relations
        // the view needs, rather than re-querying it via refreshOrganization().
        $this->organization = $organization->load([
            'users',
            'invitations' => fn ($q) => $q->where('expires_at', '>', now())->with('team'),
            'teams.users',
        ]);
    }

    protected function refreshOrganization(): void
    {
        $this->organization = $this->organization->fresh()
            ->load([
                'users',
                'invitations' => fn ($q) => $q->where('expires_at', '>', now())->with('team'),
                'teams.users',
            ]);
    }

    public function inviteMember(): void
    {
        $this->authorize('update', $this->organization);

        $this->validate([
            'invite_email' => 'required|email',
            'invite_role' => 'nullable|string|in:admin,member,deployer,'.Organization::VIEW_ONLY_ROLE,
        ]);

        $email = strtolower($this->invite_email);
        if ($this->organization->users()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'That user is already a member.']);
        }
        if ($this->organization->invitations()->where('email', $email)->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'An invitation has already been sent to that address.']);
        }

        $maxMembers = $this->organization->effectiveMemberSeatCap();
        // View-only members are free: inviting one never hits the seat cap.
        if ($maxMembers !== null && $this->invite_role !== Organization::VIEW_ONLY_ROLE) {
            if ($this->organization->seatsWithPendingInvites() >= $maxMembers) {
                throw ValidationException::withMessages([
                    'invite_email' => __('Your :plan plan includes :max seats (members plus pending invites; view-only members are free). Upgrade on the billing page to add more.', ['plan' => $this->organization->planTierLabel(), 'max' => $maxMembers]),
                ]);
            }
        }

        $actor = auth()->user();
        $invitation = OrganizationInvitation::createFor(
            $this->organization,
            $email,
            $this->invite_role ?: 'member',
            $actor
        );

        $event = app(NotificationPublisher::class)->publish(
            eventKey: 'organization.invitation.sent',
            subject: $this->organization,
            title: 'Invitation sent',
            body: $email.' was invited to join '.$this->organization->name.'.',
            url: route('organizations.members', $this->organization, absolute: true),
            actor: $actor,
            recipientUsers: $this->organization->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id')->all(),
            metadata: [
                'invitation_id' => $invitation->id,
                'invitation_token' => $invitation->token,
                'email' => $email,
                'role' => $invitation->role,
                'organization_name' => $this->organization->name,
                'inviter_name' => $actor->name !== '' ? $actor->name : ($actor->email !== '' ? $actor->email : __('Someone')),
            ],
        );
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($event));
        audit_log($this->organization, auth()->user(), 'invitation.sent', $invitation);

        $this->reset(['invite_email', 'invite_role']);
        $this->dispatch('close-modal', 'invite-member-modal');
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation sent to '.$email);
    }

    public function openInviteModal(): void
    {
        $this->authorize('update', $this->organization);

        $this->invite_email = '';
        $this->invite_role = 'member';
        $this->resetValidation(['invite_email', 'invite_role']);
        $this->dispatch('open-modal', 'invite-member-modal');
    }

    public function closeInviteModal(): void
    {
        $this->invite_email = '';
        $this->invite_role = 'member';
        $this->resetValidation(['invite_email', 'invite_role']);
        $this->dispatch('close-modal', 'invite-member-modal');
    }

    /**
     * Roles assignable through invites. Owner is tied to org ownership and is not granted via invitation.
     *
     * @return array<string, string>
     */
    public function inviteableRoles(): array
    {
        return [
            'member' => __('Member'),
            'admin' => __('Admin'),
            'deployer' => __('Deployer'),
            Organization::VIEW_ONLY_ROLE => __('Viewer (free)'),
        ];
    }

    public function promptCancelInvitation(string $invitationId): void
    {
        $this->openConfirmActionModal(
            'cancelInvitation',
            [$invitationId],
            __('Cancel invitation'),
            __('Cancel this invitation?'),
            __('Cancel invitation'),
            true,
        );
    }

    public function cancelInvitation(int|string $invitationId): void
    {
        $this->authorize('update', $this->organization);

        $invitation = $this->organization->invitations()->findOrFail($invitationId);
        $invitation->delete();
        audit_log($this->organization, auth()->user(), 'invitation.cancelled', $invitation);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation cancelled.');
    }

    /**
     * Roles a member row's dropdown offers. Ownership is granted with Make
     * owner, so Owner appears only on an owner's own row.
     *
     * @return array<string, string>
     */
    public function assignableRoles(bool $includeOwner = false): array
    {
        return ($includeOwner ? ['owner' => __('Owner')] : [])
            + ['admin' => __('Admin'), 'member' => __('Member'), 'deployer' => __('Deployer')]
            + [Organization::VIEW_ONLY_ROLE => __('Viewer (free)')];
    }

    /** Apply an upgrade at once; confirm a downgrade (less access) first. */
    public function promptChangeRole(string $userId, string $role): void
    {
        $member = $this->member($userId);
        $rank = array_flip(array_reverse(ManageOrganizationMembers::ROLES));
        $current = (string) $this->organization->memberRole($member);

        if (($rank[$role] ?? 0) >= ($rank[$current] ?? 0)) {
            $this->changeRole($userId, $role);

            return;
        }

        $this->openConfirmActionModal(
            'changeRole',
            [$userId, $role],
            __('Change role'),
            __('Change :name from :from to :to? They lose access that :from has.', [
                'name' => $member->name,
                'from' => $this->assignableRoles(true)[$current] ?? $current,
                'to' => $this->assignableRoles(true)[$role] ?? $role,
            ]),
            __('Change role'),
            true,
        );
    }

    public function changeRole(string $userId, string $role): void
    {
        $member = $this->member($userId);
        app(ManageOrganizationMembers::class)->changeRole($this->organization, auth()->user(), $member, $role);

        $this->refreshOrganization();
        $this->dispatch('notify', message: __(':name is now :role.', ['name' => $member->name, 'role' => $this->assignableRoles(true)[$role] ?? $role]));
    }

    public function promptRemoveMember(string $userId): void
    {
        $member = $this->member($userId);

        $this->openConfirmActionModal(
            'removeMember',
            [$userId],
            __('Remove member'),
            __('Remove :name from :org? They lose access to every app, their app roles and team places are removed, and their API tokens for this organization are revoked.', [
                'name' => $member->name,
                'org' => $this->organization->name,
            ]),
            __('Remove member'),
            true,
        );
    }

    public function removeMember(string $userId): mixed
    {
        if ($userId === (string) auth()->id()) {
            return $this->leave();
        }

        $member = $this->member($userId);
        app(ManageOrganizationMembers::class)->remove($this->organization, auth()->user(), $member);

        $this->refreshOrganization();
        $this->dispatch('notify', message: __(':name was removed.', ['name' => $member->name]));

        return null;
    }

    public function promptLeave(): void
    {
        $this->openConfirmActionModal(
            'leave',
            [],
            __('Leave organization'),
            __('Leave :org? You lose access to its apps, and your API tokens for it are revoked. An owner or admin must invite you to come back.', ['org' => $this->organization->name]),
            __('Leave'),
            true,
        );
    }

    public function leave(): mixed
    {
        $user = auth()->user();
        app(ManageOrganizationMembers::class)->remove($this->organization, $user, $user);

        if ((string) Session::get('current_organization_id') === (string) $this->organization->id) {
            Session::forget(['current_organization_id', 'current_team_id']);
        }
        Session::flash('success', __('You left :org.', ['org' => $this->organization->name]));

        return $this->redirect(route('dashboard'), navigate: true);
    }

    public function promptTransferOwnership(string $userId): void
    {
        $member = $this->member($userId);

        $this->openConfirmActionModal(
            'transferOwnership',
            [$userId],
            __('Make owner'),
            __('Make :name an owner of :org? Owners control billing and can delete the organization.', [
                'name' => $member->name,
                'org' => $this->organization->name,
            ]),
            __('Make owner'),
            false,
            toggleLabel: __('Step down to admin'),
            toggleHint: __('You stay in the organization as an admin.'),
        );
    }

    public function transferOwnership(string $userId, bool $stepDown = false): void
    {
        $member = $this->member($userId);
        app(ManageOrganizationMembers::class)->transferOwnership($this->organization, auth()->user(), $member, $stepDown);

        $this->refreshOrganization();
        $this->dispatch('notify', message: __(':name is now an owner.', ['name' => $member->name]));
    }

    private function member(string $userId): User
    {
        return $this->organization->users()->where('users.id', $userId)->firstOrFail();
    }

    public function render(): View
    {
        return view('livewire.organizations.members');
    }
}
