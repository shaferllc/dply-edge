<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use RuntimeException;

/**
 * The image a container site runs. A repo `Dockerfile` wins; otherwise one is
 * generated for Laravel/PHP (FrankenPHP) or Rails/Ruby (Puma) and written as
 * `Dockerfile.dply` at the checkout root, which stays the build context.
 *
 * Generated images listen on 8080 and run migrations on boot
 * (`DPLY_MIGRATE_ON_BOOT=0` opts out) — containers start fresh every time, so
 * there is no separate release step to hang them on.
 */
final class EdgeContainerDockerfile
{
    /**
     * @return array{path: string, port: int, stack: string, generated: bool}
     */
    public static function prepare(string $checkout): array
    {
        $default = (int) config('edge.build.containers.default_port', 8080);

        if (is_file($checkout.'/Dockerfile')) {
            return [
                'path' => $checkout.'/Dockerfile',
                'port' => self::exposedPort((string) file_get_contents($checkout.'/Dockerfile')) ?? $default,
                'stack' => 'dockerfile',
                'generated' => false,
            ];
        }

        [$stack, $contents] = match (true) {
            is_file($checkout.'/composer.json') => ['php', self::php($checkout)],
            is_file($checkout.'/Gemfile') => ['ruby', self::ruby($checkout)],
            default => throw new RuntimeException('Container sites need a Dockerfile, composer.json (PHP) or Gemfile (Ruby) at the repository root.'),
        };

        file_put_contents($checkout.'/Dockerfile.dply', $contents);

        return ['path' => $checkout.'/Dockerfile.dply', 'port' => 8080, 'stack' => $stack, 'generated' => true];
    }

    /** First `EXPOSE` port in a Dockerfile, if any. */
    public static function exposedPort(string $dockerfile): ?int
    {
        return preg_match('/^\s*EXPOSE\s+(\d+)/mi', $dockerfile, $m) === 1 ? (int) $m[1] : null;
    }

    private static function php(string $checkout): string
    {
        $composer = json_decode((string) file_get_contents($checkout.'/composer.json'), true);
        $constraint = (string) (($composer['config']['platform']['php'] ?? null) ?: ($composer['require']['php'] ?? ''));
        $version = preg_match('/(8\.\d)/', $constraint, $m) === 1 ? $m[1] : '8.4';
        $laravel = is_file($checkout.'/artisan');
        $assets = is_file($checkout.'/package.json');

        $lines = [];
        if ($assets) {
            $lines[] = 'FROM node:22-bookworm-slim AS assets';
            $lines[] = 'WORKDIR /app';
            $lines[] = 'COPY . .';
            $lines[] = 'RUN (npm ci || npm install) && (npm run build --if-present)';
            $lines[] = '';
        }
        $lines[] = "FROM dunglas/frankenphp:1-php{$version}";
        $lines[] = 'RUN install-php-extensions pdo_pgsql pdo_mysql redis intl zip bcmath pcntl opcache';
        $lines[] = 'COPY --from=composer:2 /usr/bin/composer /usr/bin/composer';
        $lines[] = 'WORKDIR /app';
        $lines[] = 'COPY . .';
        if ($assets) {
            $lines[] = 'COPY --from=assets /app/public /app/public';
        }
        $lines[] = 'RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress';
        $lines[] = 'ENV SERVER_NAME=":8080" DPLY_MIGRATE_ON_BOOT=1';
        if ($laravel) {
            $lines[] = 'RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache && chmod -R 775 storage bootstrap/cache';
            $lines[] = 'ENV LOG_CHANNEL=stderr';
        }
        $lines[] = 'EXPOSE 8080';
        $boot = $laravel
            ? 'if [ "$DPLY_MIGRATE_ON_BOOT" = "1" ]; then php artisan migrate --force --isolated || true; fi; exec frankenphp run --config /etc/frankenphp/Caddyfile'
            : 'exec frankenphp run --config /etc/frankenphp/Caddyfile';
        $lines[] = 'CMD ["sh", "-c", '.json_encode($boot, JSON_UNESCAPED_SLASHES).']';

        return implode("\n", $lines)."\n";
    }

    private static function ruby(string $checkout): string
    {
        $version = '3.3';
        if (is_file($checkout.'/.ruby-version') && preg_match('/(\d+\.\d+)/', (string) file_get_contents($checkout.'/.ruby-version'), $m) === 1) {
            $version = $m[1];
        }
        $rails = is_file($checkout.'/config/application.rb');

        $lines = [
            "FROM ruby:{$version}-slim",
            'RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends build-essential git libpq-dev libyaml-dev pkg-config curl nodejs && apt-get clean',
            'WORKDIR /app',
            'ENV RAILS_ENV=production RACK_ENV=production BUNDLE_WITHOUT="development:test" RAILS_LOG_TO_STDOUT=1 RAILS_SERVE_STATIC_FILES=1 PORT=8080 DPLY_MIGRATE_ON_BOOT=1',
            'COPY Gemfile Gemfile.lock* ./',
            'RUN bundle install --jobs 4',
            'COPY . .',
        ];
        if ($rails) {
            $lines[] = 'RUN SECRET_KEY_BASE_DUMMY=1 bundle exec rails assets:precompile || true';
        }
        $lines[] = 'EXPOSE 8080';
        $boot = $rails
            ? 'if [ "$DPLY_MIGRATE_ON_BOOT" = "1" ]; then bundle exec rails db:prepare || true; fi; exec bundle exec puma -b tcp://0.0.0.0:8080'
            : 'exec bundle exec rackup -o 0.0.0.0 -p 8080';
        $lines[] = 'CMD ["sh", "-c", '.json_encode($boot, JSON_UNESCAPED_SLASHES).']';

        return implode("\n", $lines)."\n";
    }
}
