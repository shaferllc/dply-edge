<?php

declare(strict_types=1);

namespace App\Modules\Edge\Services\Containers;

use App\Modules\Edge\Services\NodeVersionDetector;
use App\Modules\Edge\Services\RuntimeDetection\FrontendAssetBuild;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The image a container site runs. A repo `Dockerfile` wins; otherwise one is
 * generated for Laravel/PHP (FrankenPHP), Rails/Ruby (Puma) or a Node HTTP
 * server (`npm start` on $PORT) and written as
 * `Dockerfile.dply` at the checkout root, which stays the build context.
 *
 * Each image starts as that stack's default, then gains only the steps the
 * repo asks for. A package.json `build` / `production` script, or an npm or
 * Vite line in composer.json, adds a Node stage that runs that command and
 * copies the built `public/` in. No compile step means no Node stage.
 *
 * Generated images listen on 8080. Migrations on boot are opt-in
 * (`DPLY_MIGRATE_ON_BOOT=1`): they cost a second framework boot exactly when a
 * cold-starting container has the least memory, and repeat on every wake from
 * sleep. Containers start fresh every time, so there is no separate release
 * step to hang them on.
 */
final class EdgeContainerDockerfile
{
    /**
     * FrankenPHP tags we generate against, newest first.
     *
     * Only versions with a published `dunglas/frankenphp:1-php<v>-alpine` tag
     * belong here — a missing one makes every build resolving to it fail with
     * `manifest unknown`. 8.1 is EOL and has no tag; 8.6 is unreleased.
     */
    public const PHP_VERSIONS = ['8.5', '8.4', '8.3', '8.2'];

    /**
     * Highest version an *open-ended* constraint gets.
     *
     * `^8.2` legally allows 8.5, but frameworks lag: Laravel 12 supports
     * 8.2–8.4, so resolving every app to the newest release the day we publish
     * it would upgrade customers without them asking. An explicit `^8.5` or
     * `8.5.*` still gets 8.5 — raise this once the ecosystem catches up.
     */
    public const PHP_DEFAULT_MAX = '8.4';

    /**
     * PHP application servers we can generate an image for.
     *
     * Swoole and RoadRunner only make sense with laravel/octane installed —
     * they keep the framework booted between requests, so choosing one for an
     * app that isn't Octane-aware changes its semantics (and fails at boot,
     * since `octane:start` won't exist). Hence detection, not free choice.
     */
    public const PHP_SERVERS = ['frankenphp', 'swoole', 'roadrunner', 'fpm'];

    /** Ruby minors we publish a prebuilt base for, newest first. */
    public const RUBY_VERSIONS = ['3.4', '3.3', '3.2'];

    /**
     * Alpine over bookworm: ~110MB vs ~250MB, and every build host pulls it.
     * install-php-extensions ships in the Alpine FrankenPHP images too.
     */
    public const PHP_BASE_IMAGE = 'dunglas/frankenphp:1-php%s-alpine';

    /** Native gem toolchain (pg, nokogiri) — the Ruby equivalent of the PHP compile. */
    public const RUBY_PACKAGES = 'build-essential git libpq-dev libyaml-dev pkg-config curl nodejs';

    /**
     * Compiling these is the expensive part of a cold PHP build (~5 min).
     * Must stay byte-identical between the generated Dockerfile and
     * WarmEdgeBuildImagesCommand, or the warmed layer cache misses.
     */
    public const PHP_EXTENSIONS = 'pdo_pgsql pdo_mysql redis intl zip bcmath pcntl opcache';

    /**
     * Compiled into the official PHP images this generator builds on.
     * Asking install-php-extensions for them only prints
     * "Module already installed".
     */
    private const BUNDLED_PHP_EXTENSIONS = [
        'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'iconv', 'json',
        'libxml', 'mbstring', 'openssl', 'pcre', 'phar', 'posix', 'reflection',
        'pdo', 'session', 'simplexml', 'sodium', 'spl', 'tokenizer', 'xml', 'xmlreader',
        'xmlwriter', 'zlib',
    ];

