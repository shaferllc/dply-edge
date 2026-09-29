<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Edge\Workspace;

use App\Livewire\Concerns\ConfirmsActionWithModal;
use App\Livewire\Concerns\DispatchesToastNotifications;
use App\Livewire\Concerns\Edge\MountsEdgeWorkspaceSection;
use App\Models\EdgeSiteMember;
use App\Models\Organization;
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

    /** Person open in the modal (user id), or null. */
    public ?string $editingUserId = null;

    /** The modal is adding someone (with a person picker). */
    public bool $adding = false;

    public function mount(Server $server, Site $site): void
    {
        $this->mountEdgeWorkspaceSection($server, $site);
    }

    public function openAdd(): void
    {
        $this->authorize('manageMembers', $this->site);
        $this->resetErrorBag();
        $this->member_user_id = '';
        $this->member_role = EdgeSiteMember::ROLE_VIEWER;
        $this->editingUserId = null;
        $this->adding = true;
        $this->dispatch('open-modal', 'edge-member');
    }

    /** Open someone whose access this page can change: not an org owner/admin or viewer. */
    public function editPerson(string $userId): void
    {
        $this->authorize('manageMembers', $this->site);
        $org = $this->site->organization;
        $role = $org?->users()->where('users.id', $userId)->first()?->pivot?->role;
        abort_unless(in_array($role, ['member', 'deployer'], true), 404);

        $this->resetErrorBag();
        $this->editingUserId = $userId;
        $this->adding = false;
        // '' = no app role: their org role applies.
        $this->member_role = (string) ($this->site->edgeSiteMembers()->where('user_id', $userId)->value('role') ?? '');
        $this->dispatch('open-modal', 'edge-member');
    }

    public function closePerson(): void
    {
        $this->editingUserId = null;
        $this->adding = false;
        $this->dispatch('close-modal', 'edge-member');
    }

    public function savePerson(): void
    {
        $this->authorize('manageMembers', $this->site);

        if ($this->adding) {
            $this->addMember();
            if ($this->getErrorBag()->isEmpty()) {
                $this->closePerson();
            }

            return;
        }
        if ($this->editingUserId === null) {
            return;
        }

        $member = $this->site->edgeSiteMembers()->where('user_id', $this->editingUserId)->first();
        match (true) {
            $this->member_role === '' && $member !== null => $this->removeMember((string) $member->id),
            $this->member_role === '' => null,
            $member !== null => $this->updateMemberRole((string) $member->id, $this->member_role),
            default => (function (): void {
                $this->member_user_id = (string) $this->editingUserId;
                $this->addMember();
            })(),
        };
        if ($this->getErrorBag()->isEmpty()) {
            $this->closePerson();
        }
    }

    public function removePerson(): void
    {
        $this->member_role = '';
        $this->savePerson();
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

        // Org Viewers stay view-only (no seat), so they cannot be given an app role.
        $user = $org->users()->wherePivot('role', '!=', Organization::VIEW_ONLY_ROLE)->where('users.id', $this->member_user_id)->first();
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

    /**
     * What a person can do on this app, the way SitePolicy resolves it:
     * org owner/admin → everything; an app role decides when one exists;
     * otherwise the org role (member configures + deploys, deployer deploys,
     * viewer looks).
     */
    private static function accessFor(string $orgRole, ?string $appRole): string
    {
        return match (true) {
            in_array($orgRole, ['owner', 'admin'], true) => 'all',
            $orgRole === Organization::VIEW_ONLY_ROLE => 'view',
            $appRole === EdgeSiteMember::ROLE_ADMIN => 'admin',
            $appRole === EdgeSiteMember::ROLE_DEPLOYER => 'deploy',
            $appRole === EdgeSiteMember::ROLE_VIEWER => 'view',
            $orgRole === 'member' => 'configure',
            $orgRole === 'deployer' => 'deploy',
            default => 'view',
        };
    }

    public function render(): View
    {
        $org = $this->site->organization;
        abort_if($org === null, 403);

        $appRoles = $this->site->edgeSiteMembers()->pluck('role', 'user_id');
        $rank = ['all' => 0, 'admin' => 1, 'configure' => 2, 'deploy' => 3, 'view' => 4];
        $people = $org->users()->orderBy('users.name')->get()
            ->map(function (User $user) use ($appRoles): array {
                $orgRole = (string) $user->pivot->role;
                $appRole = $appRoles[$user->id] ?? null;

                return [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'org_role' => $orgRole,
                    'app_role' => $appRole,
                    'access' => self::accessFor($orgRole, $appRole),
                    'editable' => in_array($orgRole, ['member', 'deployer'], true),
                ];
            })
            ->sortBy(fn (array $p): int => $rank[$p['access']])
            ->values();

        $editing = $this->editingUserId !== null ? $people->firstWhere('id', $this->editingUserId) : null;

        return view('livewire.sites.edge.workspace.members', array_merge(
            EdgeSiteViewData::context($this->site, 'members'),
            [
                'server' => $this->server,
                'site' => $this->site,
                'people' => $people,
                'editing' => $editing,
                'eligibleUsers' => $people->filter(fn (array $p): bool => $p['editable'] && $p['app_role'] === null)->values(),
                'roleOptions' => [
                    EdgeSiteMember::ROLE_VIEWER => __('Viewer'),
                    EdgeSiteMember::ROLE_DEPLOYER => __('Deployer'),
                    EdgeSiteMember::ROLE_ADMIN => __('Admin'),
                ],
            ],
        ));
    }
}
