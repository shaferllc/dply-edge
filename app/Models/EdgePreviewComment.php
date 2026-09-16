<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A review comment attached to a preview Edge site, anchored to a CSS
 *                      selector + viewport so the dashboard can replay it. Stored centrally
 *                      (not on GitHub) because we want non-engineer reviewers to leave
 *                      comments without a GitHub account — see C6 magic-link RBAC.
 * @property string|null $author_email
 * @property string|null $author_label
 * @property string $body
 * @property ?string $created_by_user_id
 * @property ?string $organization_id
 * @property ?string $parent_id
 * @property ?string $resolved_at
 * @property ?string $resolved_by_user_id
 * @property string|null $selector
 * @property ?string $site_id
 * @property string $url_path
 * @property string|null $viewport_width
 * @property-read ?Site $site
 * @property-read ?self $parent
 * @property-read ?Organization $organization
 * @property-read ?User $createdBy
 * @property-read ?User $resolvedBy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgePreviewComment extends Model
{
    use HasUlids;

    protected $table = 'edge_preview_comments';

    protected $fillable = [
        'organization_id',
        'site_id',
        'parent_id',
        'created_by_user_id',
        'author_label',
        'author_email',
        'selector',
        'viewport_width',
        'url_path',
        'body',
        'resolved_at',
        'resolved_by_user_id',
    ];

    protected $casts = [
        'viewport_width' => 'integer',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<EdgePreviewComment, $this>
     */
    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function authorDisplayName(): string
    {
        if ($this->createdBy) {
            return (string) ($this->createdBy->name ?: $this->createdBy->email);
        }

        return (string) ($this->author_label ?: 'Guest');
    }
}
