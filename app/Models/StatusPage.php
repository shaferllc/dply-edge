<?php

namespace App\Models;

use Database\Factories\StatusPageFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property ?string $description
 * @property bool $is_public
 * @property string $name
 * @property ?string $organization_id
 * @property string $slug
 * @property ?string $user_id
 * @property-read ?Organization $organization
 * @property-read ?User $user
 * @property-read Collection<int, StatusPageMonitor> $monitors
 * @property-read Collection<int, Incident> $incidents
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StatusPage extends Model
{
    /** @use HasFactory<StatusPageFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'organization_id',
        'user_id',
        'name',
        'slug',
        'description',
        'is_public',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (StatusPage $page): void {
            if (empty($page->slug)) {
                $page->slug = Str::slug($page->name) ?: 'status';
            }

            $base = $page->slug;
            $n = 0;
            while (static::query()->where('slug', $page->slug)->exists()) {
                $n++;
                $page->slug = $base.'-'.$n;
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<StatusPageMonitor, $this> */
    public function monitors(): HasMany
    {
        return $this->hasMany(StatusPageMonitor::class)->orderBy('sort_order')->orderBy('id');
    }

    /** @return HasMany<Incident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class)->orderByDesc('started_at');
    }

    /** @return HasMany<Incident, $this> */
    public function openIncidents(): HasMany
    {
        return $this->hasMany(Incident::class)
            ->whereNull('resolved_at')
            ->orderByDesc('started_at');
    }
}
