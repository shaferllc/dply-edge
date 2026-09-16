<?php

namespace App\Models;

use App\Models\Concerns\RoutesIntercomNotifications;
use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $name
 * @property ?string $organization_id
 * @property ?array<string, mixed> $preferences
 * @property ?string $slug
 * @property-read ?Organization $organization
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Server> $servers
 * @property-read Collection<int, NotificationChannel> $notificationChannels
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory, HasUlids;

    use RoutesIntercomNotifications;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'preferences',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'preferences' => 'array',
        ];
    }

    /**
     * Team-level defaults for servers and sites (list behavior, sorting).
     * Falls back to legacy keys stored on the organization before teams had their own column.
     *
     * @return array<string, mixed>
     */
    public function mergedTeamPreferences(): array
    {
        $defaults = config('user_preferences.team_server_site_defaults', []);
        $keys = array_keys($defaults);
        $stored = $this->preferences ?? [];
        $merged = array_merge($defaults, array_intersect_key($stored, array_flip($keys)));

        $org = $this->organization;
        if ($org) {
            $legacyOrg = $org->server_site_preferences ?? [];
            foreach ($keys as $key) {
                if (! array_key_exists($key, $stored) && array_key_exists($key, $legacyOrg)) {
                    $merged[$key] = $legacyOrg[$key];
                }
            }
        }

        return $merged;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /** @return HasMany<Server, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->users()->where('user_id', $user->id)->exists();
    }

    /**
     * Team admins or organization owners/admins may manage team SSH keys.
     */
    public function userCanManageSshKeys(User $user): bool
    {
        if ($this->organization->hasAdminAccess($user)) {
            return true;
        }

        return $this->users()->where('user_id', $user->id)->wherePivot('role', 'admin')->exists();
    }

    /**
     * Team admins or organization owners/admins may manage team notification channels.
     */
    public function userCanManageNotificationChannels(User $user): bool
    {
        return $this->userCanManageSshKeys($user);
    }

    /** @return MorphMany<NotificationChannel, $this> */
    public function notificationChannels(): MorphMany
    {
        return $this->morphMany(NotificationChannel::class, 'owner');
    }
}