    /**
     * What a generated PHP image builds on.
     *
     * With `containers.php_base_repo` set, that prebuilt image already carries
     * the extensions, so a build pulls one layer instead of compiling for ~5
     * minutes — and every build host benefits, not just locally warmed ones.
     * Unset, it falls back to compiling them, which is what the warmer caches.
     *
     * @return list<string>
     */
    public static function phpBaseLines(string $version, string $server = 'frankenphp'): array
    {
        $repo = (string) config('edge.build.containers.php_base_repo', '');

        // The server is part of the identity — a swoole base and a frankenphp
        // base carry different binaries — but it is carried by the tag prefix,
        // not the digest. Hashing it too would change the default server's
        // existing tag and point every build at an unpublished image.
        return $repo !== ''
            ? ['FROM '.$repo.':'.self::baseTag($version, self::publishedPhpExtensions(), $server)]
            : self::phpBaseSourceLines($version, $server);
    }

    /**
     * Version plus a digest of what's baked in, e.g. `8.4-1f3c9ab2`.
     *
     * A version-only tag silently goes stale the moment someone edits the
     * extension list: builds keep pulling an image that no longer matches.
     * Hashing the contents means a changed list simply has no published image
     * yet (the build falls back to compiling) and old deployments keep
     * resolving to the exact base they were built against.
     */
    public static function baseTag(string $version, string $contents, string $server = ''): string
    {
        $prefix = ($server !== '' && $server !== 'frankenphp') ? $server.'-' : '';

        return $prefix.$version.'-'.substr(sha1($contents), 0, 8);
    }

    /**
     * What a generated Ruby image builds on — same deal as PHP: the apt layer
     * for native gem builds (pg, nokogiri) is the slow part worth publishing.
     *
     * @return list<string>
     */
    public static function rubyBaseLines(string $version): array
    {
        $repo = (string) config('edge.build.containers.ruby_base_repo', '');

        return $repo !== ''
            ? ['FROM '.$repo.':'.self::baseTag($version, self::RUBY_PACKAGES)]
            : self::rubyBaseSourceLines($version);
    }

    /** @return list<string> */
    public static function rubyBaseSourceLines(string $version): array
    {
        return [
            "FROM ruby:{$version}-slim",
            'RUN apt-get update -qq && apt-get install -y -qq --no-install-recommends '
                .self::RUBY_PACKAGES.' && apt-get clean && rm -rf /var/lib/apt/lists/*',
        ];
    }

    /**
     * The upstream image plus the extension compile — what the publish command
     * bakes into `php_base_repo` and what the warmer pre-builds.
     *
     * @return list<string>
     */
    public static function phpBaseSourceLines(string $version, string $server = 'frankenphp'): array
    {
        return match ($server) {
            // Octane servers run PHP directly, so they start from the official
            // CLI image rather than a web server: swoole IS the server.
            'swoole' => [
                "FROM php:{$version}-cli-alpine",
                'COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/',
                self::installPhpExtensions(self::PHP_EXTENSIONS.' swoole'),
            ],
            'roadrunner' => [
                "FROM php:{$version}-cli-alpine",
                'COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/',
                self::installPhpExtensions(self::PHP_EXTENSIONS.' sockets'),
                'COPY --from=ghcr.io/roadrunner-server/roadrunner:latest /usr/bin/rr /usr/local/bin/rr',
            ],
            // nginx fronts php-fpm; s6/supervisor would be another moving part,
            // so nginx runs in the foreground and php-fpm daemonizes behind it.
            'fpm' => [
                "FROM php:{$version}-fpm-alpine",
                'COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/',
                self::installPhpExtensions(self::PHP_EXTENSIONS),
                'RUN apk add --no-cache nginx && mkdir -p /run/nginx',
            ],
            default => [
                'FROM '.sprintf(self::PHP_BASE_IMAGE, $version),
                self::installPhpExtensions(self::PHP_EXTENSIONS),
            ],
        };
    }

