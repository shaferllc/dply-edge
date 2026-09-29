<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily Workers CPU, Workers Logs events, Durable Objects and customer R2 bucket usage per Worker
 * script or bucket. Written by EdgePlatformUsageCollector, read by
 * EdgePlatformUsageCost.
 *
 * @property string $id
 * @property string $organization_id
 * @property ?string $site_id
 * @property string $resource
 * @property Carbon $date
 * @property int $cpu_ms
 * @property int $do_requests
 * @property float $do_gb_seconds
 * @property int $do_rows_read
 * @property int $do_rows_written
 * @property int $do_storage_bytes
 * @property int $r2_storage_bytes
 * @property int $r2_class_a_ops
 * @property int $r2_class_b_ops
 * @property int $images_transformations
 * @property int $log_events
 */
class EdgePlatformUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_platform_usage';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cpu_ms' => 'integer',
            'do_requests' => 'integer',
            'do_gb_seconds' => 'float',
            'do_rows_read' => 'integer',
            'do_rows_written' => 'integer',
            'do_storage_bytes' => 'integer',
            'r2_storage_bytes' => 'integer',
            'r2_class_a_ops' => 'integer',
            'r2_class_b_ops' => 'integer',
            'images_transformations' => 'integer',
            'log_events' => 'integer',
        ];
    }
}
