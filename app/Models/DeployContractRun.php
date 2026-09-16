<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, mixed> $checks
 * @property ?Carbon $finished_at
 * @property string|null $git_commit
 * @property ?string $organization_id
 * @property ?string $parent_site_id
 * @property ?string $preview_deployment_id
 * @property ?string $preview_site_id
 * @property string $status
 * @property array<string, mixed> $summary
 * @property ?string $triggered_by_user_id
 * @property ?Carbon $waived_at
 * @property ?string $waived_by_user_id
 * @property string|null $waiver_reason
 * @property-read ?Site $parentSite
 * @property-read ?Site $previewSite
 * @property-read ?User $triggeredBy
 * @property-read ?User $waivedBy
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DeployContractRun extends Model
{
    use HasUlids;

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_WAIVED = 'waived';

    protected $fillable = [
        'organization_id',
        'parent_site_id',
        'preview_site_id',
        'preview_deployment_id',
        'git_commit',
        'triggered_by_user_id',
        'status',
        'checks',
        'summary',
        'waiver_reason',
        'waived_by_user_id',
        'waived_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checks' => 'array',
            'summary' => 'array',
            'waived_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isPromoteAllowed(): bool
    {
        return in_array($this->status, [self::STATUS_PASSED, self::STATUS_WAIVED], true);
    }

    /** @return BelongsTo<Site, $this> */
    public function parentSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'parent_site_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function previewSite(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'preview_site_id');
    }

    /** @return BelongsTo<User, $this> */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function waivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'waived_by_user_id');
    }
}
