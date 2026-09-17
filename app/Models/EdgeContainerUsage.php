<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property Carbon $date
 * @property ?string $application_id
 * @property float $cpu_seconds
 * @property float $memory_gib_seconds
 * @property float $disk_gb_seconds
 * @property int $tx_bytes
 */
class EdgeContainerUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_container_usage';

    protected $fillable = [
        'organization_id', 'site_id', 'date', 'application_id',
        'cpu_seconds', 'memory_gib_seconds', 'disk_gb_seconds', 'tx_bytes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'cpu_seconds' => 'float',
            'memory_gib_seconds' => 'float',
            'disk_gb_seconds' => 'float',
            'tx_bytes' => 'integer',
        ];
    }
}
