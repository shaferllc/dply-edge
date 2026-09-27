<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily Realtime connection time, messages and peak sockets per app.
 * Written by EdgeRealtimeUsageCollector, read by EdgeRealtimeCost.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property string $realtime_app_id
 * @property Carbon $date
 * @property int $connection_seconds
 * @property int $messages
 * @property int $peak_connections
 */
class EdgeRealtimeUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_realtime_usage';

    protected $fillable = ['organization_id', 'site_id', 'realtime_app_id', 'date', 'connection_seconds', 'messages', 'peak_connections'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'connection_seconds' => 'integer',
            'messages' => 'integer',
            'peak_connections' => 'integer',
        ];
    }
}
