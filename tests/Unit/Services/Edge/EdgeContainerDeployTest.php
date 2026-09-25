<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeContainerDeployTest;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Services\Containers\EdgeContainerRollout;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

function checkout(array $files): string
{
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($dir.'/'.$path));
        File::put($dir.'/'.$path, $contents);
    }

    return $dir;
}

afterEach(function () {
    Cache::forget(EdgeContainerDockerfile::EXTRA_EXTENSIONS_CACHE_KEY);
    collect(glob(sys_get_temp_dir().'/dply-container-test-*'))->each(fn ($d) => File::deleteDirectory($d));
});

test('a repo Dockerfile wins and its EXPOSE port is used', function () {
    $dir = checkout(['Dockerfile' => "FROM ruby:3.3\nEXPOSE 3000\n", 'Gemfile' => '']);

    expect(EdgeContainerDockerfile::prepare($dir))->toMatchArray(['path' => $dir.'/Dockerfile', 'port' => 3000, 'generated' => false]);
});

test('laravel gets a php-fpm image with assets, migrations on boot and port 8080', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'artisan' => '',
        'package.json' => '{"scripts":{"build":"vite build"}}',
    ]);

    $image = EdgeContainerDockerfile::prepare($dir);
    $dockerfile = File::get($image['path']);

    expect($image)->toMatchArray(['stack' => 'php', 'port' => 8080, 'generated' => true])
        ->and($dockerfile)->toContain('FROM node:22-bookworm-slim AS assets')
        // `^8.3` allows anything newer, so we take the newest tag we support —
        // an older pin wastes the warmed layer cache. See newestAllowedPhp().
        ->and($dockerfile)->toContain('FROM php:8.4-fpm-alpine')
        ->and($dockerfile)->toContain('pm = ondemand')
        ->and($dockerfile)->toContain('php-fpm -F -y /tmp/php-fpm.conf')
        // nginx must not open 8080 before php-fpm listens, or cold starts 502.
        ->and(strpos($dockerfile, 'fsockopen(\\"127.0.0.1\\", 9000)'))->toBeInt()->toBeLessThan(strpos($dockerfile, 'exec nginx'))
        ->and($dockerfile)->toContain('pid /tmp/nginx.pid')
        ->and($dockerfile)->toContain('pid = /tmp/php-fpm.pid')
        ->and($dockerfile)->toContain('VIEW_COMPILED_PATH=/tmp/views')
        ->and($dockerfile)->toContain('chmod 1777 /tmp/views /tmp/client_body /tmp/fastcgi')
        ->and($dockerfile)->toContain('error_log /tmp/nginx-error.log')
        ->and($dockerfile)->toContain('/tmp/php-fpm.log')
        ->and($dockerfile)->toContain('php-fpm.d/docker.conf')
        ->and($dockerfile)->toContain('chown -R www-data:www-data storage bootstrap/cache')
        ->and($dockerfile)->not->toContain("\nerror_log")
        ->and($dockerfile)->not->toContain('/dev/stdout')
        ->and($dockerfile)->not->toContain('error_log /dev/stderr')
        ->and($dockerfile)->toContain('composer install --no-dev')
        ->and($dockerfile)->toContain('http://sqlite.dply/db')
        ->and($dockerfile)->toContain('chmod 666')
        ->and($dockerfile)->not->toContain('DPLY_MIGRATE_ON_BOOT" = "1" ]; then if [ "$DB_CONNECTION" = "sqlite"')
        ->and($dockerfile)->toContain('php artisan migrate --force --isolated')
        ->and($dockerfile)->toContain('RUN npm run build')
        ->and($dockerfile)->toContain('SERVER_NAME=":8080"')
        ->and(EdgeContainerDockerfile::logSummary($dockerfile))->toContain('RUN npm run build');
});

test('a published base that already has the extension is reused', function () {
    config(['edge.build.containers.php_base_repo' => 'ghcr.io/example/edge-php']);
    EdgeContainerDockerfile::rememberExtraExtensions('gd');

    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.2","ext-gd":"*","ext-exif":"*"},"config":{"platform":{"php":"8.2.12"}}}',
        'artisan' => '',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);
    $tag = EdgeContainerDockerfile::baseTag('8.2', EdgeContainerDockerfile::publishedPhpExtensions(), 'fpm');

    expect($dockerfile)->toContain('FROM ghcr.io/example/edge-php:'.$tag)
        ->and($dockerfile)->toContain('install-php-extensions exif')
        ->and($dockerfile)->not->toContain('install-php-extensions exif gd')
        ->and($dockerfile)->not->toContain('install-php-extensions gd');
});

