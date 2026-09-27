<?php

namespace App\Policies;

use App\Models\EdgeSiteMember;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Workspaces\WorkspaceRegistry;

class SitePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->currentOrganization() !== null;
    }

    public function view(User $user, Site $site): bool
    {
        $server = $this->resolveServer($site);

        if ($server !== null && $user->can('view', $server)) {
            return true;
        }

        // An app role grants view to an org member who could not otherwise see it.
        return $this->orgRole($user, $site) !== null && $this->appRole($user, $site) !== null;
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();

        if ($org === null) {
            return false;
        }

        if ($org->userHasRestrictedRole($user)) {
            return false;
        }

        // Machine-site ceiling: every authorize('create', Site::class) caller
        // is a VM / container create path. Edge, Cloud and function creates
        // have their own ceilings and gate at their own create components.
        return $org->canCreateSite();
    }

    /**
     * Configure the app: settings, env vars, domains, resources, security,
     * build settings. Org owners/admins always can. Below that, an app role
     * (EdgeSiteMember) decides when one exists — only app Admin configures —
     * and otherwise the org role does: members yes, deployers no.
     */
    public function update(User $user, Site $site): bool
    {
        if ($this->isOrgViewer($user, $site)) {
            return false;
        }

        $workspace = app(WorkspaceRegistry::class)->for($site);
        if ($workspace !== null) {
            return $workspace->userCanView($user) && $workspace->userCanUpdate($user);
        }

        if (! $this->view($user, $site)) {
            return false;
        }

        if ($this->isOrgAdmin($user, $site)) {
            return true;
        }

        $appRole = $this->appRole($user, $site);
        if ($appRole !== null) {
            return EdgeSiteMember::rankFor($appRole) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_ADMIN);
        }

        return $this->orgRole($user, $site) === 'member';
    }

    /**
     * Ship code: deploy, redeploy, roll back, promote/tear down previews,
     * cancel/restart builds, purge cache. Same resolution as update(), but
     * app Deployer and org Deployer qualify.
     */
    public function deploy(User $user, Site $site): bool
    {
        if ($this->isOrgViewer($user, $site)) {
            return false;
        }

        $workspace = app(WorkspaceRegistry::class)->for($site);
        if ($workspace !== null) {
            return $workspace->userCanView($user) && $workspace->userCanDeploy($user);
        }

        if (! $this->view($user, $site)) {
            return false;
        }

        if ($this->isOrgAdmin($user, $site)) {
            return true;
        }

        $appRole = $this->appRole($user, $site);
        if ($appRole !== null) {
            return EdgeSiteMember::rankFor($appRole) >= EdgeSiteMember::rankFor(EdgeSiteMember::ROLE_DEPLOYER);
        }

        return in_array($this->orgRole($user, $site), ['member', 'deployer'], true);
    }

    public function clone(User $user, Site $site): bool
    {
        return $this->update($user, $site) && $this->create($user);
    }

    public function delete(User $user, Site $site): bool
    {
        $server = $this->resolveServer($site);
        if ($server === null || ! $user->can('view', $server)) {
            return false;
        }

        if ($site->organization_id !== null) {
            return $this->resolveOrganization($user, $site)?->hasAdminAccess($user) ?? false;
        }

        return $site->user_id === $user->id;
    }

    /**
     * Manage per-site Edge members (Wave E P12). Org admins always can;
     * Edge site admins elevate to the same gate.
     */
    public function manageMembers(User $user, Site $site): bool
    {
        if ($site->organization_id === null) {
            return false;
        }

        if ($this->isOrgAdmin($user, $site)) {
            return true;
        }

        return ! $this->isOrgViewer($user, $site) && $this->appRole($user, $site) === EdgeSiteMember::ROLE_ADMIN;
    }

    /**
     * The user's app role on this site, or null. Memoized per site+user —
     * update/deploy run on every @can in a workspace render. Flushed when an
     * EdgeSiteMember row changes ({@see EdgeSiteMember::booted()}).
     *
     * ponytail: process-static memo; a long-running worker that authorizes
     * after a role change in another process sees the old role until restart.
     */
    private function appRole(User $user, Site $site): ?string
    {
        if (! $site->usesEdgeRuntime()) {
            return null;
        }

        $key = $site->getKey().':'.$user->getKey();
        if (! array_key_exists($key, self::$appRoleMemo)) {
            $role = $site->edgeSiteMembers()->where('user_id', $user->id)->value('role');
            self::$appRoleMemo[$key] = is_string($role) && EdgeSiteMember::isValidRole($role) ? $role : null;
        }

        return self::$appRoleMemo[$key];
    }

    /** @var array<string, ?string> */
    private static array $appRoleMemo = [];

    public static function flushAppRoleCache(): void
    {
        self::$appRoleMemo = [];
    }

    private function orgRole(User $user, Site $site): ?string
    {
        return $this->resolveOrganization($user, $site)?->memberRole($user);
    }

    /** Org Viewers are view-only everywhere: an app role never lifts them (they hold no seat). */
    private function isOrgViewer(User $user, Site $site): bool
    {
        return $this->orgRole($user, $site) === Organization::VIEW_ONLY_ROLE;
    }

    private function isOrgAdmin(User $user, Site $site): bool
    {
        if ($site->organization_id === null) {
            return (string) $site->user_id === (string) $user->id;
        }

        return in_array($this->orgRole($user, $site), ['owner', 'admin'], true);
    }

    /**
     * Resolve a site's organization for an admin check, preferring the user's
     * already-memoized {@see User::currentOrganization()} when it's the same org
     * (the common case) so authorizing several site instances in one render
     * doesn't reload the same `organizations` row each time. Falls back to the
     * relation for the rare cross-org check.
     */
    private function resolveOrganization(User $user, Site $site): ?Organization
    {
        if ($site->organization_id === null) {
            return null;
        }

        $current = $user->currentOrganization();
        if ($current !== null && (string) $current->id === (string) $site->organization_id) {
            return $current;
        }

        return $site->organization;
    }

    private function resolveServer(Site $site): ?Server
    {
        if ($site->relationLoaded('server')) {
            return $site->server;
        }

        $routeServer = request()->route('server');
        if ($routeServer instanceof Server && (string) $routeServer->getKey() === (string) $site->server_id) {
            $site->setRelation('server', $routeServer);

            return $routeServer;
        }

        $site->loadMissing('server');

        return $site->server;
    }
}
