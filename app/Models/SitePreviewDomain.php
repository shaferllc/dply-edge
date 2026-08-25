<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property bool $auto_ssl
 * @property string $dns_status
 * @property string $hostname
 * @property bool $https_redirect
 * @property bool $is_primary
 * @property ?string $label
 * @property ?Carbon $last_dns_checked_at
 * @property ?Carbon $last_ssl_checked_at
 * @property bool $managed_by_dply
 * @property ?array<string, mixed> $meta
 * @property ?string $provider_record_id
 * @property ?string $provider_type
 * @property ?string $record_data
 * @property ?string $record_name
 * @property ?string $record_type
 * @property ?string $site_id
 * @property string $ssl_status
 * @property ?string $zone
 * @property-read ?Site $site
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class SitePreviewDomain extends Model
{
    use HasUlids;

    protected $fillable = [
        'site_id',
        'hostname',
        'label',
        'zone',
        'record_name',
        'provider_type',
        'provider_record_id',
        'record_type',
        'record_data',
        'dns_status',
        'ssl_status',
        'is_primary',
        'auto_ssl',
        'https_redirect',
        'managed_by_dply',
        'last_dns_checked_at',
        'last_ssl_checked_at',
        'meta',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'auto_ssl' => 'boolean',
            'https_redirect' => 'boolean',
            'managed_by_dply' => 'boolean',
            'last_dns_checked_at' => 'datetime',
            'last_ssl_checked_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

}
