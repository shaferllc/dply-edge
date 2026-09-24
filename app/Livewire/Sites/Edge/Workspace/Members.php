<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeSiteMember;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\EdgeSiteViewData;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Per-site Edge members (Wave E P12). Org admins grant viewer / deployer /
 * admin roles on top of org membership for this Edge site only.
 */
class Members extends Component
{
    use ConfirmsActionWithModal;
    use DispatchesToastNotifications;
    use MountsEdgeWorkspaceSection;

    public string $member_user_id = '';

    public string $member_role = EdgeSiteMember::ROLE_VIEWER;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function addMember(): void
    {
        $this->authorize('manageMembers', $this->site);

        $this->validate([
            'member_user_id' => ['required', 'string'],
            'member_role' => ['required', 'in:'.implode(',', EdgeSiteMember::ROLES)],
        ]);

        $org = $this->site->organization;
        if ($org === null) {
            throw ValidationException::withMessages(['member_user_id' => __('Organization is required.')]);
        }

        $user = $org->users()->where('users.id', $this->member_user_id)->first();
        if ($user === null) {
            throw ValidationException::withMessages(['member_user_id' => __('Pick a member of this organization.')]);
        }

        if ($this->site->edgeSiteMembers()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['member_user_id' => __('That user already has a role on this site.')]);
        }

        EdgeSiteMember::query()->create([
            'site_id' => $this->site->id,
            'user_id' => $user->id,
            'role' => $this->member_role,
            'invited_by_user_id' => auth()->id(),
        ]);

        audit_log($org, auth()->user(), 'site.edge.member.added', $this->site, null, [
            'user_id' => (string) $user->id,
            'role' => $this->member_role,
        ]);

        $this->reset(['member_user_id', 'member_role']);
        $this->member_role = EdgeSiteMember::ROLE_VIEWER;
        $this->toastSuccess(__('Member added.'));
    }

    public function updateMemberRole(string $memberId, string $role): void
    {
        $this->authorize('manageMembers', $this->site);

        if (! EdgeSiteMember::isValidRole($role)) {
            return;
        }

        $member = $this->site->edgeSiteMembers()->whereKey($memberId)->first();
        if ($member === null) {
            return;
        }

        $member->update(['role' => $role]);

        audit_log($this->site->organization, auth()->user(), 'site.edge.member.role_updated', $this->site, null, [
            'user_id' => (string) $member->user_id,
            'role' => $role,
        ]);

        $this->toastSuccess(__('Role updated.'));
    }

    public function removeMember(string $memberId): void
    {
        $this->authorize('manageMembers', $this->site);

        $member = $this->site->edgeSiteMembers()->whereKey($memberId)->first();
        if ($member === null) {
            return;
        }

        $userId = (string) $member->user_id;
        $member->delete();

        audit_log($this->site->organization, auth()->user(), 'site.edge.member.removed', $this->site, null, [
            'user_id' => $userId,
        ]);

        $this->toastSuccess(__('Member removed.'));
    }

    public function render(): View
    {
        $org = $this->site->organization;
        abort_if($org === null, 403);

        $members = $this->site->edgeSiteMembers()
            ->with(['user:id,name,email', 'invitedBy:id,name'])
            ->orderBy('created_at')
            ->get();

        $eligibleUsers = $org->users()
            ->orderBy('users.name')
            ->get()
            ->filter(fn (User $user): bool => ! $members->contains('user_id', $user->id))
            ->values();

        return view('livewire.sites.edge.workspace.members', array_merge(
            EdgeSiteViewData::context($this->site, 'members'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'members' => $members,
                'eligibleUsers' => $eligibleUsers,
                'roleOptions' => [
                    EdgeSiteMember::ROLE_VIEWER => __('Viewer'),
                    EdgeSiteMember::ROLE_DEPLOYER => __('Deployer'),
                    EdgeSiteMember::ROLE_ADMIN => __('Admin'),
                ],
            ],
        ));
    }
}
