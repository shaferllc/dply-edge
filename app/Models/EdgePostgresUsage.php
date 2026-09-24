<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily Postgres compute and storage for one project.
 * Written by EdgePostgresUsageCollector, read by EdgeAppDatabaseCost.
 * User request: "ok lets move ahead with imp,emeting neon and postgres first".
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $project_id
 * @property Carbon $date
 * @property int $compute_unit_seconds
 * @property int $storage_byte_hours
 * @property int $history_byte_hours
 * @property int $snapshot_byte_hours
 * @property int $transfer_bytes
 */
class EdgePostgresUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_postgres_usage';

    protected $fillable = [
        'organization_id',
        'site_id',
        'project_id',
        'date',
        'compute_unit_seconds',
        'storage_byte_hours',
        'history_byte_hours',
        'snapshot_byte_hours',
        'transfer_bytes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'compute_unit_seconds' => 'integer',
            'storage_byte_hours' => 'integer',
            'history_byte_hours' => 'integer',
            'snapshot_byte_hours' => 'integer',
            'transfer_bytes' => 'integer',
        ];
    }
}
