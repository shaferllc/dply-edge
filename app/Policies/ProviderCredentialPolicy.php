<?php

namespace App\Policies;

use App\Models\ProviderCredential;
use App\Models\User;

class ProviderCredentialPolicy
{
    public function viewAny(User $user): bool
    {
        $org = $user->currentOrganization();
        if ($org && $org->userHasRestrictedRole($user)) {
            return false;
        }

        return true;
    }

    public function view(User $user, ProviderCredential $providerCredential): bool
    {
        if ($providerCredential->user_id === $user->id) {
            return true;
        }
        if ($providerCredential->organization_id && $providerCredential->organization->hasMember($user)) {
            return ! $providerCredential->organization->userHasRestrictedRole($user);
        }

        return false;
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();
        if (! $org) {
            return false;
        }
        if ($org->userHasRestrictedRole($user)) {
            return false;
        }

        return true;
    }

    public function delete(User $user, ProviderCredential $providerCredential): bool
    {
        if ($providerCredential->organization_id) {
            return $providerCredential->organization?->hasAdminAccess($user) ?? false;
        }

        return $providerCredential->user_id === $user->id;
    }
}
