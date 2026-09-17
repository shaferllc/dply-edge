<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $organization_id
 * @property Carbon $date
 * @property int $d1_rows_read
 * @property int $d1_rows_written
 * @property int $d1_storage_bytes
 * @property int $queue_operations
 */
class EdgeDataUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_data_usage';

    protected $fillable = ['organization_id', 'date', 'd1_rows_read', 'd1_rows_written', 'd1_storage_bytes', 'queue_operations'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date', 'd1_rows_read' => 'integer', 'd1_rows_written' => 'integer', 'd1_storage_bytes' => 'integer', 'queue_operations' => 'integer'];
    }
}
