<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Server $server): bool
    {
        if ($server->user_id === $user->id) {
            return true;
        }
        if ($server->organization_id && $server->organization->hasMember($user)) {
            if ($server->workspace_id && $server->workspace) {
                return $server->workspace->userCanView($user);
            }

            return true;
        }

        return false;
    }

    public function create(User $user): bool
    {
        $org = $user->currentOrganization();

        return $org !== null && ! $org->userHasRestrictedRole($user);
    }

    public function update(User $user, Server $server): bool
    {
        if (! $this->view($user, $server)) {
            return false;
        }

        if ($server->workspace_id && $server->workspace) {
            return $server->workspace->userCanUpdate($user);
        }

        return true;
    }

    public function delete(User $user, Server $server): bool
    {
        // dply's own control-plane infrastructure is never deletable from the
        // panel — see Server::isDeletionProtected(). Returning false here hides
        // every @can('delete')-gated affordance; DeleteServerAction is the hard
        // backstop for any non-UI caller.
        if ($server->isDeletionProtected()) {
            return false;
        }

        if (! $this->view($user, $server)) {
            return false;
        }

        if ($server->organization_id) {
            return $server->organization->hasAdminAccess($user);
        }

        return $server->user_id === $user->id;
    }
}