test('a required php extension missing from the base image is installed before composer', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.2","ext-gd":"*"}}',
        'composer.lock' => '{"packages":[{"name":"some/lib","require":{"ext-exif":"*","ext-intl":"*","ext-ctype":"*","ext-mbstring":"*"}}]}',
        'artisan' => '',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);
    $install = strpos($dockerfile, 'RUN install-php-extensions exif gd');
    $composer = strpos($dockerfile, 'composer install --no-dev');

    expect($install)->not->toBeFalse()
        ->and($composer)->not->toBeFalse()
        ->and($install)->toBeLessThan($composer)
        ->and($dockerfile)->not->toContain('install-php-extensions exif gd intl')
        ->and($dockerfile)->not->toContain(' ctype')
        ->and($dockerfile)->not->toContain(' mbstring')
        ->and($dockerfile)->toContain('--mount=type=cache,target=/root/.composer/cache')
        ->and($dockerfile)->toContain('grep -v StandWithUkraine');
});

test('an explicit platform pin is honoured instead of the newest supported php', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.2"},"config":{"platform":{"php":"8.2.12"}}}',
        'artisan' => '',
    ]);

    expect(File::get(EdgeContainerDockerfile::prepare($dir)['path']))
        ->toContain('FROM php:8.2-fpm-alpine')
        ->not->toContain('AS assets');   // no package.json, no node stage
});

test('rails gets a puma image with db:prepare on boot', function () {
    $dir = checkout(['Gemfile' => "gem 'rails'", '.ruby-version' => '3.2.4', 'config/application.rb' => '']);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('FROM ruby:3.2-slim')
        ->and($dockerfile)->toContain('rails assets:precompile')
        ->and($dockerfile)->toContain('rails db:prepare')
        ->and($dockerfile)->toContain('puma -b tcp://0.0.0.0:8080');
});

test('a node server gets npm start on port 8080 with the lockfile installer', function () {
    $dir = checkout(['package.json' => '{"engines":{"node":"20"},"scripts":{"start":"node server.js"}}', 'pnpm-lock.yaml' => '']);

    $image = EdgeContainerDockerfile::prepare($dir);
    $dockerfile = File::get($image['path']);

    expect($image['stack'])->toBe('node')
        ->and($dockerfile)->toContain('FROM node:20-bookworm-slim')
        ->and($dockerfile)->toContain('pnpm install --frozen-lockfile')
        ->and($dockerfile)->toContain('PORT=8080')
        ->and($dockerfile)->toContain('exec pnpm start');
});

test('php assets follow composer and package.json instead of a hardcoded npm build', function () {
    $dir = checkout([
        'composer.json' => json_encode([
            'require' => ['php' => '^8.3'],
            'scripts' => [
                'setup' => [
                    'composer install',
                    '@php artisan migrate --force',
                    'npm install',
                    'npm run production',
                ],
            ],
        ]),
        'artisan' => '',
        'package.json' => json_encode(['scripts' => ['production' => 'mix --production', 'dev' => 'mix']]),
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('FROM node:22-bookworm-slim AS assets')
        ->and($dockerfile)->toContain('--mount=type=cache,target=/root/.npm npm install')
        ->and($dockerfile)->toContain('RUN npm run production')
        ->and($dockerfile)->not->toContain('npm run build --if-present')
        ->and($dockerfile)->not->toContain('package-lock.json*')
        ->and($dockerfile)->not->toContain('@php artisan migrate');
});

test('a php app compiles the workspace vite package into public', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'artisan' => '',
        'package.json' => '{"workspaces":["resources/assets/v3"]}',
        'package-lock.json' => '{}',
        'resources/assets/v3/package.json' => '{"scripts":{"build":"vite build"}}',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('FROM node:22-bookworm-slim AS assets')
        ->and($dockerfile)->toContain('COPY package.json package-lock.json ./')
        ->and($dockerfile)->toContain('COPY resources/assets/v3/package.json resources/assets/v3/package.json')
        ->and($dockerfile)->toContain('RUN --mount=type=cache,target=/root/.npm npm ci')
        ->and($dockerfile)->toContain('RUN npm run build --prefix resources/assets/v3')
        ->and($dockerfile)->toContain('COPY --from=assets /app/public /app/public');
});

test('an inertia app with build:ssr builds and runs the ssr server', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'artisan' => '',
        'package.json' => '{"scripts":{"build":"vite build","build:ssr":"vite build && vite build --ssr"},"dependencies":{"@inertiajs/vue3":"^2"}}',
        'resources/js/ssr.ts' => '',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('RUN npm run build:ssr')
        ->and($dockerfile)->toContain('RUN apk add --no-cache nodejs')
        ->and($dockerfile)->toContain('COPY --from=assets /app/bootstrap/ssr /app/bootstrap/ssr')
        ->and($dockerfile)->toContain('php artisan inertia:start-ssr & ');
});

