<?php

namespace App\Livewire\Organizations;

use App\Actions\Organizations\ManageOrganizationMembers;
use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationEventRegistry;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Head\Facades\Head;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The People page: members, pending invites and teams on one screen
 * (org redesign 2026-09-27). /teams redirects here with a team selected.
 */
#[Layout('layouts.app')]
class Members extends Component
{
    use ConfirmsActionWithModal;

    public Organization $organization;

    public string $invite_email = '';

    public string $invite_role = 'member';

    /** Left-rail filter: '' (everyone), 'pending' or 'none' (no team). Ignored while a team is selected. */
    #[Url(except: '')]
    public string $filter = '';

    /** Selected team id; resolved only against this organization's teams. */
    #[Url(except: '')]
    public string $team = '';

    public string $team_name = '';

    /** Team being renamed, and the draft name. */
    public ?string $renamingTeamId = null;

    public string $renameName = '';

    /** @var array<string, string> team id => user id (both ULIDs) for "add member" dropdown */
    public array $addMemberSelected = [];

    /** Team the invite-to-team modal is currently sending for. */
    public ?string $inviteTeamId = null;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        Head::title($organization->name.' · '.__('Members'));

        // The route-bound model is already fresh — only the relations need loading.
        $this->organization = $organization;
        $this->refreshOrganization(fresh: false);
    }

    protected function refreshOrganization(bool $fresh = true): void
    {
        $this->organization = ($fresh ? $this->organization->fresh() : $this->organization)
            ->load([
                'users',
                'invitations' => fn ($q) => $q->where('expires_at', '>', now())->with('team'),
                'teams' => fn ($q) => $q->orderBy('name')->with('users'),
            ]);
    }

    public function select(string $filter = '', string $team = ''): void
    {
        $this->filter = in_array($filter, ['pending', 'none'], true) ? $filter : '';
        $this->team = $team;
        $this->cancelRename();
    }

    /** The selected team, only if it belongs to this organization. */
    protected function selectedTeam(): ?Team
    {
        return $this->team === '' ? null : $this->organization->teams->firstWhere('id', $this->team);
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

    public function createTeam(): void
    {
        $this->validate([
            'team_name' => 'required|string|max:255',
        ]);

        $this->authorize('create', [Team::class, $this->organization]);

        $slug = Str::slug(Str::limit($this->team_name, 50));
        $base = $slug;
        $i = 0;
        while (Team::where('organization_id', $this->organization->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        $team = $this->organization->teams()->create([
            'name' => $this->team_name,
            'slug' => $slug,
        ]);
        audit_log($this->organization, auth()->user(), 'team.created', $team);

        $this->reset('team_name');
        $this->dispatch('close-modal', 'create-team-modal');
        $this->refreshOrganization();
        $this->select(team: (string) $team->id);
        $this->dispatch('notify', message: 'Team created.');
    }

    public function openCreateTeamModal(): void
    {
        $this->authorize('create', [Team::class, $this->organization]);

        $this->team_name = '';
        $this->resetValidation(['team_name']);
        $this->dispatch('open-modal', 'create-team-modal');
    }

    public function closeCreateTeamModal(): void
    {
        $this->team_name = '';
        $this->resetValidation(['team_name']);
        $this->dispatch('close-modal', 'create-team-modal');
    }

    public function startRename(string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        $this->renamingTeamId = (string) $team->id;
        $this->renameName = $team->name;
        $this->resetValidation(['renameName']);
    }

    public function cancelRename(): void
    {
        $this->renamingTeamId = null;
        $this->renameName = '';
        $this->resetValidation(['renameName']);
    }

    public function saveRename(): void
    {
        $team = $this->organization->teams()->findOrFail((string) $this->renamingTeamId);
        $this->authorize('update', $team);

        $this->renameName = trim($this->renameName);
        $this->validate(['renameName' => 'required|string|max:255'], [], ['renameName' => 'name']);

        $oldName = $team->name;
        if ($oldName !== $this->renameName) {
            $team->update(['name' => $this->renameName]);
            audit_log($this->organization, auth()->user(), 'team.updated', $team, ['name' => $oldName], ['name' => $this->renameName]);
            $this->dispatch('notify', message: 'Team renamed.');
        }

        $this->cancelRename();
        $this->refreshOrganization();
    }

    public function promptDeleteTeam(string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);

        $this->openConfirmActionModal(
            'deleteTeam',
            [$teamId],
            __('Delete team'),
            __('Delete the team “:team”? Its members stay in the organization.', ['team' => $team->name]),
            __('Delete'),
            true,
        );
    }

    public function deleteTeam(int|string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('delete', $team);
        audit_log($this->organization, auth()->user(), 'team.deleted', $team, ['name' => $team->name], null);
        $team->delete();

        if ($this->team === (string) $teamId) {
            $this->select();
        }
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team removed.');
    }

    public function addTeamMember(int|string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        // Users are keyed by ULID — never cast the id to int.
        $userId = (string) ($this->addMemberSelected[$teamId] ?? '');
        if ($userId === '') {
            $this->addError('team_'.$teamId, 'Select a user to add.');

            return;
        }
        $user = User::find($userId);
        if (! $user || ! $team->organization->hasMember($user)) {
            $this->addError('team_'.$teamId, 'User must be an organization member first.');

            return;
        }
        if ($team->users()->where('user_id', $userId)->exists()) {
            $this->addError('team_'.$teamId, 'User is already on this team.');

            return;
        }
        $team->users()->attach($userId, ['role' => 'member']);
        $this->addMemberSelected[$teamId] = '';

        audit_log($this->organization, auth()->user(), 'team.member_added', $team, null, [
            'team_id' => (string) $team->id,
            'user_id' => (string) $userId,
        ]);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Member added to team.');
    }

    public function openTeamInviteModal(string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        $this->inviteTeamId = (string) $team->id;
        $this->invite_email = '';
        $this->invite_role = 'member';
        $this->resetValidation(['invite_email', 'invite_role']);
        $this->dispatch('open-modal', 'invite-to-team-modal');
    }

    public function closeTeamInviteModal(): void
    {
        $this->inviteTeamId = null;
        $this->invite_email = '';
        $this->invite_role = 'member';
        $this->resetValidation(['invite_email', 'invite_role']);
        $this->dispatch('close-modal', 'invite-to-team-modal');
    }

    /**
     * Invite an email address straight onto a team. Someone who is already an
     * organization member needs no invitation — they're attached to the team
     * on the spot. Everyone else gets an org invitation carrying the team, and
     * joins both when they accept.
     */
    public function inviteToTeam(): void
    {
        $team = $this->organization->teams()->findOrFail((string) $this->inviteTeamId);
        $this->authorize('update', $team);

        $this->validate([
            'invite_email' => 'required|email',
            'invite_role' => 'nullable|string|in:admin,member,deployer,'.Organization::VIEW_ONLY_ROLE,
        ]);

        $email = strtolower($this->invite_email);

        $existingMember = $this->organization->users()->where('users.email', $email)->first();
        if ($existingMember) {
            if ($team->users()->where('user_id', $existingMember->id)->exists()) {
                throw ValidationException::withMessages([
                    'invite_email' => __('That member is already on this team.'),
                ]);
            }

            $team->users()->attach($existingMember->id, ['role' => 'member']);
            audit_log($this->organization, auth()->user(), 'team.member_added', $team, null, [
                'team_id' => (string) $team->id,
                'user_id' => $existingMember->id,
            ]);

            $this->closeTeamInviteModal();
            $this->refreshOrganization();
            $this->dispatch('notify', message: $existingMember->name.' was already a member — added to '.$team->name.'.');

            return;
        }

        // One pending invite per address per org (enforced by a unique index),
        // so an outstanding invite has to be cancelled before it can be re-sent for a team.
        if ($this->organization->invitations()->where('email', $email)->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages([
                'invite_email' => __('An invitation has already been sent to that address. Cancel it under Pending invites to re-send it for this team.'),
            ]);
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
            $actor,
            $team,
        );

        $event = app(NotificationPublisher::class)->publish(
            eventKey: 'organization.invitation.sent',
            subject: $this->organization,
            title: 'Invitation sent',
            body: $email.' was invited to join '.$this->organization->name.' on the team '.$team->name.'.',
            url: route('organizations.members', ['organization' => $this->organization, 'team' => $team->id], absolute: true),
            actor: $actor,
            recipientUsers: $this->organization->users()->wherePivotIn('role', ['owner', 'admin'])->pluck('users.id')->all(),
            metadata: [
                'invitation_id' => $invitation->id,
                'invitation_token' => $invitation->token,
                'email' => $email,
                'role' => $invitation->role,
                'organization_name' => $this->organization->name,
                'team_name' => $team->name,
                'inviter_name' => $actor->name !== '' ? $actor->name : ($actor->email !== '' ? $actor->email : __('Someone')),
            ],
        );
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification($event));
        audit_log($this->organization, auth()->user(), 'invitation.sent', $invitation);

        $this->closeTeamInviteModal();
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation sent to '.$email.'.');
    }

    public function promptRemoveTeamMember(string $teamId, string $userId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $member = $team->users()->where('users.id', $userId)->firstOrFail();

        $this->openConfirmActionModal(
            'removeTeamMember',
            [$teamId, $userId],
            __('Remove from team'),
            __('Remove :member from the team “:team”?', [
                'member' => $member->name,
                'team' => $team->name,
            ]),
            __('Remove'),
            true,
        );
    }

    public function removeTeamMember(int|string $teamId, int|string $userId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);
        $team->users()->detach($userId);

        audit_log($this->organization, auth()->user(), 'team.member_removed', $team, [
            'team_id' => (string) $team->id,
            'user_id' => (string) $userId,
        ], null);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Member removed from team.');
    }

    /**
     * What the selected team hears about: its channels, each with the events
     * routed to it. Loaded for the selected team only.
     *
     * @return list<array{label: string, type: string, icon: string, destination: string, events: list<string>}>
     */
    protected function teamRouting(Team $team): array
    {
        $registry = app(NotificationEventRegistry::class);

        return $team->notificationChannels()->with('subscriptions:id,notification_channel_id,event_key')->orderBy('label')->get()
            ->map(fn ($channel) => [
                'label' => (string) $channel->label,
                'type' => $channel::labelForType((string) $channel->type),
                'icon' => $channel::iconForType((string) $channel->type),
                'destination' => $channel->describeDestination(),
                'events' => $channel->subscriptions->pluck('event_key')->unique()
                    ->map(fn ($key) => $registry->definition((string) $key)['label'])->sort()->values()->all(),
            ])->all();
    }

    private function member(string $userId): User
    {
        return $this->organization->users()->where('users.id', $userId)->firstOrFail();
    }

    public function render(): View
    {
        $selectedTeam = $this->selectedTeam();

        return view('livewire.organizations.members', [
            'selectedTeam' => $selectedTeam,
            'routing' => $selectedTeam ? $this->teamRouting($selectedTeam) : [],
        ]);
    }
}
