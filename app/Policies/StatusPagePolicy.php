<?php

namespace App\Policies;

use App\Models\StatusPage;
use App\Models\User;

class StatusPagePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, StatusPage $statusPage): bool
    {
        $org = $user->currentOrganization();

        return $org && $statusPage->organization_id === $org->id && $org->hasMember($user);
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();
        if (! $org || $org->userHasRestrictedRole($user)) {
            return false;
        }

        return true;
    }

    public function update(User $user, StatusPage $statusPage): bool
    {
        return $this->view($user, $statusPage) && ! $statusPage->organization->userIsViewer($user);
    }

    public function delete(User $user, StatusPage $statusPage): bool
    {
        if (! $this->view($user, $statusPage)) {
            return false;
        }

        return $statusPage->organization->hasAdminAccess($user);
    }
}
