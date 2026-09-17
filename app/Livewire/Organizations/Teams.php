<?php

namespace App\Livewire\Organizations;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Team;
use App\Models\User;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Notifications\OrganizationInvitationNotification;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class Teams extends Component
{
    use ConfirmsActionWithModal {
        closeConfirmActionModal as private traitCloseConfirmActionModal;
        confirmActionModal as private traitConfirmActionModal;
    }

    public Organization $organization;

    public string $team_name = '';

    /** @var array<string, string> team id (ULID) => name for inline edit */
    public array $teamNames = [];

    /** @var array<string, string> team id => user id (both ULIDs) for "add member" dropdown */
    public array $addMemberSelected = [];

    /** Team the invite modal is currently sending for. */
    public ?string $inviteTeamId = null;

    public string $invite_email = '';

    public string $invite_role = 'member';

    /** Prevents reverting the team name field when closing the modal after confirm (see confirmActionModal). */
    public bool $suppressTeamRenameRevertOnClose = false;

    public function mount(Organization $organization): void
    {
        $this->authorize('view', $organization);
        $this->organization = $organization;
        // The route-bound model is already fresh — only the relations need
        // loading. Skipping fresh() here avoids a duplicate organizations SELECT.
        $this->refreshOrganization(fresh: false);
    }

    protected function refreshOrganization(bool $fresh = true): void
    {
        $this->organization = ($fresh ? $this->organization->fresh() : $this->organization)
            ->load([
                'users',
                'teams' => fn ($q) => $q->withCount('users')->with('users'),
                // Live invites only — expired ones are noise, and the Members
                // page filters the same way.
                'invitations' => fn ($q) => $q->where('expires_at', '>', now())->with('team'),
            ]);
        $this->syncTeamNames();
    }

    protected function syncTeamNames(): void
    {
        $this->teamNames = $this->organization->teams->keyBy('id')->map(fn ($t) => $t->name)->all();
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

        $this->organization->teams()->create([
            'name' => $this->team_name,
            'slug' => $slug,
        ]);
        audit_log($this->organization, auth()->user(), 'team.created', $this->organization->teams()->latest()->first());

        $this->reset('team_name');
        $this->dispatch('close-modal', 'create-team-modal');
        $this->refreshOrganization();
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

    public function promptDeleteTeam(string $teamId): void
    {
        $this->openConfirmActionModal(
            'deleteTeam',
            [$teamId],
            __('Delete team'),
            __('Remove this team?'),
            __('Delete'),
            true,
        );
    }

    public function promptSaveTeamNameOnBlur(string $teamId): void
    {
        $team = $this->organization->teams->firstWhere('id', $teamId);
        if (! $team) {
            return;
        }

        $new = trim((string) ($this->teamNames[$teamId] ?? ''));
        if ($new === $team->name) {
            return;
        }
        if ($new === '') {
            $this->teamNames[$teamId] = $team->name;

            return;
        }

        $this->openConfirmActionModal(
            'updateTeam',
            [$teamId],
            __('Save team name'),
            __('Change this team’s name from “:from” to “:to”?', [
                'from' => $team->name,
                'to' => $new,
            ]),
            __('Save'),
            false,
        );
    }

    public function closeConfirmActionModal(): void
    {
        $method = $this->confirmActionModalMethod;
        $arguments = $this->confirmActionModalArguments;

        $shouldRevertRename = ! $this->suppressTeamRenameRevertOnClose
            && $method === 'updateTeam'
            && isset($arguments[0]);

        if ($shouldRevertRename) {
            $tid = $arguments[0];
            $team = $this->organization->teams->firstWhere('id', $tid);
            if ($team) {
                $this->teamNames[$tid] = $team->name;
            }
            $this->resetValidation(['teamNames.'.$tid]);
        }

        $this->traitCloseConfirmActionModal();
    }

    public function confirmActionModal(): mixed
    {
        $this->suppressTeamRenameRevertOnClose = true;

        try {
            return $this->traitConfirmActionModal();
        } finally {
            $this->suppressTeamRenameRevertOnClose = false;
        }
    }

    public function updateTeam(int|string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        $name = $this->teamNames[$teamId] ?? $team->name;
        $key = 'teamNames.'.$teamId;
        $this->validate([
            $key => 'required|string|max:255',
        ], [], [$key => 'name']);
        $oldName = $team->name;
        $team->update(['name' => $name]);
        audit_log($this->organization, auth()->user(), 'team.updated', $team, ['name' => $oldName], ['name' => $name]);

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team updated.');
    }

    public function deleteTeam(int|string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('delete', $team);
        $org = $team->organization;
        audit_log($org, auth()->user(), 'team.deleted', $team, ['name' => $team->name], null);
        $team->delete();

        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Team removed.');
    }

    public function addTeamMember(int|string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        // Users are keyed by ULID — casting to int silently turned every id
        // into 1 and looked up a user that doesn't exist.
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

    public function openInviteModal(string $teamId): void
    {
        $team = $this->organization->teams()->findOrFail($teamId);
        $this->authorize('update', $team);

        $this->inviteTeamId = (string) $team->id;
        $this->invite_email = '';
        $this->invite_role = 'member';
        $this->resetValidation(['invite_email', 'invite_role']);
        $this->dispatch('open-modal', 'invite-to-team-modal');
    }

    public function closeInviteModal(): void
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
            'invite_role' => 'nullable|string|in:admin,member,deployer',
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

            $this->closeInviteModal();
            $this->refreshOrganization();
            $this->dispatch('notify', message: $existingMember->name.' was already a member — added to '.$team->name.'.');

            return;
        }

        // One pending invite per address per org (enforced by a unique index),
        // so an outstanding invite has to be cancelled on Members before it can
        // be re-sent for a team.
        if ($this->organization->invitations()->where('email', $email)->where('expires_at', '>', now())->exists()) {
            throw ValidationException::withMessages([
                'invite_email' => __('An invitation has already been sent to that address. Cancel it on Members to re-send it for this team.'),
            ]);
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
            $actor,
            $team,
        );

        $event = app(NotificationPublisher::class)->publish(
            eventKey: 'organization.invitation.sent',
            subject: $this->organization,
            title: 'Invitation sent',
            body: $email.' was invited to join '.$this->organization->name.' on the team '.$team->name.'.',
            url: route('organizations.teams', $this->organization, absolute: true),
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

        $this->closeInviteModal();
        $this->refreshOrganization();
        $this->dispatch('notify', message: 'Invitation sent to '.$email.'.');
    }

    /**
     * Roles assignable through invites. Owner is tied to org ownership and is
     * not granted via invitation — mirrors the Members page.
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

    public function promptRemoveTeamMember(string $teamId, int|string $userId): void
    {
        $team = $this->organization->teams->firstWhere('id', $teamId);
        $member = $team ? User::find($userId) : null;

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

    public function render(): View
    {
        return view('livewire.organizations.teams');
    }
}
