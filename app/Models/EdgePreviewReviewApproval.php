<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $note
 * @property ?string $organization_id
 * @property ?string $site_id
 * @property ?string $user_id
 * @property-read ?Site $site
 * @property-read ?Organization $organization
 * @property-read ?User $user
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgePreviewReviewApproval extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'site_id',
        'user_id',
        'note',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
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
}