test('an app that imports ziggy from vendor gets composer vendor in the asset build', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3","tightenco/ziggy":"^2.4"}}',
        'artisan' => '',
        'package.json' => '{"scripts":{"build":"vite build"}}',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('FROM composer:2 AS vendor')
        ->and(strpos($dockerfile, 'COPY --from=vendor /app/vendor vendor'))->toBeLessThan(strpos($dockerfile, 'RUN npm run build'));
});

test('build:ssr with inertia but no ssr entry is left alone', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'artisan' => '',
        'package.json' => '{"scripts":{"build":"vite build","build:ssr":"vite build && vite build --ssr"},"dependencies":{"@inertiajs/vue3":"^2"}}',
        'vite.config.ts' => "laravel({ input: ['resources/js/app.ts'] })",
    ]);

    expect(File::get(EdgeContainerDockerfile::prepare($dir)['path']))->not->toContain('inertia:start-ssr')
        ->and(File::get(EdgeContainerDockerfile::prepare($dir)['path']))->toContain('RUN npm run build');
});

test('build:ssr without inertia is left alone', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'artisan' => '',
        'package.json' => '{"scripts":{"build":"vite build","build:ssr":"vite build --ssr"}}',
    ]);

    expect(File::get(EdgeContainerDockerfile::prepare($dir)['path']))->not->toContain('inertia:start-ssr')
        ->and(File::get(EdgeContainerDockerfile::prepare($dir)['path']))->not->toContain('build:ssr');
});

test('a pnpm php app compiles assets with pnpm', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'artisan' => '',
        'package.json' => '{"scripts":{"build":"vite build"}}',
        'pnpm-lock.yaml' => '',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('corepack enable && pnpm install --frozen-lockfile')
        ->and($dockerfile)->toContain('RUN pnpm run build')
        ->and($dockerfile)->toContain('COPY --from=assets /app/public /app/public');
});

