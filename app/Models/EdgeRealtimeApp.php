<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Realtime app on the customer relay (docs/edge-realtime.md). The id is
 * the Pusher app_id; app_key / app_secret are what Reverb and Echo use.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property string $name
 * @property ?string $hostname {label}.{edge.realtime.app_host_suffix}; EdgeRealtimeApps::hostFor decides when it is used.
 * @property string $app_key
 * @property string $app_secret
 * @property string $status
 * @property int $max_connections
 * @property array<int, string>|null $allowed_origins Not guaranteed a list; EdgeRealtimeApps::record re-indexes it.
 * @property bool $client_events
 * @property array<string, mixed>|null $meta
 * @property-read ?Organization $organization
 * @property-read ?Site $site
 */
class EdgeRealtimeApp extends Model
{
    use HasUlids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $fillable = [
        'organization_id', 'site_id', 'name', 'hostname', 'app_key', 'app_secret', 'status',
        'max_connections', 'allowed_origins', 'client_events', 'meta',
    ];

    protected $hidden = ['app_secret'];

    protected function casts(): array
    {
        return [
            'app_secret' => 'encrypted',
            'allowed_origins' => 'array',
            'client_events' => 'boolean',
            'max_connections' => 'integer',
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