    /**
     * Which server a repo should run under.
     *
     * Octane is detected, never assumed: its servers keep state between
     * requests, so silently selecting one would change how an app behaves.
     * Everything else runs php-fpm behind nginx. A resident server does not
     * fit the 1 GiB floor we give PHP apps.
     *
     * @param  array<string, mixed>  $composer  decoded composer.json
     */
    public static function detectPhpServer(array $composer): string
    {
        $require = array_change_key_case(array_merge(
            is_array($composer['require'] ?? null) ? $composer['require'] : [],
            is_array($composer['require-dev'] ?? null) ? $composer['require-dev'] : [],
        ));

        if (! array_key_exists('laravel/octane', $require)) {
            return 'fpm';
        }

        foreach (array_keys($require) as $package) {
            if (str_starts_with((string) $package, 'spiral/roadrunner')) {
                return 'roadrunner';
            }
        }

        return 'swoole';
    }

    /**
     * Every ext-* the root package or a locked production dependency requires
     * that the base image does not already ship. Installed before
     * `composer install` so the platform check passes.
     *
     * @param  array<string, mixed>  $composer
     * @param  array<string, mixed>  $lock
     */
    public static function extraPhpExtensions(array $composer, array $lock = []): string
    {
        $baked = array_fill_keys(preg_split('/\s+/', self::publishedPhpExtensions()) ?: [], true);
        foreach (self::BUNDLED_PHP_EXTENSIONS as $bundled) {
            $baked[$bundled] = true;
        }
        $requires = [];
        if (is_array($composer['require'] ?? null)) {
            $requires[] = $composer['require'];
        }
        foreach (is_array($lock['packages'] ?? null) ? $lock['packages'] : [] as $package) {
            if (is_array($package) && is_array($package['require'] ?? null)) {
                $requires[] = $package['require'];
            }
        }

        $extra = [];
        foreach ($requires as $require) {
            foreach (array_keys($require) as $name) {
                $name = strtolower((string) $name);
                if (! str_starts_with($name, 'ext-')) {
                    continue;
                }
                $extension = substr($name, 4);
                if ($extension === '' || isset($baked[$extension]) || isset($extra[$extension])) {
                    continue;
                }
                $extra[$extension] = true;
            }
        }

        ksort($extra);

        return implode(' ', array_keys($extra));
    }

    /**
     * Extensions the published base image already contains. Empty cache keeps
     * the original PHP_EXTENSIONS string so the existing tag still resolves.
     */
    public const EXTRA_EXTENSIONS_CACHE_KEY = 'edge:php-base-extra-extensions';

