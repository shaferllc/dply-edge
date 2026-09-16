<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $actor_id
 * @property ?string $body
 * @property ?string $category
 * @property ?Carbon $cleared_at
 * @property ?string $cleared_by_user_id
 * @property string $event_key
 * @property ?array<string, mixed> $metadata
 * @property ?Carbon $occurred_at
 * @property ?string $organization_id
 * @property ?string $resource_id
 * @property ?string $resource_type
 * @property string $severity
 * @property ?string $subject_id
 * @property ?string $subject_type
 * @property bool $supports_email
 * @property bool $supports_in_app
 * @property bool $supports_webhook
 * @property ?string $team_id
 * @property string $title
 * @property ?string $url
 * @property-read ?User $actor
 * @property-read Collection<int, NotificationInboxItem> $inboxItems
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class NotificationEvent extends Model
{
    use HasUlids;

    protected $fillable = [
        'event_key',
        'subject_type',
        'subject_id',
        'resource_type',
        'resource_id',
        'organization_id',
        'team_id',
        'actor_id',
        'title',
        'body',
        'url',
        'severity',
        'category',
        'supports_in_app',
        'supports_email',
        'supports_webhook',
        'metadata',
        'occurred_at',
        'cleared_at',
        'cleared_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'supports_in_app' => 'boolean',
            'supports_email' => 'boolean',
            'supports_webhook' => 'boolean',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return HasMany<NotificationInboxItem, $this> */
    public function inboxItems(): HasMany
    {
        return $this->hasMany(NotificationInboxItem::class);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForResource(Builder $query, string $resourceType, string $resourceId): Builder
    {
        return $query
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId);
    }
}
