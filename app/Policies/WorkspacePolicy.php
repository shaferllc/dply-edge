<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class WorkspacePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $workspace->userCanView($user);
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();
        if (! $org || $org->userHasRestrictedRole($user)) {
            return false;
        }

        return true;
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $workspace->userCanUpdate($user);
    }

    public function delete(User $user, Workspace $workspace): bool
    {
        if (! $this->view($user, $workspace)) {
            return false;
        }

        return $workspace->organization->hasAdminAccess($user);
    }
}
