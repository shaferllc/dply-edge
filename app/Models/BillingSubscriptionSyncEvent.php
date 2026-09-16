<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, mixed>|null $changes
 * @property array<string, mixed>|null $desired_state
 * @property ?string $error_message
 * @property int $monthly_total_cents
 * @property ?string $organization_id
 * @property string $status
 * @property string $trigger
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BillingSubscriptionSyncEvent extends Model
{
    use HasUlids;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NO_OP = 'no_op';

    protected $fillable = [
        'organization_id',
        'trigger',
        'status',
        'changes',
        'desired_state',
        'monthly_total_cents',
        'error_message',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'desired_state' => 'array',
            'monthly_total_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