test('rails compiles the package.json asset script before precompile', function () {
    $dir = checkout([
        'Gemfile' => "gem 'rails'",
        'config/application.rb' => '',
        'package.json' => '{"scripts":{"build":"vite build"}}',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('FROM node:22-bookworm-slim AS assets')
        ->and($dockerfile)->toContain('RUN npm run build')
        ->and($dockerfile)->toContain('COPY --from=assets /app/public /app/public')
        ->and($dockerfile)->toContain('rails assets:precompile');
});

test('platform sqlite is stored through the worker and served by one instance', function () {
    config(['edge.r2.bucket' => 'edge-artifacts']);
    $site = new Site;
    $site->id = '01SITEABC';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, [], [], '', true);

    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');

    expect($config['r2_buckets'][0])->toBe(['binding' => 'SQLITE', 'bucket_name' => 'edge-artifacts'])
        ->and($worker)->toContain('const INSTANCES = 1')
        ->and($worker)->toContain('sqlite.dply')
        ->and($worker)->toContain('sites/01SITEABC/sqlite/database.sqlite');
});

test('an unrecognised repo without a Dockerfile is refused', function () {
    EdgeContainerDockerfile::prepare(checkout(['index.html' => 'hi']));
})->throws(\RuntimeException::class, 'Container sites need a Dockerfile');

test('the generated worker project wires the container, queues and the token-guarded endpoints', function () {
    config(['edge.build.containers.max_instances' => 3]);
    $site = new Site;
    $site->id = '01SITEABC';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/build/src/Dockerfile.dply', 8080, ['JOBS' => 'site-jobs']);

    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');

    expect($config['name'])->toBe('dply-ctr-01siteabc')
        ->and($config['containers'][0])->toMatchArray(['class_name' => 'App', 'image' => '/build/src/Dockerfile.dply', 'max_instances' => 3])
        ->and($config['migrations'][0]['new_sqlite_classes'])->toBe(['App'])
        ->and($config['queues']['producers'][0])->toBe(['binding' => 'JOBS', 'queue' => 'site-jobs'])
        ->and($config['queues']['consumers'][0]['queue'])->toBe('site-jobs')
        ->and($worker)->toContain("headers.set('x-forwarded-proto'")
        ->and($worker)->toContain("url.protocol = 'http:'")
        ->and($worker)->toContain("redirect: 'manual'")
        ->and($worker)->toContain('defaultPort = 8080')
        ->and($worker)->toContain('const INSTANCES = 3')
        ->and($worker)->toContain('const MIN_INSTANCES = 0')
        ->and($worker)->toContain('instance(env, i).hasRoom(i)')
        ->and($worker)->toContain("url.pathname === '/_dply/warm'")
        ->and($worker)->not->toContain('getRandom')
        ->and($worker)->toContain('const STICKY = true')
        ->and($worker)->toContain('const DEDICATED_JOBS = false')
        ->and($worker)->toContain("getContainer(env.APP, 'jobs')")
        ->and($worker)->toContain('startAndWaitForPorts')
        ->and($worker)->toContain('async function proxy(env, request, target)')
        ->and($worker)->toContain('portReadyTimeoutMS: 45000')
        ->and($worker)->toContain('return proxy(env, new Request(request, { headers }), await webTarget(env, request))')
        ->and($worker)->toContain('"/_dply/queue/send"')
        ->and($worker)->toContain("request.headers.get('x-dply-queue-token') !== env.DPLY_QUEUE_TOKEN")
        ->and($worker)->toContain('{"site-jobs":"JOBS"}')
        ->and($worker)->not->toContain('puppeteer')
        ->and($worker)->not->toContain('__');
});

test('a browser resource imports puppeteer and a site without one does not', function () {
    $site = new Site(['meta' => ['edge' => ['browser' => true]]]);
    $site->id = '01BROWSER';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);

    $worker = File::get($dir.'/src/index.js');
    $package = json_decode(File::get($dir.'/package.json'), true);
    File::deleteDirectory($dir);

    expect($worker)->toContain("import puppeteer from '@cloudflare/puppeteer';")
        ->and($worker)->toContain('puppeteer.launch(env.BROWSER)')
        ->and($package['dependencies'])->toHaveKey('@cloudflare/puppeteer');
});

test('the queue token is stable per site and differs between sites', function () {
    $a = new Site;
    $a->id = 'a';
    $b = new Site;
    $b->id = 'b';

    expect(EdgeContainerDeployer::queueToken($a))->toBe(EdgeContainerDeployer::queueToken($a))
        ->not->toBe(EdgeContainerDeployer::queueToken($b));
});

test('crons become cron triggers and a scheduled() handler posting to /_dply/schedule', function () {
    $site = new Site(['meta' => ['edge' => ['container' => ['scheduler' => true], 'crons_overrides' => [['schedule' => '0 3 * * *', 'handler' => 'reports:send']]]]]);
    $site->id = '01CRON';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    $crons = EdgeContainerDeployer::cronHandlers($site, null);
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, [], $crons);

    expect($crons)->toBe(['* * * * *' => ['schedule:run'], '0 3 * * *' => ['reports:send']])
        ->and(json_decode(File::get($dir.'/wrangler.jsonc'), true)['triggers'])->toBe(['crons' => ['* * * * *', '0 3 * * *']])
        ->and(File::get($dir.'/src/index.js'))->toContain('async scheduled(controller, env, ctx)')
        ->and(File::get($dir.'/src/index.js'))->toContain('"/_dply/schedule"')
        ->and(File::get($dir.'/src/index.js'))->toContain("url.pathname === '/_dply/command'");
});

