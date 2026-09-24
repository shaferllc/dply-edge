<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Daily HTTP delivery messages and bandwidth per app.
 * Written by the delivery usage hook, read by EdgeDeliveryCost.
 * Columns: organization_id, site_id, date, messages, bandwidth_bytes.
 * User request: "ok then lets build that out and we need to charge for it".
 *
 * @property string $id
 * @property string $organization_id
 * @property string $site_id
 * @property Carbon $date
 * @property int $messages
 * @property int $bandwidth_bytes
 */
class EdgeDeliveryUsage extends Model
{
    use HasUlids;

    protected $table = 'edge_delivery_usage';

    protected $fillable = ['organization_id', 'site_id', 'date', 'messages', 'bandwidth_bytes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'date' => 'date',
            'messages' => 'integer',
            'bandwidth_bytes' => 'integer',
        ];
    }
}
