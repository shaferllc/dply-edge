<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A continuous span where a monitor was not operational. Opened on the
 *                      down/degraded transition and closed on recovery (resolved_at set). An
 *                      `outage` counts against uptime %; a `degraded` shows on the timeline but
 *                      still counts as up. SSL expiry warnings are not incidents.
 * @property string|null $cause
 * @property ?Carbon $resolved_at
 * @property string $severity
 * @property ?string $site_id
 * @property ?string $site_uptime_monitor_id
 * @property ?Carbon $started_at
 * @property-read ?SiteUptimeMonitor $monitor
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SiteUptimeIncident extends Model
{
    use HasUlids;

    public const SEVERITY_DEGRADED = 'degraded';

    public const SEVERITY_OUTAGE = 'outage';

    protected $fillable = [
        'site_uptime_monitor_id',
        'site_id',
        'severity',
        'cause',
        'started_at',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SiteUptimeMonitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(SiteUptimeMonitor::class, 'site_uptime_monitor_id');
    }

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isOngoing(): bool
    {
        return $this->resolved_at === null;
    }
}