test('previews enqueue but never consume queues or run crons', function () {
    $preview = new Site(['meta' => ['edge' => ['preview_parent_site_id' => '01PARENT', 'container' => ['scheduler' => true]]]]);
    $preview->id = '01PREVIEW';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $preview, '/x/Dockerfile', 8080, ['JOBS' => 'site-jobs'], EdgeContainerDeployer::cronHandlers($preview, null));
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);

    expect($config['queues'])->toBe(['producers' => [['binding' => 'JOBS', 'queue' => 'site-jobs']]])
        ->and($config)->not->toHaveKey('triggers');
});

test('a laravel site keeps the size the operator picked and the fpm pool fits that memory', function () {
    $site = new Site(['meta' => ['edge' => ['build' => ['framework' => 'laravel'], 'container' => ['instance_type' => 'lite']]]]);

    expect(EdgeContainerSettings::for($site)['instance_type'])->toBe('lite')
        ->and(EdgeContainerSettings::phpFpmPool('basic'))->toBe(['max_children' => 2, 'memory_limit' => '128M'])
        ->and(EdgeContainerSettings::phpFpmPool('lite'))->toBe(['max_children' => 1, 'memory_limit' => '128M'])
        ->and(EdgeContainerSettings::looksLikeMemoryCrash('php-fpm: Killed process'))->toBeTrue()
        ->and(EdgeContainerSettings::looksLikeMemoryCrash('SQLSTATE connection refused'))->toBeFalse();
});

test('a rollout at 0 percent is still in progress', function () {
    $rolling = EdgeContainerRollout::rolloutProgress([[
        'status' => 'in_progress',
        'progress' => ['version_distribution' => ['target_version_percentage' => 0]],
        'steps' => [['status' => 'in_progress']],
    ]]);
    $done = EdgeContainerRollout::rolloutProgress([[
        'status' => 'completed',
        'progress' => ['version_distribution' => ['target_version_percentage' => 100]],
        'steps' => [['status' => 'completed'], ['status' => 'completed']],
    ]]);

    $missingPercent = EdgeContainerRollout::rolloutProgress([['status' => 'in_progress', 'steps' => [['status' => 'in_progress']]]]);

    expect($rolling['in_progress'])->toBeTrue()
        ->and($rolling['percentage'])->toBe(0)
        ->and($done['in_progress'])->toBeFalse()
        ->and(EdgeContainerRollout::rolloutProgress([])['in_progress'])->toBeTrue()
        ->and($missingPercent['percentage'])->toBeNull()
        ->and(EdgeContainerRollout::healthSettled($rolling, ['starting' => 0], true))->toBeFalse()
        ->and(EdgeContainerRollout::healthSettled($missingPercent, ['starting' => 0], true))->toBeTrue()
        ->and(EdgeContainerRollout::healthSettled($missingPercent, ['starting' => 0], false))->toBeFalse();
});

test('a laravel app that needs the package gets dply/laravel in the image', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"}}',
        'artisan' => '',
    ]);
    $site = new Site;
    $site->meta = ['edge' => ['connections' => [[
        'kind' => 'key_value',
        'name' => 'FLAGS',
        'host' => 'flags.internal',
        'target' => 'ns-1',
    ]]]];

    expect(EdgeContainerDeployer::needsLaravelPackage($site, $dir))->toBeTrue();

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir, true)['path']);

    expect($dockerfile)->toContain('COPY dply-laravel /opt/dply/laravel')
        ->and($dockerfile)->toContain('composer require dply/laravel:^1.0')
        ->and(File::exists($dir.'/dply-laravel/src/DplyServiceProvider.php'))->toBeTrue()
        ->and(File::get(EdgeContainerDockerfile::prepare(checkout([
            'composer.json' => '{"require":{"php":"^8.3","dply/laravel":"^1.0"}}',
            'artisan' => '',
        ]), true)['path']))->not->toContain('COPY dply-laravel');
});

test('the first start uses only the instances asked for, and a later rollout keeps one spare', function () {
    expect(EdgeContainerSettings::wranglerMaxInstances(1))->toBe(1)
        ->and(EdgeContainerSettings::wranglerMaxInstances(1, false, true))->toBe(2)
        ->and(EdgeContainerSettings::wranglerMaxInstances(5, false, true))->toBe(6)
        ->and(EdgeContainerSettings::wranglerMaxInstances(1, true, true))->toBe(3);
});