    public static function publishedPhpExtensions(): string
    {
        $extra = trim((string) Cache::get(self::EXTRA_EXTENSIONS_CACHE_KEY, ''));
        if ($extra === '') {
            return self::PHP_EXTENSIONS;
        }

        $names = [];
        foreach (preg_split('/\s+/', self::PHP_EXTENSIONS.' '.$extra) ?: [] as $name) {
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $sorted = array_keys($names);
        sort($sorted);

        return implode(' ', $sorted);
    }

    public static function rememberExtraExtensions(string $extensions): void
    {
        $current = trim((string) Cache::get(self::EXTRA_EXTENSIONS_CACHE_KEY, ''));
        $names = [];
        foreach (preg_split('/\s+/', trim($current.' '.$extensions)) ?: [] as $name) {
            if ($name !== '') {
                $names[$name] = true;
            }
        }
        $sorted = array_keys($names);
        sort($sorted);
        Cache::forever(self::EXTRA_EXTENSIONS_CACHE_KEY, implode(' ', $sorted));
    }

    /**
     * @return array{version: string, server: string}
     */
    public static function phpIdentity(string $checkout): array
    {
        $composer = json_decode((string) file_get_contents($checkout.'/composer.json'), true);
        $composer = is_array($composer) ? $composer : [];
        $platform = (string) ($composer['config']['platform']['php'] ?? '');
        $version = $platform !== ''
            ? (preg_match('/(8\.\d)/', $platform, $m) === 1 ? $m[1] : self::PHP_DEFAULT_MAX)
            : self::newestAllowedPhp((string) ($composer['require']['php'] ?? ''));

        return ['version' => $version, 'server' => self::detectPhpServer($composer)];
    }

    /**
     * install-php-extensions always prints #StandWithUkraine. Drop that line
     * and keep the installer's exit code.
     */
    private static function composerRequires(string $checkout, string $package): bool
    {
        $composer = json_decode((string) file_get_contents($checkout.'/composer.json'), true);

        return is_array($composer) && isset($composer['require'][$package]);
    }

    /**
     * Copies the in-repo package into the build context so the image can
     * require it from a path repository. The app's composer.json is untouched.
     */
    private static function stageLaravelPackage(string $checkout): void
    {
        $source = base_path('packages/laravel-dply');
        $dest = $checkout.'/dply-laravel';
        if (! is_dir($source)) {
            throw new RuntimeException('dply/laravel is not available to inject into this image.');
        }
        if (is_dir($dest)) {
            return;
        }
        mkdir($dest.'/src', 0775, true);
        copy($source.'/composer.json', $dest.'/composer.json');
        foreach (glob($source.'/src/*.php') ?: [] as $file) {
            copy($file, $dest.'/src/'.basename($file));
        }
    }

    private static function installPhpExtensions(string $extensions): string
    {
        return 'RUN install-php-extensions '.$extensions
            .' > /tmp/ipe.log 2>&1; code=$?; grep -v StandWithUkraine /tmp/ipe.log || true; rm -f /tmp/ipe.log; exit $code';
    }

    /**
     * @return array{path: string, port: int, stack: string, generated: bool, server: string}
     */
    public static function prepare(string $checkout, bool $injectLaravel = false): array
    {
        $default = (int) config('edge.build.containers.default_port', 8080);

        if (is_file($checkout.'/Dockerfile')) {
            return [
                'path' => $checkout.'/Dockerfile',
                'port' => self::exposedPort((string) file_get_contents($checkout.'/Dockerfile')) ?? $default,
                'stack' => 'dockerfile',
                'generated' => false,
                'server' => '',
            ];
        }

        [$stack, $contents] = match (true) {
            is_file($checkout.'/composer.json') => ['php', self::php($checkout, $injectLaravel)],
            is_file($checkout.'/Gemfile') => ['ruby', self::ruby($checkout)],
            is_file($checkout.'/package.json') => ['node', self::node($checkout)],
            default => throw new RuntimeException('Container sites need a Dockerfile, composer.json (PHP), Gemfile (Ruby) or package.json (Node) at the repository root.'),
        };

        file_put_contents($checkout.'/Dockerfile.dply', $contents);

        $server = '';
        if ($stack === 'php') {
            $composer = json_decode((string) file_get_contents($checkout.'/composer.json'), true);
            $server = self::detectPhpServer(is_array($composer) ? $composer : []);
        }

        return ['path' => $checkout.'/Dockerfile.dply', 'port' => 8080, 'stack' => $stack, 'generated' => true, 'server' => $server];
    }

    /**
     * The lines an operator needs in the build log: base images and the
     * commands that install dependencies and compile assets.
     */
    public static function logSummary(string $dockerfile): string
    {
        $kept = [];
        foreach (preg_split('/\r?\n/', $dockerfile) ?: [] as $line) {
            $trim = trim($line);
            if (preg_match('/^(FROM|RUN|COPY --from)\b/', $trim) === 1) {
                $kept[] = $trim;
            }
        }

        return $kept === [] ? '' : "Image steps:\n".implode("\n", $kept)."\n";
    }

    /** First `EXPOSE` port in a Dockerfile, if any. */
    public static function exposedPort(string $dockerfile): ?int
    {
        return preg_match('/^\s*EXPOSE\s+(\d+)/mi', $dockerfile, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Highest supported PHP the `require.php` constraint allows.
     *
     * `^8.2` means "8.2 or newer", so pinning 8.2 gave a repo a needlessly old
     * runtime — and missed the warmed layer cache for the newest tag. Floors
     * come from the highest `8.x` named; a `<8.x` bound or a minor-locking
     * form (`8.2.*`, `~8.2.0`) caps it there.
     *
     * ponytail: string matching, not a semver engine — no composer/semver in
     * this app. Swap in Semver::satisfies() if constraints get exotic.
     */
    private static function newestAllowedPhp(string $constraint): string
    {
        $cap = preg_match('/<\s*8\.(\d)/', $constraint, $m) === 1
            ? (int) $m[1] - 1                       // `<8.4` → at most 8.3
            : 9;

        // The upper bound's own digit must not count as a floor: in
        // `^8.2 <8.4` the floor is 8.2, not 8.4.
        preg_match_all('/8\.(\d)/', (string) preg_replace('/<\s*8\.\d+(\.\d+)?/', '', $constraint), $found);
        if ($found[1] === []) {
            return self::PHP_DEFAULT_MAX;
        }

        $floor = max(array_map('intval', $found[1]));
        if (preg_match('/(~\s*8\.\d+\.|8\.\d+\.\*|8\.\d+\.x)/', $constraint) === 1) {
            $cap = min($cap, $floor);               // `~8.2.0` / `8.2.*` lock the minor
        } else {
            // Open-ended: don't hand out a newer PHP than the ecosystem
            // expects. A floor above the default still wins (`^8.5` → 8.5).
            $cap = min($cap, max($floor, (int) explode('.', self::PHP_DEFAULT_MAX)[1]));
        }

        foreach (self::PHP_VERSIONS as $candidate) {
            $minor = (int) explode('.', $candidate)[1];
            if ($minor >= $floor && $minor <= $cap) {
                return $candidate;
            }
        }

        return '8.'.$floor;
    }

    /**
     * Node stage that runs the repo's own frontend install and build.
     * Lockfiles are copied only when they exist — a glob that matches
     * nothing fails the build, and `npm ci` cannot run without one.
     *
     * @param  array{install: string, build: string, workspaces?: list<string>}|null  $assets
     * @return list<string>
     */
    private static function assetStageLines(string $checkout, ?array $assets): array
    {
        if ($assets === null) {
            return [];
        }

        $install = $assets['install'];
        if (preg_match('/^(pnpm|yarn)\s/', $install) === 1) {
            $install = 'corepack enable && '.$install;
        }

        $manifests = ['package.json'];
        foreach (['package-lock.json', 'pnpm-lock.yaml', 'yarn.lock'] as $lock) {
            if (is_file($checkout.'/'.$lock)) {
                $manifests[] = $lock;
            }
        }

        $lines = [
            'FROM node:22-bookworm-slim AS assets',
            'WORKDIR /app',
            'COPY '.implode(' ', $manifests).' ./',
        ];
        foreach ($assets['workspaces'] ?? [] as $dir) {
            $lines[] = 'COPY '.$dir.'/package.json '.$dir.'/package.json';
        }
        if (is_dir($checkout.'/patches')) {
            $lines[] = 'COPY patches patches';
        }
        $lines[] = self::cachedRun($install, self::nodeCacheDir($install));
        $lines[] = 'COPY . .';
        $lines[] = 'RUN '.$assets['build'];
        $lines[] = '';

        return $lines;
    }

    private static function cachedRun(string $command, string $cacheDir): string
    {
        return 'RUN --mount=type=cache,target='.$cacheDir.' '.$command;
    }

    private static function nodeCacheDir(string $install): string
    {
        return match (true) {
            str_contains($install, 'pnpm') => '/pnpm/store',
            str_contains($install, 'yarn') => '/usr/local/share/.cache/yarn',
            default => '/root/.npm',
        };
    }

    private static function php(string $checkout, bool $injectLaravel = false): string
    {
        $composer = json_decode((string) file_get_contents($checkout.'/composer.json'), true);
        $identity = self::phpIdentity($checkout);
        $version = $identity['version'];
        $server = $identity['server'];
        $laravel = is_file($checkout.'/artisan');
        $assets = FrontendAssetBuild::stepsForDirectory($checkout);
        // Inertia SSR: build the server bundle too and run it next to PHP.
        $ssr = $laravel && $assets !== null ? FrontendAssetBuild::inertiaSsrBuild($checkout) : null;
        if ($ssr !== null) {
            $assets['build'] = $ssr;
        }

        $lines = self::assetStageLines($checkout, $assets);
        foreach (self::phpBaseLines($version, $server) as $line) {
            $lines[] = $line;
        }
        $lines[] = 'COPY --from=composer:2 /usr/bin/composer /usr/bin/composer';
        $lines[] = 'WORKDIR /app';
        $lock = is_file($checkout.'/composer.lock')
            ? json_decode((string) file_get_contents($checkout.'/composer.lock'), true)
            : [];
        $extraExtensions = self::extraPhpExtensions(is_array($composer) ? $composer : [], is_array($lock) ? $lock : []);
        if ($extraExtensions !== '') {
            $lines[] = 'COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/';
            $lines[] = self::installPhpExtensions($extraExtensions);
        }
        if ($ssr !== null) {
            $lines[] = 'RUN apk add --no-cache nodejs';
        }
        // Dependencies before code: this layer survives every deploy that
        // doesn't touch composer.json/lock, so a code-only change skips the
        // whole vendor install. --no-scripts/--no-autoloader because Laravel's
        // post-install hooks need artisan, which arrives with the next COPY.
        $lines[] = is_file($checkout.'/composer.lock')
            ? 'COPY composer.json composer.lock ./'
            : 'COPY composer.json ./';
        if ($injectLaravel && $laravel && ! self::composerRequires($checkout, 'dply/laravel')) {
            self::stageLaravelPackage($checkout);
            $lines[] = 'COPY dply-laravel /opt/dply/laravel';
            $lines[] = self::cachedRun('composer config repositories.dply \'{"type":"path","url":"/opt/dply/laravel","options":{"symlink":false}}\' && composer require dply/laravel:^1.0 --no-dev --no-interaction --no-progress --no-scripts --no-plugins --no-install && composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader', '/root/.composer/cache');
        } else {
            $lines[] = self::cachedRun('composer install --no-dev --no-interaction --no-progress --no-scripts --no-autoloader', '/root/.composer/cache');
        }
        $lines[] = 'COPY . .';
        if ($assets !== null) {
            $lines[] = 'COPY --from=assets /app/public /app/public';
        }
        if ($ssr !== null) {
            // ponytail: the whole node_modules, since Vite leaves SSR deps
            // external. Prune to production deps if image size starts to hurt.
            $lines[] = 'COPY --from=assets /app/bootstrap/ssr /app/bootstrap/ssr';
            $lines[] = 'COPY --from=assets /app/node_modules /app/node_modules';
        }
        // The Worker terminates TLS. Trust its X-Forwarded-Proto so Laravel
        // generates https asset URLs instead of mixed-content http links.
        if ($server === 'frankenphp') {
            $lines[] = 'ENV CADDY_GLOBAL_OPTIONS="servers { trusted_proxies static 0.0.0.0/0 ::/0 }"';
        }
        $publish = implode(' && ', FrontendAssetBuild::phpAssetCommands(is_array($composer) ? $composer : []));
        $lines[] = 'RUN composer dump-autoload --no-dev --optimize && composer run-script post-autoload-dump --no-interaction'
            .($publish !== '' ? ' && '.$publish : '')
            .' || true';
        // Image default off; the deploy injects the site's setting.
        $lines[] = 'ENV SERVER_NAME=":8080" DPLY_MIGRATE_ON_BOOT=0';
        if ($laravel) {
            // php-fpm runs as www-data. 775 owned by root makes Blade's compiled
            // views fail with tempnam() and the welcome page 500s.
            $own = $server === 'fpm' ? ' && chown -R www-data:www-data storage bootstrap/cache' : '';
            $lines[] = 'RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache'.$own.' && chmod -R 775 storage bootstrap/cache';
            $lines[] = 'ENV LOG_CHANNEL=stderr';
        }
        if ($server === 'fpm') {
            // nginx on 8080, PHP on 127.0.0.1:9000. The pool is written at
            // start from DPLY_PHP_FPM_* so a bigger instance gets more children
            // without a rebuild. ondemand so a cold boot does not pre-fork
            // itself out of memory.
            // Cloudflare containers cannot open /dev/stdout or /proc/self/fd/2
            // (ENXIO), and php-fpm's docker.conf points the master log there.
            // Both logs go to files so the process does not exit on startup.
            $conf = 'pid /tmp/nginx.pid; error_log /tmp/nginx-error.log; events {} http { include /etc/nginx/mime.types; access_log off; '
                .'client_body_temp_path /tmp/client_body; fastcgi_temp_path /tmp/fastcgi; '
                .'server { listen 0.0.0.0:8080; root /app/public; index index.php; '
                .'location / { try_files $uri $uri/ /index.php?$query_string; } '
                .'location ~ \\.php$ { fastcgi_pass 127.0.0.1:9000; fastcgi_index index.php; include fastcgi_params; '
                .'fastcgi_param HTTPS on; fastcgi_param HTTP_X_FORWARDED_PROTO https; '
                .'fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; } } }';
            // The stock docker.conf logs to /proc/self/fd/2, which Cloudflare
            // cannot open. Replace it in the image, and start php-fpm from a
            // /tmp pool so that file is never loaded.
            $lines[] = 'RUN printf %s '.escapeshellarg($conf).' > /etc/nginx/nginx.conf'
                .' && printf %s '.escapeshellarg('[global]\nerror_log = /tmp/php-fpm.log\nlog_limit = 8192\n').' > /usr/local/etc/php-fpm.d/docker.conf';
        }

        $lines[] = 'EXPOSE 8080';
        // Each server starts differently; only FrankenPHP has `frankenphp run`.
        $start = match ($server) {
            'swoole' => 'exec php artisan octane:start --server=swoole --host=0.0.0.0 --port=8080',
            'roadrunner' => 'exec php artisan octane:start --server=roadrunner --host=0.0.0.0 --port=8080 --rr-config=.rr.yaml',
            'fpm' => 'children="${DPLY_PHP_FPM_MAX_CHILDREN:-2}"; limit="${DPLY_PHP_MEMORY_LIMIT:-128M}"; mkdir -p /tmp/views /tmp/client_body /tmp/fastcgi; chmod 1777 /tmp/views /tmp/client_body /tmp/fastcgi; export VIEW_COMPILED_PATH=/tmp/views; printf "[global]\npid = /tmp/php-fpm.pid\nerror_log = /tmp/php-fpm.log\ndaemonize = no\n[www]\nuser = www-data\ngroup = www-data\nlisten = 127.0.0.1:9000\npm = ondemand\npm.max_children = %s\npm.process_idle_timeout = 10s\npm.max_requests = 500\nclear_env = no\n" "$children" > /tmp/php-fpm.conf; php-fpm -F -y /tmp/php-fpm.conf -d "memory_limit=$limit" -d opcache.enable=1 -d opcache.memory_consumption=64 -d opcache.max_accelerated_files=10000 & nginx -g "daemon off;"',
            default => 'exec frankenphp run --config /etc/frankenphp/Caddyfile',
        };
        if ($ssr !== null) {
            // Inertia's default SSR URL is http://127.0.0.1:13714, which this serves.
            $start = 'php artisan inertia:start-ssr & '.$start;
        }
        $sqlite = 'if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "$DB_DATABASE" ]; then mkdir -p "$(dirname "$DB_DATABASE")"; if [ "$DPLY_SQLITE_SYNC" = "1" ]; then php -r \'@copy("http://sqlite.dply/db", getenv("DB_DATABASE"));\'; fi; [ -f "$DB_DATABASE" ] || touch "$DB_DATABASE"; chmod 666 "$DB_DATABASE"; if [ "$DPLY_SQLITE_SYNC" = "1" ]; then ( while true; do php -r \'$p=getenv("DB_DATABASE"); if(!is_file($p)) exit; $b=file_get_contents($p); $c=stream_context_create(["http"=>["method"=>"PUT","header"=>"Content-Type: application/octet-stream\r\n","content"=>$b,"timeout"=>60]]); @file_get_contents("http://sqlite.dply/db", false, $c);\' ; sleep 20; done ) & fi; fi; ';
        $boot = $laravel
            ? $sqlite.'if [ "$DPLY_MIGRATE_ON_BOOT" = "1" ]; then php artisan migrate --force --isolated || true; fi; '.$start
            : $start;
        $lines[] = 'CMD ["sh", "-c", '.json_encode($boot, JSON_UNESCAPED_SLASHES).']';

        return implode("\n", $lines)."\n";
    }

    /**
     * Node HTTP server: dev dependencies for the build, production install
     * for the image, `npm start` listening on $PORT.
     */
    private static function node(string $checkout): string
    {
        $major = app(NodeVersionDetector::class)->detect($checkout)['major'];
        $install = match (true) {
            is_file($checkout.'/pnpm-lock.yaml') => 'corepack enable && pnpm install --frozen-lockfile',
            is_file($checkout.'/yarn.lock') => 'corepack enable && yarn install --frozen-lockfile',
            is_file($checkout.'/package-lock.json') => 'npm ci',
            default => 'npm install',
        };
        $start = match (true) {
            is_file($checkout.'/pnpm-lock.yaml') => 'pnpm start',
            is_file($checkout.'/yarn.lock') => 'yarn start',
            default => 'npm start',
        };

        $assets = FrontendAssetBuild::stepsForDirectory($checkout);
        $run = $assets === null ? $install : $install.' && '.$assets['build'];

        return implode("\n", [
            "FROM node:{$major}-bookworm-slim",
            'WORKDIR /app',
            'COPY . .',
            self::cachedRun($run, self::nodeCacheDir($run)),
            'ENV NODE_ENV=production PORT=8080 HOST=0.0.0.0',
            'EXPOSE 8080',
            'CMD ["sh", "-c", '.json_encode("exec {$start}", JSON_UNESCAPED_SLASHES).']',
        ])."\n";
    }

    private static function ruby(string $checkout): string
    {
        $version = '3.3';
        if (is_file($checkout.'/.ruby-version') && preg_match('/(\d+\.\d+)/', (string) file_get_contents($checkout.'/.ruby-version'), $m) === 1) {
            $version = $m[1];
        }
        $rails = is_file($checkout.'/config/application.rb');
        $assets = FrontendAssetBuild::stepsForDirectory($checkout);

        $lines = [
            ...self::assetStageLines($checkout, $assets),
            ...self::rubyBaseLines($version),
            'WORKDIR /app',
            'ENV RAILS_ENV=production RACK_ENV=production BUNDLE_WITHOUT="development:test" RAILS_LOG_TO_STDOUT=1 RAILS_SERVE_STATIC_FILES=1 PORT=8080 DPLY_MIGRATE_ON_BOOT=0',
            is_file($checkout.'/Gemfile.lock') ? 'COPY Gemfile Gemfile.lock ./' : 'COPY Gemfile ./',
            self::cachedRun('bundle install --jobs 4', '/usr/local/bundle/cache'),
            'COPY . .',
        ];
        if ($assets !== null) {
            $lines[] = 'COPY --from=assets /app/public /app/public';
        }
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
