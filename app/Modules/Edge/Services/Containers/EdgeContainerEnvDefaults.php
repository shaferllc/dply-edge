<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Models\EdgeSiteEnvVar;
use App\Models\Site;
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
                'APP_DEBUG' => 'false',
                'APP_URL' => (string) ($site->edgeLiveUrl() ?? ''),
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
            EdgeSiteEnvVar::query()->create([
                'site_id' => $site->id,
                'key' => $key,
                'value' => $value,
                'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
            ]);
            $env[$key] = $value;
        }

        return $env;
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
