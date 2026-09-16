<?php

namespace App\Models;

use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property ?string $description
 * @property string $name
 * @property ?string $notes
 * @property ?string $organization_id
 * @property string $slug
 * @property ?string $user_id
 * @property-read ?Organization $organization
 * @property-read ?User $user
 * @property-read Collection<int, Server> $servers
 * @property-read Collection<int, Site> $sites
 * @property-read Collection<int, WorkspaceMember> $members
 * @property-read Collection<int, NotificationSubscription> $notificationSubscriptions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'description',
        'notes',
    ];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace): void {
            if (empty($workspace->slug)) {
                $workspace->slug = Str::slug($workspace->name) ?: 'project';
            }

            $base = $workspace->slug;
            $n = 0;
            while (static::query()
                ->where('organization_id', $workspace->organization_id)
                ->where('slug', $workspace->slug)
                ->exists()) {
                $n++;
                $workspace->slug = $base.'-'.$n;
            }
        });

        static::created(function (Workspace $workspace): void {
            if (! $workspace->members()->where('user_id', $workspace->user_id)->exists()) {
                $workspace->members()->create([
                    'user_id' => $workspace->user_id,
                    'role' => WorkspaceMember::ROLE_OWNER,
                ]);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'notes' => 'string',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /** @return HasMany<Site, $this> */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class)->orderBy('created_at');
    }

    /** @return MorphMany<NotificationSubscription, $this> */
    public function notificationSubscriptions(): MorphMany
    {
        return $this->morphMany(NotificationSubscription::class, 'subscribable');
    }

    public function hasMember(User $user): bool
    {
        // Reuse the already-loaded members collection when present so per-row
        // permission checks (userCanManageMembers/userCanDeploy/… in the project
        // tabs) don't fire a membership query for every row. Falls back to a
        // direct query when the relation isn't loaded.
        if ($this->relationLoaded('members')) {
            return $this->members->contains('user_id', $user->id);
        }

        return $this->members()->where('user_id', $user->id)->exists();
    }

    public function memberRole(?User $user): ?string
    {
        if (! $user) {
            return null;
        }

        if ($this->relationLoaded('members')) {
            return $this->members->firstWhere('user_id', $user->id)?->role;
        }

        return $this->members()
            ->where('user_id', $user->id)
            ->value('role');
    }

    public function userCanView(User $user): bool
    {
        $current = $user->currentOrganization();
        if ($this->organization_id !== $current?->id) {
            return false;
        }

        // We just confirmed this workspace's org IS the user's current org, which
        // is already memoized with the member role primed — reuse it instead of
        // lazy-loading $this->organization (another `organizations where id = ?`
        // plus a role lookup).
        return $current->hasAdminAccess($user) || $this->hasMember($user);
    }

    public function userCanUpdate(User $user): bool
    {
        if (! $this->userCanView($user)) {
            return false;
        }

        // userCanView confirmed this workspace's org is the user's current org —
        // reuse the memoized instance rather than re-loading $this->organization.
        if ($user->currentOrganization()?->hasAdminAccess($user)) {
            return true;
        }

        return in_array($this->memberRole($user), [
            WorkspaceMember::ROLE_OWNER,
            WorkspaceMember::ROLE_MAINTAINER,
        ], true);
    }

    public function userCanManageMembers(User $user): bool
    {
        if ($this->organization->hasAdminAccess($user)) {
            return true;
        }

        return $this->memberRole($user) === WorkspaceMember::ROLE_OWNER;
    }

    public function userCanDeploy(User $user): bool
    {
        if ($this->organization->hasAdminAccess($user)) {
            return true;
        }

        return in_array($this->memberRole($user), [
            WorkspaceMember::ROLE_OWNER,
            WorkspaceMember::ROLE_MAINTAINER,
            WorkspaceMember::ROLE_DEPLOYER,
        ], true);
    }
}
