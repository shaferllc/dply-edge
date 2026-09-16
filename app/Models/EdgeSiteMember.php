<?php

declare(strict_types=1);

namespace App\Models;

use App\Policies\SitePolicy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      Per-site role grant for an Edge site (P12). Stacks on top of the
 *                      org-level membership — grants only ELEVATE rights, never restrict.
 *
 * @see SitePolicy
 *
 * @property ?string $invited_by_user_id
 * @property string $role
 * @property ?string $site_id
 * @property ?string $user_id
 * @property-read ?Site $site
 * @property-read ?User $user
 * @property-read ?User $invitedBy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgeSiteMember extends Model
{
    use HasUlids;

    public const ROLE_VIEWER = 'viewer';

    public const ROLE_DEPLOYER = 'deployer';

    public const ROLE_ADMIN = 'admin';

    public const ROLES = [self::ROLE_VIEWER, self::ROLE_DEPLOYER, self::ROLE_ADMIN];

    protected $fillable = [
        'site_id',
        'user_id',
        'role',
        'invited_by_user_id',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    /**
     * Numeric ranking so policy checks can compare "at least deployer".
     * Higher = more privileged.
     */
    public static function rankFor(string $role): int
    {
        return match ($role) {
            self::ROLE_ADMIN => 30,
            self::ROLE_DEPLOYER => 20,
            self::ROLE_VIEWER => 10,
            default => 0,
        };
    }
}
