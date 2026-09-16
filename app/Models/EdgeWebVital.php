<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property float|null $cls
 * @property string|null $country
 * @property ?Carbon $created_at
 * @property ?string $edge_deployment_id
 * @property string|null $fcp_ms
 * @property string $hostname
 * @property string|null $inp_ms
 * @property string|null $lcp_ms
 * @property ?Carbon $occurred_at
 * @property ?string $organization_id
 * @property string $path
 * @property ?string $site_id
 * @property string $source
 * @property string|null $ttfb_ms
 * @property-read ?Site $site
 * @property Carbon $updated_at
 */
class EdgeWebVital extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'organization_id',
        'site_id',
        'edge_deployment_id',
        'hostname',
        'path',
        'lcp_ms',
        'cls',
        'inp_ms',
        'fcp_ms',
        'ttfb_ms',
        'country',
        'source',
        'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cls' => 'float',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
