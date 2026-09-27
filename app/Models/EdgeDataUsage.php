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
 * @property array<string, array{rows_read: int, rows_written: int, storage_bytes: int}>|null $d1_by_database
 */
class EdgeDataUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_data_usage';

    protected $fillable = ['organization_id', 'date', 'd1_rows_read', 'd1_rows_written', 'd1_storage_bytes', 'queue_operations', 'd1_by_database'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date', 'd1_rows_read' => 'integer', 'd1_rows_written' => 'integer', 'd1_storage_bytes' => 'integer', 'queue_operations' => 'integer', 'd1_by_database' => 'array'];
    }
}
