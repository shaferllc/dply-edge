<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily dply Valkey awake seconds per app. Written by EdgeValkeyUsageCollector,
 * read by EdgeRedisCost. commands, storage_bytes and bandwidth_bytes are left
 * from the Upstash era and no longer written.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property Carbon $date
 * @property int $commands
 * @property int $storage_bytes
 * @property int $bandwidth_bytes
 * @property int $awake_seconds dply Valkey only
 */
class EdgeRedisUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_redis_usage';

    protected $fillable = ['organization_id', 'site_id', 'date', 'commands', 'storage_bytes', 'bandwidth_bytes', 'awake_seconds'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'commands' => 'integer',
            'storage_bytes' => 'integer',
            'bandwidth_bytes' => 'integer',
            'awake_seconds' => 'integer',
        ];
    }
}
