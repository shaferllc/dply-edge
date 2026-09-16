<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 *                      A user's recently-visited resource, recorded by the command palette when the
 *                      operator drills into a site or server. Powers the palette's empty-query
 *                      "Recently visited" group. One row per (user, resource_type, resource_id); a
 *                      re-visit bumps {@see $visited_at} rather than inserting a duplicate.
 *                      This is a lightweight history record, not a source of truth — labels and URLs
 *                      are always re-resolved from the live resource at render time, so renamed or
 *                      deleted resources never show stale.
 * @property ?string $resource_id
 * @property string $resource_type
 * @property ?string $user_id
 * @property ?Carbon $visited_at
 * @property-read ?User $user
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RecentResource extends Model
{
    use HasUlids;

    /** Most-recent rows kept per user; older ones are pruned on write. */
    public const KEEP_PER_USER = 20;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'resource_type',
        'resource_id',
        'visited_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visited_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record (or bump) a visit, then prune the user's history to the most
     * recent {@see KEEP_PER_USER} rows.
     */
    public static function record(string $userId, string $type, string $id): void
    {
        static::query()->updateOrCreate(
            ['user_id' => $userId, 'resource_type' => $type, 'resource_id' => $id],
            ['visited_at' => now()],
        );

        $keepIds = static::query()
            ->where('user_id', $userId)
            ->orderByDesc('visited_at')
            ->limit(self::KEEP_PER_USER)
            ->pluck('id');

        static::query()
            ->where('user_id', $userId)
            ->whereNotIn('id', $keepIds)
            ->delete();
    }
}
