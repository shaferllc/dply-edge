<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property string $id
 * @property string $kind
 * @property string $name
 * @property ?string $organization_id
 * @property string $slug
 * @property ?string $user_id
 * @property-read ?Organization $organization
 * @property-read ?User $user
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Project extends Model
{
    use HasUlids;

    public const KIND_BYO_SITE = 'byo_site';

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'kind',
    ];

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

    /** @return HasOne<Site, $this> */
    public function site(): HasOne
    {
        return $this->hasOne(Site::class);
    }

}