test('a deploy fingerprint changes when resources change and stays put when they do not', function () {
    $same = EdgeContainerDeployer::deployFingerprint('abc', 'FROM php', '{}', '{"DB":"sqlite"}');

    expect($same)->toBe(EdgeContainerDeployer::deployFingerprint('abc', 'FROM php', '{}', '{"DB":"sqlite"}'))
        ->and($same)->not->toBe(EdgeContainerDeployer::deployFingerprint('abc', 'FROM php', '{}', '{"DB":"pgsql"}'))
        ->and($same)->not->toBe(EdgeContainerDeployer::deployFingerprint('def', 'FROM php', '{}', '{"DB":"sqlite"}'))
        ->and($same)->not->toBe(EdgeContainerDeployer::deployFingerprint('abc', 'FROM php', '{"sleep":"10m"}', '{"DB":"sqlite"}'));
});

test('min instances keep instances awake, never exceed max, and the worker parses', function () {
    $site = new Site(['meta' => ['edge' => ['container' => ['max_instances' => 3, 'min_instances' => 2]]]]);
    $site->id = '01AUTOSCALE';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    File::put($dir.'/src/check.mjs', $worker);
    $out = [];
    $code = 0;
    if (trim((string) shell_exec('command -v node')) !== '') { // CI may not have node
        exec('node --check '.escapeshellarg($dir.'/src/check.mjs').' 2>&1', $out, $code);
    }

    $over = new Site(['meta' => ['edge' => ['container' => ['max_instances' => 2, 'min_instances' => 9]]]]);

    expect($worker)->toContain('const MIN_INSTANCES = 2')
        ->and($worker)->toContain('const CAPACITY = 50')
        ->and($worker)->toContain('index < limits().min')
        ->and($code)->toBe(0, implode("\n", $out))
        ->and(EdgeContainerSettings::for($over)['min_instances'])->toBe(2);
});

test('the worker picks the most specific scaling window in its own time zone', function () {
    if (trim((string) shell_exec('command -v node')) === '') {
        $this->markTestSkipped('node is not installed');
    }
    $site = new Site(['meta' => ['edge' => ['container' => ['max_instances' => 2, 'min_instances' => 0, 'schedules' => [
        ['days' => 'daily', 'start' => '00:00', 'end' => '23:59', 'timezone' => 'UTC', 'min' => 1, 'max' => 3],
        ['days' => 'weekdays', 'start' => '09:00', 'end' => '17:00', 'timezone' => 'America/New_York', 'min' => 2, 'max' => 5],
        ['days' => 'fri', 'start' => '12:00', 'end' => '13:00', 'timezone' => 'America/New_York', 'min' => 4, 'max' => 9],
        ['days' => 'mon', 'start' => '17:00', 'end' => '09:00', 'timezone' => 'UTC', 'min' => 9, 'max' => 9], // overnight: dropped
        ['days' => '2026-09-25', 'start' => '12:00', 'end' => '12:45', 'timezone' => 'America/New_York', 'min' => 6, 'max' => 12], // launch
        ['days' => '2026-02-30', 'start' => '00:00', 'end' => '23:00', 'timezone' => 'UTC', 'min' => 9, 'max' => 9], // no such date: dropped
    ]]]]]);
    $site->id = '01WINDOWS';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');

    // Run just the constants and limits() from the generated worker.
    preg_match('/const INSTANCES = .*?\nconst PAUSE_KEY/s', $worker, $block);
    $script = preg_replace('/\nconst PAUSE_KEY$/', '', $block[0]).<<<'JS'

    const at = (iso) => JSON.stringify(limits(new Date(iso)));
    console.log([
      at('2026-09-25T16:30:00Z'), // Fri 12:30 New York: the launch date beats Friday
      at('2026-09-25T16:50:00Z'), // Fri 12:50 New York: launch over, Friday window
      at('2026-09-24T14:00:00Z'), // Thu 10:00 New York: weekdays
      at('2026-09-26T14:00:00Z'), // Sat: daily only
      at('2026-09-24T23:59:30Z'), // outside every window: defaults
    ].join('|'));
    JS;
    File::put($dir.'/limits.mjs', $script);

    expect(trim((string) shell_exec('node '.escapeshellarg($dir.'/limits.mjs').' 2>&1')))
        ->toBe('{"min":6,"max":12}|{"min":4,"max":9}|{"min":2,"max":5}|{"min":1,"max":3}|{"min":0,"max":2}');
});
