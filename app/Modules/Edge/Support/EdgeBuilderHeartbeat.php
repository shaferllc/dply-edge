<?php

declare(strict_types=1);

namespace App\Modules\Edge\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "Some builder is alive." Each healthy builder's `dply:runtime:check` (its
 * pod probe, every minute) stamps the time; dply:edge:check-builders on the
 * control plane alerts when no builder has stamped it for STALE_AFTER.
 *
 * ponytail: one shared stamp, not one per host: the alert is "zero builders
 * alive", which one key answers. Per-host keys if a builder list is wanted.
 */
final class EdgeBuilderHeartbeat
{
    public const KEY = 'dply:builders:heartbeat';

    public const STALE_AFTER_SECONDS = 300;

    public static function beat(string $host): void
    {
        Cache::forever(self::KEY, ['at' => now()->getTimestamp(), 'host' => $host]);
    }

    /** @return array{at: Carbon, host: string}|null */
    public static function last(): ?array
    {
        $raw = Cache::get(self::KEY);
        if (! is_array($raw) || ! isset($raw['at'])) {
            return null;
        }

        return ['at' => Carbon::createFromTimestamp((int) $raw['at']), 'host' => (string) ($raw['host'] ?? '')];
    }

    public static function alive(): bool
    {
        $last = self::last();

        return $last !== null && $last['at']->diffInSeconds(now(), true) <= self::STALE_AFTER_SECONDS;
    }
}
