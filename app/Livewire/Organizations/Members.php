<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
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
            'invite_role' => 'nullable|string|in:admin,member,deployer',
        ]);

        $email = strtolower($this->invite_email);
        if ($this->organization->users()->where('users.email', $email)->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'That user is already a member.']);
        }
        if ($this->organization->invitations()->where('email', $email)->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['invite_email' => 'An invitation has already been sent to that address.']);
        }

        $maxMembers = $this->organization->effectiveMemberSeatCap();
        if ($maxMembers !== null) {
            $current = $this->organization->users()->count();
            $pending = $this->organization->invitations()->where('expires_at', '>', now())->count();
            if ($current + $pending >= $maxMembers) {
                throw ValidationException::withMessages([
                    'invite_email' => __('Your :plan plan includes :max seats (members plus pending invites). Upgrade on the billing page to add more.', ['plan' => $this->organization->planTierLabel(), 'max' => $maxMembers]),
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

    public function render(): View
    {
        return view('livewire.organizations.members');
    }
}
