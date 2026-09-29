<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain whose DNS dply runs (Routing → Use your own domain → Let dply run DNS).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property ?string $cloudflare_zone_id
 * @property string $status pending|active
 * @property ?list<string> $name_servers
 * @property ?list<string> $original_name_servers
 * @property bool $records_reviewed
 * @property ?\Illuminate\Support\Carbon $activated_at
 * @property ?\Illuminate\Support\Carbon $last_checked_at
 * @property ?string $created_by
 * @property-read ?Organization $organization
 */
class EdgeDnsZone extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    protected $fillable = [
        'organization_id', 'name', 'cloudflare_zone_id', 'status', 'name_servers',
        'original_name_servers', 'records_reviewed', 'activated_at', 'last_checked_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'name_servers' => 'array',
            'original_name_servers' => 'array',
            'records_reviewed' => 'boolean',
            'activated_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Whether $hostname is this domain or one of its subdomains. */
    public function covers(string $hostname): bool
    {
        $hostname = strtolower(rtrim($hostname, '.'));

        return $hostname === $this->name || str_ends_with($hostname, '.'.$this->name);
    }
}
