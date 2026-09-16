<?php

namespace App\Models;

use App\Services\Status\MonitorOperationalState;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      One recorded uptime check. Append-only history behind a monitor's last_*
 *                      snapshot; powers uptime %, latency trends and incident stitching. `state`
 *                      mirrors {@see MonitorOperationalState} values.
 * @property ?Carbon $checked_at
 * @property ?string $error
 * @property string|null $http_status
 * @property string|null $latency_ms
 * @property string|null $probe_worker
 * @property ?string $site_uptime_monitor_id
 * @property string $state
 * @property-read ?SiteUptimeMonitor $monitor
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class SiteUptimeCheckResult extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = [
        'site_uptime_monitor_id',
        'checked_at',
        'state',
        'http_status',
        'latency_ms',
        'error',
        'probe_worker',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SiteUptimeMonitor, $this> */
    public function monitor(): BelongsTo
    {
        return $this->belongsTo(SiteUptimeMonitor::class, 'site_uptime_monitor_id');
    }
}
