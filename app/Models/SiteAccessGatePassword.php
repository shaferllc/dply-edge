<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SiteAccessGatePasswordFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $label
 * @property string $password_salt
 * @property string $password_verifier
 * @property ?Carbon $pending_removal_at
 * @property ?string $site_id
 * @property string $sort_order
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteAccessGatePassword extends Model
{
    /** @use HasFactory<SiteAccessGatePasswordFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'site_id',
        'label',
        'password_salt',
        'password_verifier',
        'sort_order',
        'pending_removal_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pending_removal_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @param  Builder<SiteAccessGatePassword>  $query
     * @return Builder<SiteAccessGatePassword>
     */
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeNotPendingRemoval(Builder $query): Builder
    {
        return $query->whereNull('pending_removal_at');
    }

    public function isPendingRemoval(): bool
    {
        return $this->pending_removal_at !== null;
    }
}
