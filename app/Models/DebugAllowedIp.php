<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An IP / CIDR permitted to see the interactive Lookout debug page for a
 * production 500 (the "who" half of the debug-page gate, alongside the
 * viewPlatformAdmin email allow-list). Kept deliberately SEPARATE from
 * {@see ComingSoonAllowedIp}: previewing the marketing site and reading full
 * stack traces + context are different blast radii and shouldn't share a list.
 *
 * The effective list is the union of env entries
 * ({@see config('dply.debug_allowed_ips')}) and admin-managed rows, cached
 * because the gate is consulted while rendering an error.
 *
 * @property string $ip
 * @property string|null $label
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DebugAllowedIp extends Model
{
    public const CACHE_KEY = 'debug_allowed_ips';

    protected $fillable = ['ip', 'label', 'created_by'];

    protected static function booted(): void
    {
        static::saved(static fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(static fn () => Cache::forget(self::CACHE_KEY));
    }

    /**
     * Effective allow-list: env entries ∪ managed rows, cached for the gate.
     *
     * @return list<string>
     */
    public static function allowList(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, static function (): array {
            $env = (array) config('dply.debug_allowed_ips', []);
            $db = self::query()->pluck('ip')->all();

            return array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                array_merge($env, $db),
            ))));
        });
    }

    /**
     * Whether the given client IP is in the allow-list. Matches IPv4, IPv6, and
     * CIDR ranges via Symfony's IpUtils — the same matcher the coming-soon gate
     * uses. Fails closed on a null/empty IP.
     */
    public static function allows(?string $ip): bool
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return false;
        }

        $allowed = self::allowList();

        return $allowed !== [] && IpUtils::checkIp($ip, $allowed);
    }
}
