<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily key-value reads, writes, deletes, lists, and stored bytes per store.
 * Written by EdgeKvUsageCollector, read by EdgeKvCost.
 * User request: "continue buuiikding out key value".
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property string $namespace_id
 * @property Carbon $date
 * @property int $reads
 * @property int $writes
 * @property int $deletes
 * @property int $lists
 * @property int $storage_bytes
 */
class EdgeKvUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_kv_usage';

    protected $fillable = ['organization_id', 'site_id', 'namespace_id', 'date', 'reads', 'writes', 'deletes', 'lists', 'storage_bytes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'reads' => 'integer',
            'writes' => 'integer',
            'deletes' => 'integer',
            'lists' => 'integer',
            'storage_bytes' => 'integer',
        ];
    }
}
