<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ?string $detail
 * @property ?string $http_status
 * @property string $outcome
 * @property ?string $provider_delivery_id
 * @property ?string $provider_event
 * @property ?string $request_ip
 * @property ?string $site_id
 * @property-read ?Site $site
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WebhookDeliveryLog extends Model
{
    use HasUlids;

    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_ERROR = 'error';

    protected $fillable = [
        'site_id',
        'request_ip',
        'http_status',
        'outcome',
        'detail',
        'provider_event',
        'provider_delivery_id',
    ];

    /** @return BelongsTo<Site, $this> */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
