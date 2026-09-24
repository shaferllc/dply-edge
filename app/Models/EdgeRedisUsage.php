<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily Redis usage. Written by EdgeRedisUsageCollector, read by EdgeRedisCost.
 * Columns: organization_id, site_id, date, commands, storage_bytes, bandwidth_bytes.
 * User request: "ok so how can we implement upstash and bill for it".
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property Carbon $date
 * @property int $commands
 * @property int $storage_bytes
 * @property int $bandwidth_bytes
 */
class EdgeRedisUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_redis_usage';

    protected $fillable = ['organization_id', 'site_id', 'date', 'commands', 'storage_bytes', 'bandwidth_bytes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'commands' => 'integer',
            'storage_bytes' => 'integer',
            'bandwidth_bytes' => 'integer',
        ];
    }
}
