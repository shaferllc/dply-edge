<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $role
 * @property ?string $user_id
 * @property ?string $workspace_id
 * @property-read ?Workspace $workspace
 * @property-read ?User $user
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WorkspaceMember extends Model
{
    use HasUlids;

    public const ROLE_OWNER = 'owner';

    public const ROLE_MAINTAINER = 'maintainer';

    public const ROLE_DEPLOYER = 'deployer';

    public const ROLE_VIEWER = 'viewer';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'role',
    ];

    /** @return list<string> */
    public static function roles(): array
    {
        return [
            self::ROLE_OWNER,
            self::ROLE_MAINTAINER,
            self::ROLE_DEPLOYER,
            self::ROLE_VIEWER,
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
