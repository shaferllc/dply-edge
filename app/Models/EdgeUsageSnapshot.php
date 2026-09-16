<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $bytes_egress
 * @property array<string, mixed>|null $meta
 * @property ?string $organization_id
 * @property Carbon $period_end
 * @property Carbon $period_start
 * @property int $r2_class_a_ops
 * @property int $r2_class_b_ops
 * @property int $r2_storage_bytes
 * @property int $requests
 * @property ?string $site_id
 * @property string $source
 * @property-read ?Organization $organization
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EdgeUsageSnapshot extends Model
{
    use HasUlids;

    public const SOURCE_PLACEHOLDER = 'placeholder';

    public const SOURCE_CLOUDFLARE_GRAPHQL = 'cloudflare_graphql';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'organization_id',
        'site_id',
        'period_start',
        'period_end',
        'requests',
        'bytes_egress',
        'r2_storage_bytes',
        'r2_class_a_ops',
        'r2_class_b_ops',
        'source',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'requests' => 'integer',
            'bytes_egress' => 'integer',
            'r2_storage_bytes' => 'integer',
            'r2_class_a_ops' => 'integer',
            'r2_class_b_ops' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
