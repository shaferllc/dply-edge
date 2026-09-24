<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use App\Models\Organization;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Per-org build concurrency slots (ruling r-zdescb7y05vp1bxx).
 *
 * A worker killed mid-build — cancelled, OOM, `horizon:terminate` — never runs
 * the `finally` that releases its slot, and the lock then blocks every later
 * build for the whole TTL. On the Free tier that is one slot, so one cancel
 * stalls the org for ~35 minutes with no failure row and nothing queued.
 * Both taking and force-releasing live here so the key can't drift apart.
 */
final class EdgeBuildSlots
{
    public static function key(Organization $organization, int $index): string
    {
        return 'edge-build-slot:'.$organization->id.':'.$index;
    }

    public static function count(Organization $organization): int
    {
        return max(1, (int) ($organization->tierAllowances()['concurrent_builds'] ?? 1));
    }

    /** The first free slot, or null when the org is at its concurrency limit. */
    public static function acquire(Organization $organization, int $ttlSeconds): ?Lock
    {
        for ($i = 0; $i < self::count($organization); $i++) {
            $lock = Cache::lock(self::key($organization, $i), $ttlSeconds);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    /**
     * Drop every slot this org holds. Only for the cancel path: a build still
     * running keeps its slot key but no longer owns it, so two builds could
     * overlap — the lesser evil against a stall nobody can see.
     */
    public static function releaseFor(?Organization $organization): void
    {
        if ($organization === null) {
            return;
        }

        for ($i = 0; $i < self::count($organization); $i++) {
            Cache::lock(self::key($organization, $i))->forceRelease();
        }
    }
}
