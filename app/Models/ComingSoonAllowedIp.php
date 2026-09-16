<?php

declare(strict_types=1);

namespace App\Models;

use App\Http\Middleware\RedirectGuestsToComingSoon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * An IP / CIDR allowed to see the full site while the coming-soon gate is on
 * (see {@see RedirectGuestsToComingSoon}). The effective
 * allow-list is the union of these rows and the COMING_SOON_ALLOWED_IPS env
 * entries — cached, since the gate runs on every request.
 *
 * @property string|null $created_by
 * @property string $ip
 * @property string|null $label
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ComingSoonAllowedIp extends Model
{
    public const CACHE_KEY = 'coming_soon_allowed_ips';

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
            $env = (array) config('dply.coming_soon_allowed_ips', []);
            $db = self::query()->pluck('ip')->all();

            return array_values(array_unique(array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                array_merge($env, $db),
            ))));
        });
    }
}
