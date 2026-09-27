<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait ManagesOrganizationMembership
{
    /**
     * Ensure a first team exists (idempotent). Used when model events are disabled (e.g. seeders) and after legacy org rows.
     */
    public function createDefaultTeamIfMissing(): Team
    {
        $existing = $this->teams()->orderBy('created_at')->first();
        if ($existing) {
            return $existing;
        }

        $base = Str::slug(__('general'));
        $slug = $base;
        $i = 0;
        while ($this->teams()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $this->teams()->create([
            'name' => __('General'),
            'slug' => $slug,
        ]);
    }

    /**
     * Add an organization member to the default (first) team as team admin when not already present.
     */
    public function attachUserToDefaultTeam(User $user, string $teamRole = 'admin'): void
    {
        if (! $this->hasMember($user)) {
            return;
        }

        $team = $this->createDefaultTeamIfMissing();
        if ($team->users()->where('user_id', $user->id)->exists()) {
            return;
        }

        $team->users()->attach($user->id, ['role' => $teamRole]);
    }

    public function memberRole(User $user): ?string
    {
        $userId = (string) $user->id;
        if (array_key_exists($userId, $this->memberRoleMemo)) {
            return $this->memberRoleMemo[$userId];
        }

        $staticKey = (string) $this->id.':'.$userId;
        if (array_key_exists($staticKey, self::$memberRoleStaticMemo)) {
            return $this->memberRoleMemo[$userId] = self::$memberRoleStaticMemo[$staticKey];
        }

        $related = $this->users()->where('user_id', $user->id)->first();
        $pivot = $related?->getRelationValue('pivot');
        $role = $pivot !== null ? (string) data_get($pivot, 'role') : null;

        self::$memberRoleStaticMemo[$staticKey] = $role;

        return $this->memberRoleMemo[$userId] = $role;
    }

    /**
     * Seed the member-role memo from an organization_user pivot that was
     * already loaded on this org instance (e.g. via $user->organizations()).
     */
    public function rememberMemberRoleFor(User $user, ?string $role): void
    {
        $userId = (string) $user->id;
        $this->memberRoleMemo[$userId] = $role;
        self::$memberRoleStaticMemo[(string) $this->id.':'.$userId] = $role;
    }

    /** Drop the cross-instance member-role cache (between requests in long-running processes / tests). */
    public static function flushMemberRoleCache(): void
    {
        self::$memberRoleStaticMemo = [];
    }

    public function hasMember(User $user): bool
    {
        return $this->memberRole($user) !== null;
    }

    public function hasAdminAccess(User $user): bool
    {
        return in_array($this->memberRole($user), ['owner', 'admin'], true);
    }

    public function userIsDeployer(User $user): bool
    {
        return $this->memberRole($user) === 'deployer';
    }

    /** Org Viewer: sees everything in the org, changes nothing, and does not use a seat. */
    public function userIsViewer(User $user): bool
    {
        return $this->memberRole($user) === self::VIEW_ONLY_ROLE;
    }

    /** Deployer or Viewer: may not create or configure organization resources. */
    public function userHasRestrictedRole(User $user): bool
    {
        return in_array($this->memberRole($user), ['deployer', self::VIEW_ONLY_ROLE], true);
    }
}
