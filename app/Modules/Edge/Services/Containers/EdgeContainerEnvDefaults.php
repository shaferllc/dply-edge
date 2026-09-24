<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Production env a container app needs to boot, filled in only where the site
 * has nothing set: Laravel's APP_KEY and friends, Rails' SECRET_KEY_BASE.
 * Values are saved as site env vars so secrets stay stable across deploys and
 * show up (and can be changed) under Environment.
 */
final class EdgeContainerEnvDefaults
{
    /**
     * @param  array<string, string>  $env  Production env for this deploy
     * @return array<string, string> $env plus any defaults added
     */
    public static function ensure(Site $site, string $checkout, array $env): array
    {
        $defaults = match (true) {
            is_file($checkout.'/artisan') => [
                'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
                'APP_ENV' => 'production',
                'APP_URL' => (string) ($site->edgeLiveUrl() ?? ''),
                ...self::sqliteDefaults($site, $env),
                // Vite reads asset_url. Without it, Laravel builds stylesheet
                // links from the container's plain-HTTP request and the
                // browser drops them as mixed content.
                'ASSET_URL' => (string) ($site->edgeLiveUrl() ?? ''),
                'LOG_CHANNEL' => 'stderr',
                // No shared disk between containers: keep sessions in cookies.
                'SESSION_DRIVER' => 'cookie',
            ],
            is_file($checkout.'/config/application.rb') => [
                'SECRET_KEY_BASE' => bin2hex(random_bytes(64)),
                'RAILS_ENV' => 'production',
                'RAILS_LOG_TO_STDOUT' => '1',
                'RAILS_SERVE_STATIC_FILES' => '1',
            ],
            is_file($checkout.'/package.json') && ! is_file($checkout.'/composer.json') && ! is_file($checkout.'/Gemfile') => [
                'NODE_ENV' => 'production',
            ],
            default => [],
        };

        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $env) || $value === '') {
                continue;
            }

            $env[$key] = self::persistDefault($site, $key, $value);
        }

        return $env;
    }

    /**
     * Persist a missing default, or reuse the row another deploy already wrote.
     * Build jobs snapshot env before clone, so a later create() races the unique
     * (site_id, scope, key) index — firstOrCreate plus a 23505 retry covers both
     * the stale-snapshot and two-workers-at-once cases.
     */
    private static function persistDefault(Site $site, string $key, string $value): string
    {
        $match = [
            'site_id' => $site->id,
            'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
            'key' => $key,
        ];

        try {
            $row = EdgeSiteEnvVar::query()->firstOrCreate($match, ['value' => $value]);
        } catch (UniqueConstraintViolationException) {
            $row = EdgeSiteEnvVar::query()->where($match)->firstOrFail();
        }

        return (string) $row->value;
    }

    /**
     * A container app with no database of its own boots on a file SQLite
     * database. The file is created and migrated on each start.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private static function sqliteDefaults(Site $site, array $env): array
    {
        if (isset($env['DB_CONNECTION']) || isset($env['DB_URL']) || isset($env['DATABASE_URL'])) {
            return [];
        }
        if ((string) ($site->edgeMeta()['database']['engine'] ?? '') === 'none') {
            return [];
        }

        return [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => '/tmp/database.sqlite',
        ];
    }

    /** Masked for build logs. */
    public static function describe(array $before, array $after): string
    {
        $added = array_diff_key($after, $before);

        return $added === [] ? '' : 'Added production env defaults: '.implode(', ', array_map(
            static fn (string $key): string => Str::contains($key, ['KEY', 'SECRET']) ? $key.' (generated)' : $key.'='.$after[$key],
            array_keys($added),
        ))."\n";
    }
}
