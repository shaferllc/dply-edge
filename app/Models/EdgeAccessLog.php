<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $bytes_egress
 * @property string|null $cache_status
 * @property string|null $country
 * @property ?Carbon $created_at
 * @property string $duration_ms
 * @property ?string $edge_deployment_id
 * @property string $hostname
 * @property string $method
 * @property ?Carbon $occurred_at
 * @property ?string $organization_id
 * @property string $path
 * @property string|null $referrer
 * @property ?string $site_id
 * @property string $source
 * @property string|null $status_code
 * @property string|null $user_agent
 * @property-read ?Site $site
 * @property-read ?Organization $organization
 * @property Carbon $updated_at
 */
class EdgeAccessLog extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'site_id',
        'edge_deployment_id',
        'hostname',
        'method',
        'path',
        'status_code',
        'duration_ms',
        'bytes_egress',
        'country',
        'cache_status',
        'referrer',
        'user_agent',
        'source',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

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
}
