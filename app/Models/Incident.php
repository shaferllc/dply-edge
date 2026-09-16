<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $impact
 * @property ?Carbon $resolved_at
 * @property ?Carbon $started_at
 * @property string $state
 * @property ?string $status_page_id
 * @property string $title
 * @property ?string $user_id
 * @property-read ?StatusPage $statusPage
 * @property-read ?User $user
 * @property-read Collection<int, IncidentUpdate> $incidentUpdates
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Incident extends Model
{
    use HasUlids;

    public const IMPACT_NONE = 'none';

    public const IMPACT_MINOR = 'minor';

    public const IMPACT_MAJOR = 'major';

    public const IMPACT_CRITICAL = 'critical';

    public const STATE_INVESTIGATING = 'investigating';

    public const STATE_IDENTIFIED = 'identified';

    public const STATE_MONITORING = 'monitoring';

    public const STATE_RESOLVED = 'resolved';

    protected $fillable = [
        'status_page_id',
        'user_id',
        'title',
        'impact',
        'state',
        'started_at',
        'resolved_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<StatusPage, $this> */
    public function statusPage(): BelongsTo
    {
        return $this->belongsTo(StatusPage::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<IncidentUpdate, $this> */
    public function incidentUpdates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('created_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
