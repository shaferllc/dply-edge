<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property array<string, mixed>|null $category_breakdown
 * @property int $edge_usage_cents
 * @property array<string, mixed>|null $fleet_counts
 * @property int $monthly_total_cents
 * @property ?string $organization_id
 * @property Carbon $snapshot_date
 * @property string|null $subscription_interval
 * @property-read ?Organization $organization
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class OrganizationBillingSnapshot extends Model
{
    use HasUlids;

    protected $fillable = [
        'organization_id',
        'snapshot_date',
        'monthly_total_cents',
        'category_breakdown',
        'fleet_counts',
        'edge_usage_cents',
        'subscription_interval',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'monthly_total_cents' => 'integer',
            'category_breakdown' => 'array',
            'fleet_counts' => 'array',
            'edge_usage_cents' => 'integer',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
