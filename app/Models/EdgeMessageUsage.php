<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Published dply Messages per organization and day (callbacks and schedule
 * firings count as messages), written by EdgeMessages::collectUsage.
 *
 * @property string $organization_id
 * @property Carbon $date
 * @property int $messages
 */
class EdgeMessageUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_message_usage';

    protected $fillable = ['organization_id', 'date', 'messages'];

    protected function casts(): array
    {
        return ['date' => 'date', 'messages' => 'integer'];
    }
}
