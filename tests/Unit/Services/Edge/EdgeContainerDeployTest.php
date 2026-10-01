<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeContainerDeployTest;

use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Services\Containers\EdgeContainerRollout;
use App\Modules\Edge\Services\Containers\EdgeReleaseBundle;
use App\Modules\Edge\Support\EdgeContainerSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;

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
        // Laravel's stderr and FPM's log reach the container's logs (a 500 is explained, not silent).
        ->and($dockerfile)->toContain('catch_workers_output = yes')
        ->and($dockerfile)->toContain('tail -qF /tmp/php-fpm.log /tmp/nginx-error.log &')
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
        // cache_locks does not exist on a new database, so --isolated fails; retry unlocked.
        ->and($dockerfile)->toContain('elif php artisan migrate --force --isolated || php artisan migrate --force; then')
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
        ->and($worker)->toContain('const target = await webTarget(env, request);')->and($worker)->toContain('const response = await proxy(env, new Request(request, { headers }), target);')
        ->and($worker)->toContain('"/_dply/queue/send"')
        ->and($worker)->toContain("request.headers.get('x-dply-queue-token') !== env.DPLY_QUEUE_TOKEN")
        ->and($worker)->toContain('{"site-jobs":"JOBS"}')
        ->and($worker)->not->toContain('puppeteer')
        ->and($worker)->not->toContain('__');
});

test('the worker fetches a warm container directly and caches fingerprinted assets', function () {
    $site = new Site;
    $site->id = '01SITEABC';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    $proxy = Str::between($worker, 'async function proxy(env, request, target) {', "\nfunction revealAppErrors");

    // Only a non-replayable upload waits up front; everything else lets the
    // SDK's containerFetch start the container when it is not healthy.
    expect($proxy)->toContain("if (!retryable) {\n    try {\n      await container.startAndWaitForPorts(")
        ->and(substr_count($proxy, 'await container.startAndWaitForPorts('))->toBe(2)
        ->and($worker)->toContain('if (max === 1) return 0;')
        ->and($worker)->toContain("const immutable = request.method === 'GET' && isImmutableAsset(url.pathname);")
        ->and($worker)->toContain('await caches.default.match(request)')
        ->and($worker)->toContain("cacheable.set('cache-control', 'public, max-age=31536000, immutable')")
        ->and($worker)->toContain("cacheable.set('access-control-allow-origin', '*')")
        ->and($worker)->toContain('ctx.waitUntil(caches.default.put(request, stored.clone()).catch(() => {}))')
        // a missing or refusing cache must never fail the request (it 500'd in the dispatch namespace)
        ->and($worker)->toContain('} catch {}');

    // The hash rule itself, run in node when it is available.
    $node = (new ExecutableFinder)->find('node');
    if ($node !== null) {
        $fn = Str::between($worker, 'function isImmutableAsset(path) {', "\n}\n");
        $script = 'function isImmutableAsset(path) {'.$fn."\n}\n"
            .'console.log(JSON.stringify(["/build/assets/app-BXa3Kq9z.css","/build/assets/app-3f9a1c2e.js","/_next/static/x.js","/build/assets/app-Homepage.js","/css/app.css","/login"].map(isImmutableAsset)));';
        $result = Process::run([$node, '-e', $script]);
        expect(json_decode($result->output(), true))->toBe([true, true, true, false, false, false]);
        File::copy($dir.'/src/index.js', $dir.'/src/check.mjs');
        expect(Process::run([$node, '--check', $dir.'/src/check.mjs'])->successful())->toBeTrue();
    }
});

test('a browser resource imports puppeteer and a site without one does not', function () {
    $site = new Site(['meta' => ['edge' => ['browser' => true]]]);
    $site->id = '01BROWSER';
    // Browser is paid-only; a comped org is on a paid plan.
    $site->setRelation('organization', (new Organization)->forceFill(['comped_until' => now()->addYear()]));
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);

    $worker = File::get($dir.'/src/index.js');
    $package = json_decode(File::get($dir.'/package.json'), true);
    File::deleteDirectory($dir);

    expect($worker)->toContain("import puppeteer from '@cloudflare/puppeteer';")
        ->and($worker)->toContain("puppeteer.launch(dplyMetered('browser', env.BROWSER, env, ctx, 'BROWSER'))")
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
        // One trigger for every task: scheduled() works out which are due.
        ->and(json_decode(File::get($dir.'/wrangler.jsonc'), true)['triggers'])->toBe(['crons' => ['* * * * *']])
        ->and(File::get($dir.'/src/index.js'))->toContain('async scheduled(controller, env, ctx)')
        ->and(File::get($dir.'/src/index.js'))->toContain('"/_dply/schedule"')
        ->and(File::get($dir.'/src/index.js'))->toContain("url.pathname === '/_dply/command'")
        // Run now reaches the app through the Worker, not only the Cron Trigger.
        ->and(File::get($dir.'/src/index.js'))->toContain('url.pathname === "/_dply/schedule" && request.method === \'POST\'');
});

test('a container app runs more than 5 schedules, and leaves out what the Worker can’t read', function () {
    $tasks = array_map(fn (int $h) => ['schedule' => "0 {$h} * * *", 'handler' => "report:{$h}"], range(1, 7));
    $tasks[] = ['schedule' => '0 6 L * *', 'handler' => 'month:end']; // Cloudflare-only syntax
    $site = new Site(['meta' => ['edge' => ['crons_overrides' => $tasks]]]);
    $site->id = '01MANYCRONS';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    $crons = EdgeContainerDeployer::cronHandlers($site, null);
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, [], $crons);
    $wrangler = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    expect($crons)->toHaveCount(7)->not->toHaveKey('0 6 L * *')
        ->and($wrangler['triggers'])->toBe(['crons' => ['* * * * *']])
        ->and($worker)->toContain('"0 7 * * *":["report:7"]')
        ->and($worker)->toContain("cronDue(cron, 'UTC', at, true)");
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
        ->and(EdgeContainerSettings::phpFpmPool('basic'))->toBe(['max_children' => 12, 'workers' => 12, 'memory_limit' => '128M'])
        ->and(EdgeContainerSettings::phpFpmPool('lite'))->toBe(['max_children' => 1, 'workers' => 1, 'memory_limit' => '128M'])
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

    // Database tools and queue workers run through /_dply/command too.
    $withDatabase = new Site;
    $withDatabase->meta = ['edge' => ['database' => ['engine' => 'postgres', 'provider' => 'dply']]];
    $withWorkers = new Site;
    $withWorkers->meta = ['edge' => ['container' => ['workers' => ['enabled' => true]]]];
    $plain = new Site;
    $plain->meta = ['edge' => []];
    expect(EdgeContainerDeployer::needsLaravelPackage($withDatabase, $dir))->toBeTrue()
        ->and(EdgeContainerDeployer::needsLaravelPackage($withWorkers, $dir))->toBeTrue()
        ->and(EdgeContainerDeployer::needsLaravelPackage($plain, $dir))->toBeFalse();

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir, true)['path']);

    // COPY . . brings back the app's own composer files; the injected ones
    // must be put back before the autoloader is dumped.
    expect(strpos($dockerfile, 'RUN cp /opt/dply/composer.json /opt/dply/composer.lock ./'))->toBeGreaterThan(strpos($dockerfile, 'COPY . .'))
        ->and(strpos($dockerfile, 'RUN cp /opt/dply/composer.json'))->toBeLessThan(strpos($dockerfile, 'composer dump-autoload'));
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

test('roadrunner starts without --rr-config so a repo without .rr.yaml still boots', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3","laravel/octane":"^2.0","spiral/roadrunner-http":"^3.0"}}',
        'artisan' => '',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    expect($dockerfile)->toContain('octane:start --server=roadrunner --host=0.0.0.0 --port=8080')
        ->and($dockerfile)->not->toContain('--rr-config');
});

test('a websocket 101 from the app passes the sticky cookie and debug filters untouched', function () {
    $site = new Site;
    $site->id = '01SOCKETS';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');

    expect($worker)->toContain('if (isSocket(response)) return response;')
        ->and($worker)->toContain('if (isSocket(response) || (flag !==')
        ->and($worker)->toContain('return response.status === 101 || Boolean(response.webSocket);');

    if (trim((string) shell_exec('command -v node')) === '') {
        $this->markTestSkipped('node is not installed');
    }

    // Run the three filters from the generated worker. A 101 cannot be built
    // as a Response (RangeError, as in workerd), so a stand-in must come back
    // as the same object; a plain 200 still gets the cookie and debug header.
    preg_match('/function isSocket\(response\).*?\n}\n\nfunction withStickyCookie.*?\n}\n/s', $worker, $sticky);
    preg_match('/function revealAppErrors.*?\n}\n/s', $worker, $reveal);
    File::put($dir.'/filters.mjs', $sticky[0].$reveal[0].<<<'JS'
    const env = { APP_DEBUG: 'true' };
    const socket = { status: 101, webSocket: {}, headers: new Headers({ upgrade: 'websocket' }) };
    const plain = revealAppErrors(env, withStickyCookie(new Response('ok'), '1'));
    console.log([
      revealAppErrors(env, withStickyCookie(socket, '1')) === socket,
      plain.headers.get('set-cookie'),
      plain.headers.get('x-dply-app-debug'),
    ].join('|'));
    JS);

    expect(trim((string) shell_exec('node '.escapeshellarg($dir.'/filters.mjs').' 2>&1')))
        ->toBe('true|dply_instance=1; Path=/; HttpOnly; SameSite=Lax; Max-Age=604800|1');
});

test('composer extra.dply.php-server pins frankenphp or fpm; octane servers still need octane', function () {
    $pin = fn (string $server, array $require = []): string => EdgeContainerDockerfile::detectPhpServer(['require' => $require, 'extra' => ['dply' => ['php-server' => $server]]]);

    expect($pin('frankenphp', ['laravel/octane' => '^2.0']))->toBe('frankenphp')
        ->and($pin('fpm', ['laravel/octane' => '^2.0']))->toBe('fpm')
        ->and($pin('swoole'))->toBe('fpm')
        ->and(EdgeContainerDockerfile::detectPhpServer(json_decode((string) file_get_contents(base_path('composer.json')), true)))->toBe('frankenphp');
});

test('frankenphp trusts the Worker with a multi-line Caddy block; a one-line block stops the server from starting', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.3"},"extra":{"dply":{"php-server":"frankenphp"}}}',
        'artisan' => '',
    ]);

    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);
    preg_match('/^CMD \["sh", "-c", (".*")\]$/m', $dockerfile, $m);
    $boot = json_decode($m[1]);
    // Run inside the checkout: the boot line runs `php artisan config:cache`, which from
    // the project root cached dply's own config with the testing database.
    $options = shell_exec('cd '.escapeshellarg($dir).' && sh -c '.escapeshellarg(substr($boot, 0, strpos($boot, '; export FRANKENPHP')).'; printf "@@%s" "$CADDY_GLOBAL_OPTIONS"'));
    // The boot line also prints its "dply-boot:" timing line first; keep only the options.
    // The background config:cache may print a "dply:" line at any point; it is not the options.
    $options = trim((string) preg_replace('/dply: [^\n]*/', '', Str::after((string) $options, '@@')));

    expect($dockerfile)->not->toContain('ENV CADDY_GLOBAL_OPTIONS')
        ->and($options)->toBe("servers {\n\ttrusted_proxies static 0.0.0.0/0 ::/0\n}");
});

test('static assets from the container Worker allow any origin, so a custom domain can load them', function () {
    $site = new Site;
    $site->id = '01SITECORS';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, [], [], '', false);

    expect(File::get($dir.'/src/index.js'))->toContain("headers.set('access-control-allow-origin', '*')");
});

test('php images tune opcache, cache routes and events at build and config at boot, tolerating failures', function () {
    $fpm = File::get(EdgeContainerDockerfile::prepare(checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'artisan' => '']))['path']);
    $franken = File::get(EdgeContainerDockerfile::prepare(checkout(['composer.json' => '{"require":{"php":"^8.3"},"extra":{"dply":{"php-server":"frankenphp"}}}', 'artisan' => '']))['path']);
    $plain = File::get(EdgeContainerDockerfile::prepare(checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'index.php' => '']))['path']);

    expect($fpm)->toContain("RUN printf '%s\\n' opcache.enable=1 opcache.validate_timestamps=0 opcache.memory_consumption=128 opcache.interned_strings_buffer=16 opcache.max_accelerated_files=20000 opcache.jit=tracing opcache.jit_buffer_size=64M > \"\$PHP_INI_DIR/conf.d/zz-dply-opcache.ini\"")
        // The OPcache file cache was tried and made real cold starts slower (see opcacheIni).
        ->and($fpm)->not->toContain('opcache.file_cache')->not->toContain('opcache.enable_cli')
        ->and($fpm)->not->toContain('-d opcache.')
        ->and($fpm)->toContain('DPLY_PERSISTENT_PDO=1')->and($franken)->not->toContain('DPLY_PERSISTENT_PDO')
        // After VIEW_COMPILED_PATH, or the cached config pins the old view path.
        ->and(strpos($fpm, 'nice -n 19 php artisan config:cache'))->toBeGreaterThan(strpos($fpm, 'export VIEW_COMPILED_PATH'))
        // php-fpm starts first (its children inherit VIEW_COMPILED_PATH), beside the restore and the
        // migrate check; nginx opens the port last, so nothing reaches fpm before the database is ready.
        ->and(strpos($fpm, 'php-fpm -F'))->toBeGreaterThan(strpos($fpm, 'export VIEW_COMPILED_PATH'))
        ->and(strpos($fpm, 'php-fpm -F'))->toBeLessThan(strpos($fpm, 'wget -q -O'))
        ->and(strpos($fpm, 'exec nginx'))->toBeGreaterThan(strpos($fpm, 'artisan migrate --force'))
        // The restore goes to a temp file and is renamed in; only a non-404 failure falls back to PHP.
        ->and($fpm)->toContain('wget -q -O \\"$DB_DATABASE.dply\\" http://sqlite.dply/db')
        ->and($fpm)->toContain('grep -q \\" 404 \\" /tmp/dply-sqlite.err || php -r')
        // Beside the starting server, never in front of it; written to a temp path and renamed in.
        ->and($fpm)->toContain('(APP_CONFIG_CACHE=/app/bootstrap/cache/config.dply.php nice -n 19 php artisan config:cache')
        ->and($fpm)->toContain('&& mv -f /app/bootstrap/cache/config.dply.php /app/bootstrap/cache/config.php')
        // Readiness by port check, not a PHP process per poll; the restored sqlite file is not re-uploaded at boot.
        ->and($fpm)->toContain('until nc -z 127.0.0.1 9000')
        ->and($fpm)->toContain('( while true; do sleep 20; php -r')
        // Env-free caches at build; boot rebuilds them only if the build could not.
        ->and($fpm)->toContain('RUN php artisan route:cache >/dev/null 2>&1 || echo')->toContain('php artisan event:cache >/dev/null 2>&1 || true')
        ->and($fpm)->toContain('[ -f bootstrap/cache/routes-v7.php ] || php artisan route:cache')
        // Never all four (and every view) on each cold start.
        ->and($fpm)->not->toContain('artisan optimize')->not->toContain('view:cache')
        ->and($franken)->toContain('opcache.validate_timestamps=0')->not->toContain('opcache.jit')
        ->and(strpos($franken, 'php artisan config:cache'))->toBeLessThan(strpos($franken, 'exec frankenphp run'))
        ->and($franken)->not->toContain('octane:frankenphp')
        ->and($plain)->toContain('zz-dply-opcache.ini')->not->toContain('artisan config:cache');
});

test('worker mode starts octane:frankenphp only for a frankenphp app with octane', function () {
    $image = EdgeContainerDockerfile::prepare(checkout([
        'composer.json' => '{"require":{"php":"^8.3","laravel/octane":"^2.0"},"extra":{"dply":{"php-server":"frankenphp"}}}',
        'artisan' => '',
    ]));
    $dockerfile = File::get($image['path']);

    expect($image['worker_mode'])->toBeTrue()
        ->and($dockerfile)->toContain('if [ \"$DPLY_WORKER_MODE\" = \"1\" ]; then exec php artisan octane:frankenphp -n --host=0.0.0.0 --port=8080')
        ->and(strpos($dockerfile, 'octane:frankenphp'))->toBeLessThan(strpos($dockerfile, 'exec frankenphp run'))
        ->and(EdgeContainerDockerfile::prepare(checkout([
            'composer.json' => '{"require":{"php":"^8.3","laravel/octane":"^2.0"}}',
            'artisan' => '',
        ]))['worker_mode'])->toBeFalse();
});

test('the worker hints its durable objects to the region of the app database', function () {
    config(['edge.valkey.regions' => [['key' => 'nyc3', 'cloudflare' => 'ENAM']]]);
    $site = new Site;
    $site->id = '01SITEHINT';
    $site->meta = ['edge' => ['database' => ['provider' => 'dply', 'engine' => 'postgres', 'region' => 'nyc3']]];
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/build/src/Dockerfile.dply', 8080, []);
    $worker = File::get($dir.'/src/index.js');

    expect($worker)->toContain('const LOCATION_HINT = "enam";')
        ->and($worker)->toContain('binding.get(binding.idFromName(name), LOCATION_HINT ? { locationHint: LOCATION_HINT } : undefined)')
        ->and($worker)->toContain("import { Container } from '@cloudflare/containers';");
});

test('the key-value proxy pages keys, bulk-reads, and checks ttl, expiry, and metadata headers', function () {
    if (trim((string) shell_exec('command -v node')) === '') {
        $this->markTestSkipped('node is not installed');
    }
    $site = new Site;
    $site->id = '01KVPROXY';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');

    preg_match('/const asciiJson = .*?\nasync function kvFetch.*?\n}\n/s', $worker, $block);
    $later = (string) (time() + 3600);
    File::put($dir.'/kv.mjs', $block[0].str_replace('__LATER__', $later, <<<'JS'
    const store = new Map([['a1', { value: 'one', metadata: { v: 'é' } }], ['a2', { value: 'two' }], ['b1', { value: 'three' }]]);
    const puts = [];
    const kv = {
      async list({ prefix = '', cursor }) {
        const names = [...store.keys()].filter((n) => n.startsWith(prefix));
        const start = cursor ? Number(cursor) : 0;
        const page = names.slice(start, start + 2);
        const done = start + 2 >= names.length;
        return { keys: page.map((name) => ({ name, metadata: store.get(name).metadata })), list_complete: done, cursor: done ? undefined : String(start + 2) };
      },
      async get(keys) { return new Map(keys.map((k) => [k, store.get(k)?.value ?? null])); },
      async getWithMetadata(key) { const hit = store.get(key); return { value: hit?.value ?? null, metadata: hit?.metadata ?? null }; },
      async put(key, value, options) { puts.push(options); },
      async delete() {},
    };
    const call = async (method, path, { headers = {}, body, query = '' } = {}) => {
      const url = new URL('http://kv.internal/' + path + query);
      const res = await kvFetch(kv, new Request(url, { method, headers, body }), path, url);
      return res.status + ':' + (res.status === 204 ? '' : await res.text()) + (res.headers.get('x-dply-metadata') ? '|' + res.headers.get('x-dply-metadata') : '');
    };
    const later = '__LATER__';
    console.log([
      await call('GET', ''),
      await call('GET', '', { query: '?cursor=2' }),
      await call('GET', '', { query: '?prefix=a&detail=1' }),
      await call('POST', '', { headers: { 'content-type': 'application/json' }, body: JSON.stringify({ keys: ['a1', 'zz'] }) }),
      await call('POST', '', { body: '{"keys":[]}' }),
      await call('GET', 'a1'),
      await call('GET', 'a1', { headers: { 'x-dply-cache-ttl': '5' } }),
      await call('GET', 'zz'),
      await call('PUT', 'k', { body: 'v', headers: { 'x-dply-ttl': '30', 'x-dply-metadata': '{"n":1}' } }),
      await call('PUT', 'k', { body: 'v', headers: { 'x-dply-expires-at': later } }),
      await call('PUT', 'k', { body: 'v', headers: { 'x-dply-ttl': '120', 'x-dply-expires-at': later } }),
      await call('PUT', 'k', { body: 'v', headers: { 'x-dply-expires-at': '10' } }),
      await call('PUT', 'k', { body: 'v', headers: { 'x-dply-metadata': 'nope' } }),
      await call('PUT', 'k', { body: 'v', headers: { 'x-dply-metadata': JSON.stringify({ x: 'y'.repeat(1100) }) } }),
      JSON.stringify(puts),
    ].join('\n'));
    JS));

    expect(explode("\n", trim((string) shell_exec('node '.escapeshellarg($dir.'/kv.mjs').' 2>&1'))))->toBe([
        '200:{"keys":["a1","a2"],"cursor":"2"}',
        '200:{"keys":["b1"],"cursor":null}',
        '200:{"keys":[{"name":"a1","expiration":null,"metadata":{"v":"é"}},{"name":"a2","expiration":null,"metadata":null}],"cursor":null}',
        '200:{"values":{"a1":"one","zz":null}}',
        '400:Send {"keys": [...]} with 1 to 100 keys.',
        '200:one|{"v":"\u00e9"}',
        '400:x-dply-cache-ttl must be 30 seconds or more.',
        '404:',
        '204:',
        '204:',
        '400:Send x-dply-ttl or x-dply-expires-at, not both.',
        '400:x-dply-expires-at must be a unix time at least 60 seconds ahead.',
        '400:x-dply-metadata must be JSON.',
        '400:x-dply-metadata must be 1024 bytes or less.',
        '[{"metadata":{"n":1}},{"expiration":'.$later.'}]',
    ]);
});

test('a 5xx after deploy prints the error the app logged, read from the app itself', function () {
    Http::fake(['app.on-dply.live/_dply/schedule' => Http::response(['output' => implode("\n", [
        '[29-Sep-2026 20:24:40] NOTICE: fpm is running',
        '[2026-09-29 20:24:40] production.ERROR: SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "cache" does not exist',
        '#0 /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php(838): runQueryCallback()',
        '[29-Sep-2026 20:24:41] WARNING: [pool www] server reached max_children setting (1)',
    ])])]);
    $site = new Site(['meta' => ['edge' => ['live_url' => 'https://app.on-dply.live']]]);
    $site->id = '01ERRLOG';
    $lines = [];

    $logger = function (string $l) use (&$lines): void {
        $lines[] = $l;
    };
    (function () use ($site, $logger): void {
        $this->logAppErrors($site, time(), $logger);
    })->call(new EdgeContainerDeployer);

    expect(implode('', $lines))->toContain('The app logged:')
        ->toContain('relation "cache" does not exist')
        ->not->toContain('#0 /app/vendor')
        ->not->toContain('max_children');
    Http::assertSent(fn ($r) => str_starts_with((string) $r['handler'], 'tail -q -n 300 /tmp/php-fpm.log'));
});

test('a request that finds its instance asleep starts it with a tight poll and records one wake; a warm one records nothing', function () {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        $this->markTestSkipped('node is not installed');
    }
    $site = new Site(['meta' => ['edge' => []]]);
    $site->id = '01WAKETIMING';
    $dir = sys_get_temp_dir().'/dply-wake-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    $wrangler = File::get($dir.'/wrangler.jsonc');
    File::deleteDirectory($dir);

    // The App class's own fetch + wake, run against a stand-in for the SDK's Container.
    // From after the uptime-check branch (its own test covers it) to the end of wake().
    $methods = Str::between($worker, "\n    this.reservations.shift();", "\n  // Last request into this instance");
    $script = 'const SITE_ID = "s";'
        .'function recordWake(env, index, readyMs, probeMs, requestMs, status) {'.Str::betweenFirst($worker, 'function recordWake(env, index, readyMs, probeMs, requestMs, status) {', "\n}\n")."\n}\n"
        .<<<'JS'
    const points = [], calls = [];
    class Container {
      async fetch() { calls.push('request'); return new Response('ok', { status: 200 }); }
      async startAndWaitForPorts(o) { calls.push('start:' + o.cancellationOptions.waitInterval + '/' + o.cancellationOptions.instanceGetTimeoutMS); this.probeMs = 12; this.container.running = true; }
    }
    class App extends Container {
      reservations = []; index = 0; container = { running: false };
      env = { DPLY_WAKE: { writeDataPoint: (p) => points.push(p) } };
      async fetch(request) { this.reservations.shift();
    JS
        .$methods.<<<'JS'

    }
    (async () => {
      const app = new App();
      await app.fetch(new Request('https://x/'));   // asleep
      await app.fetch(new Request('https://x/'));   // now warm
      console.log(JSON.stringify({ points, calls }));
    })();
    JS;
    // The last line: wake() also logs a dply-wake: line.
    $out = json_decode((string) Str::of(Process::run([$node, '-e', $script])->throw()->output())->trim()->explode("\n")->last(), true);

    expect($out['calls'])->toBe(['start:100/30000', 'request', 'request'])
        ->and($out['points'])->toHaveCount(1)
        ->and($out['points'][0]['blobs'])->toBe(['s', '0', '200'])
        ->and($out['points'][0]['doubles'][1])->toBe(12)          // the probe, as measured
        ->and($out['points'][0]['doubles'][0])->toBeGreaterThanOrEqual(0)
        ->and($worker)->toContain("pingEndpoint = 'ping/_dply-ping'")
        // Its own dataset: the reply-bytes one is summed whole for billing.
        ->and($wrangler)->toContain('"binding": "DPLY_WAKE"')->toContain('"dataset": "dply_container_wake"')
        ->and($wrangler)->toContain('"dataset": "dply_container_bytes"');
});

test('php images answer the readiness probe without PHP and log one boot-timing line', function () {
    $fpm = File::get(EdgeContainerDockerfile::prepare(checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'artisan' => '']))['path']);
    $franken = File::get(EdgeContainerDockerfile::prepare(checkout(['composer.json' => '{"require":{"php":"^8.3"},"extra":{"dply":{"php-server":"frankenphp"}}}', 'artisan' => '']))['path']);

    expect($fpm)->toContain('location = /_dply-ping { return 204; }')
        ->and($franken)->toContain('respond /_dply-ping 204')
        ->and(substr_count($fpm, 'dply-boot:'))->toBe(1)
        ->and($fpm)->toContain('start=$b0 sqlite=$b1 migrate=$b2 caches=');
});

test('the worker serves a public bucket path read-only from the bucket', function () {
    if (trim((string) shell_exec('command -v node')) === '') {
        $this->markTestSkipped('node is not installed');
    }
    $site = new Site(['meta' => ['edge' => [
        'connections' => [
            ['kind' => 'object_storage', 'name' => 'MEDIA', 'host' => 'media.app.internal', 'target' => 'dply-x-media'],
            ['kind' => 'object_storage', 'name' => 'SECRET', 'host' => 'secret.app.internal', 'target' => 'dply-x-secret'],
        ],
        'storage_public' => ['media.app.internal' => '/files', 'secret.app.internal' => '/../nope'],
    ]]]);
    $site->id = '01PUBLICSTORE';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');

    preg_match('/const PUBLIC_STORAGE = .*?\n}\n/s', $worker, $block);
    File::put($dir.'/public.mjs', $block[0].<<<'JS'

    const bucket = { get: async (key) => key === 'a.txt' ? { body: 'hello', size: 5, httpEtag: '"e"', writeHttpMetadata: (h) => h.set('content-type', 'text/plain') } : null };
    const env = { MEDIA: bucket, SECRET: bucket };
    const hit = async (method, path) => {
      const r = await publicStorageFetch(new Request('https://app.example' + path, { method }), env, new URL('https://app.example' + path));
      return r === null ? 'app' : r.status + ':' + (await r.text()) + ':' + (r.headers.get('content-type') ?? '');
    };
    console.log([await hit('GET', '/files/a.txt'), await hit('GET', '/files/b.txt'), await hit('GET', '/files/%2E%2E/a.txt'),
      await hit('POST', '/files/a.txt'), await hit('GET', '/other/a.txt'), await hit('GET', '/files')].join('|'));
    JS);

    expect($block[0])->toContain('"path":"/files"')->not->toContain('SECRET')
        ->and(trim((string) shell_exec('node '.escapeshellarg($dir.'/public.mjs').' 2>&1')))
        ->toBe('200:hello:text/plain|404:Not found:text/plain;charset=UTF-8|app|app|app|app'); // %2E%2E normalizes out of /files
});

test('an octane app logs boot timing without config:cache; sqlite migrate-on-boot skips a build that already migrated', function () {
    $octane = File::get(EdgeContainerDockerfile::prepare(checkout([
        'composer.json' => '{"require":{"php":"^8.3","laravel/octane":"^2.0","spiral/roadrunner-http":"^3.0"}}',
        'artisan' => '',
    ]))['path']);
    $fpm = File::get(EdgeContainerDockerfile::prepare(checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'artisan' => '']))['path']);

    // Octane logs boot timing but skips config:cache (it cost more than it saved).
    expect(strpos($octane, 'dply-boot:'))->toBeInt()->toBeLessThan(strpos($octane, 'octane:start --server=roadrunner'))
        ->and($octane)->not->toContain('artisan config:cache')
        // The build id comes after the code, so a code change makes a new one.
        ->and(strpos($fpm, 'RUN cat /proc/sys/kernel/random/uuid > /app/.dply-build'))->toBeGreaterThan(strpos($fpm, 'composer dump-autoload'))
        // Skip only for sqlite whose database carries this build's id; mark only after a migrate succeeds.
        ->and($fpm)->toContain('if [ \\"$DB_CONNECTION\\" = \\"sqlite\\" ] && php -r')
        ->and(strpos($fpm, 'dply_migrated (build TEXT)'))->toBeGreaterThan(strpos($fpm, 'elif php artisan migrate --force --isolated'));
});

test('the sqlite migrate skip matches only the build that migrated', function () {
    $php = (new ExecutableFinder)->find('php');
    $db = sys_get_temp_dir().'/dply-migrated-'.bin2hex(random_bytes(4)).'.sqlite';
    $build = sys_get_temp_dir().'/dply-build-'.bin2hex(random_bytes(4));
    // The same code with the image path swapped for a temp file.
    $run = fn (string $code): int => Process::env(['DB_DATABASE' => $db])->run([$php, '-r', str_replace('/app/.dply-build', $build, $code)])->exitCode();
    File::put($build, "build-a\n");
    touch($db);

    $fresh = $run(EdgeContainerDockerfile::SQLITE_MIGRATED);
    $run(EdgeContainerDockerfile::SQLITE_MARK_MIGRATED);
    $marked = $run(EdgeContainerDockerfile::SQLITE_MIGRATED);
    File::put($build, "build-b\n");
    $newBuild = $run(EdgeContainerDockerfile::SQLITE_MIGRATED);
    File::delete([$db, $build]);

    expect([$fresh, $marked, $newBuild])->toBe([1, 0, 1]);
});

test('the worker serves vector search over REST, Upstash-Vector-compatible, on the app binding', function () {
    if (trim((string) shell_exec('command -v node')) === '') {
        $this->markTestSkipped('node is not installed');
    }
    $site = new Site(['meta' => ['edge' => [
        'connections' => [['kind' => 'vectors', 'name' => 'DOCS', 'host' => 'docs.app.internal', 'target' => 'dply-x-docs']],
    ]]]);
    $site->id = '01VECTORREST';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    expect($worker)->toContain('const vectorReply = await vectorRestFetch(request, env, ctx, url);');

    preg_match('/const CONNECTIONS = .*?;\n/', $worker, $connections);
    preg_match('/\/\/ ---- vector REST \(start\) ----.*?\/\/ ---- vector REST \(end\) ----/s', $worker, $block);
    // With UPSTASH_SDK_DIR (a folder with node_modules/@upstash/vector) the real SDK runs too.
    $runDir = getenv('UPSTASH_SDK_DIR') ?: $dir;
    $script = $runDir.'/vector-rest-check-'.bin2hex(random_bytes(3)).'.mjs';
    $fixture = File::get(base_path('tests/Fixtures/vector-rest-check.mjs'));
    // Imports first, then the Worker's constants and block, then the checks.
    [$imports, $checks] = explode("\n\nlet metered", $fixture, 2);
    File::put($script, $imports."\n".$connections[0].$block[0]."\n\nlet metered".$checks);
    $out = trim((string) shell_exec('node '.escapeshellarg($script).' 2>&1'));
    File::delete($script);

    expect($out)->toEndWith('ok');
    if (getenv('UPSTASH_SDK_DIR')) {
        expect($out)->toContain('sdk ok');
    }
});

test('durable_object scheduling inlines its own container base on ctx.container and runs it', function () {
    config(['edge.build.containers.durable_object_scheduling' => true]);
    $site = new Site(['meta' => ['edge' => ['container' => ['scheduling' => 'durable_object', 'instance_type' => 'basic']]]]);
    $site->id = '01SITEDOSCHED';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));

    (new EdgeContainerDeployer)->scaffold($dir, $site, '/build/src/Dockerfile.dply', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $package = json_decode(File::get($dir.'/package.json'), true);
    $worker = File::get($dir.'/src/index.js');

    // Only the keys wrangler allows for Durable Object-managed containers.
    expect($config['containers'][0])->toBe([
        'class_name' => 'App',
        'scheduling_policy' => 'durable_object',
        'images' => ['app' => ['dockerfile' => '/build/src/Dockerfile.dply']],
    ])
        ->and($package['dependencies'])->toBe([])
        ->and($worker)->not->toContain("from '@cloudflare/containers'")
        ->and($worker)->toContain('const DO_INSTANCE = {"vcpu":0.25,"memoryMib":1024,"diskMb":4000};')
        ->and($worker)->toContain('export class ContainerProxy extends WorkerEntrypoint')
        ->and($worker)->not->toContain('__');

    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        return;
    }
    $stubbed = str_replace(
        ["import { DurableObject } from 'cloudflare:workers';", "import { WorkerEntrypoint } from 'cloudflare:workers';"],
        ['class DurableObject { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }', 'class WorkerEntrypoint { constructor(ctx, env) { this.ctx = ctx; this.env = env; } }'],
        $worker,
    );
    File::put($dir.'/src/check.mjs', $stubbed."\n".File::get(base_path('tests/Fixtures/do-container-check.mjs'))."\nconsole.log(await run());\n");
    $result = Process::run([$node, $dir.'/src/check.mjs']);
    expect(preg_split('/\R/', trim($result->output())))->toContain('ok');

    // With a release bundle: snapshots, with no delay so the test waits on nothing.
    $bundled = str_replace(['const RELEASE_KEY = "";', 'const RELEASE_PREFIX = "";', 'const SNAPSHOT_DELAY_MS = 20000;'], ['const RELEASE_KEY = "releases/x/1.tar.gz";', 'const RELEASE_PREFIX = "releases/x/";', 'const SNAPSHOT_DELAY_MS = 0;'], $stubbed);
    File::put($dir.'/src/check-bundle.mjs', $bundled."\n".File::get(base_path('tests/Fixtures/do-container-check.mjs'))."\nconsole.log(await run());\n");
    $result = Process::run([$node, $dir.'/src/check-bundle.mjs']);
    // The snapshot code logs too ("dply-snapshot: …"); the harness prints ok last-ish.
    expect(preg_split('/\R/', trim($result->output())))->toContain('ok')
        ->and($result->output())->toContain('dply-snapshot: saved');
});

test('an app with a jurisdiction or regions stays on default scheduling', function () {
    config(['edge.build.containers.durable_object_scheduling' => true]);
    $site = new Site(['meta' => ['edge' => ['container' => ['scheduling' => 'durable_object', 'jurisdiction' => 'eu']]]]);
    expect(EdgeContainerSettings::for($site)['scheduling'])->toBe('default');
    $site = new Site(['meta' => ['edge' => ['container' => ['scheduling' => 'durable_object']]]]);
    expect(EdgeContainerSettings::for($site)['scheduling'])->toBe('durable_object')
        ->and(EdgeContainerSettings::durableObjectInstance(new Site(['meta' => ['edge' => ['container' => ['instance_type' => 'standard-2']]]])))->toBe('standard-2');
});

test('a durable_object candidate copy gets no max_instances (wrangler refuses it)', function () {
    config(['edge.build.containers.durable_object_scheduling' => true]);
    $site = new Site(['meta' => ['edge' => ['container' => ['scheduling' => 'durable_object']]]]);
    $site->id = '01SITEDOCAND';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);

    EdgeContainerDeployer::asCandidate($dir, 'dply-ctr-01sitedocand-next');

    expect(json_decode(File::get($dir.'/wrangler.jsonc'), true)['containers'][0])->not->toHaveKey('max_instances');
});

test('faster starts stays off unless the platform setting allows it', function () {
    config(['edge.build.containers.durable_object_scheduling' => false]);
    $site = new Site(['meta' => ['edge' => ['container' => ['scheduling' => 'durable_object']]]]);
    expect(EdgeContainerSettings::for($site)['scheduling'])->toBe('default');
});

test('a request that lands while its instance stops for inactivity is retried, not answered with the SDK 500', function () {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        $this->markTestSkipped('node is not installed');
    }
    $site = new Site;
    $site->id = '01SITERETRY';
    $dir = sys_get_temp_dir().'/dply-retry-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    // The real proxy(); its helpers stubbed to pass the response through.
    $script = 'const httpRequest = (r) => r; const countReply = (env, r) => r; const revealAppErrors = (env, r) => r; const withStickyCookie = (r) => r;'
        .'async function proxy(env, request, target) {'.Str::between($worker, 'async function proxy(env, request, target) {', "\n// One cold start")
        .<<<'JS'

    const run = async (first) => {
      const calls = [];
      let n = 0;
      const container = {
        fetch: async () => { calls.push('fetch'); return n++ === 0 ? new Response(first, { status: 500 }) : new Response('ok', { status: 200 }); },
        startAndWaitForPorts: async () => { calls.push('start'); },
      };
      const res = await proxy({}, new Request('https://x/'), { container, cookie: null });
      return { status: res.status, calls };
    };
    (async () => console.log(JSON.stringify({
      stopping: await run('Error proxying request to container: The container is not listening'),
      app: await run('Whoops, something went wrong'),
    })))();
    JS;
    // The last line: wake() also logs a dply-wake: line.
    $out = json_decode((string) Str::of(Process::run([$node, '-e', $script])->throw()->output())->trim()->explode("\n")->last(), true);

    expect($out['stopping'])->toBe(['status' => 200, 'calls' => ['fetch', 'start', 'fetch']])
        // The app's own 500 is its answer: no retry.
        ->and($out['app'])->toBe(['status' => 500, 'calls' => ['fetch']]);
});

test('a generated laravel Dockerfile splits into a runtime image and a release of /app', function () {
    $dir = checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'composer.lock' => '{}', 'artisan' => '', 'package.json' => '{"scripts":{"build":"vite build"}}']);
    $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);

    $split = EdgeReleaseBundle::split($dockerfile);

    expect($split)->not->toBeNull();
    // The runtime has no app: no dependencies, code, assets or caches.
    expect($split['runtime'])->toStartWith('FROM php:8.4-fpm-alpine')
        ->and($split['runtime'])->toContain('WORKDIR /app')
        ->and($split['runtime'])->toContain('RUN printf %s ')
        ->and($split['runtime'])->toContain('EXPOSE 8080')
        ->and($split['runtime'])->not->toContain('COPY composer.json')
        ->and($split['runtime'])->not->toContain('COPY . .')
        ->and($split['runtime'])->not->toContain('route:cache >/dev/null 2>&1 || echo')
        ->and($split['runtime'])->not->toContain('AS assets');
    $cmd = json_decode(substr((string) Str::of($split['runtime'])->explode("\n")->last(fn ($l) => str_starts_with($l, 'CMD ')), 4), true);
    expect($cmd[2])->toStartWith(EdgeReleaseBundle::FETCH)->and($cmd[2])->toContain('php-fpm -F');
    // The release is the whole build plus a stage holding only /app.
    expect($split['release'])->toContain('COPY . .')
        ->and($split['release'])->toContain("FROM scratch AS dply-release\nCOPY --from=dply-app /app /")
        ->and($split['release'])->toMatch('/^FROM php:8.4-fpm-alpine AS dply-app$/m')
        ->and($split['hash'])->toHaveLength(16);
    // Same runtime text, same hash: a code change does not rebuild the runtime.
    File::put($dir.'/routes.php', '<?php // changed');
    expect(EdgeReleaseBundle::split(File::get(EdgeContainerDockerfile::prepare($dir)['path']))['hash'])->toBe($split['hash']);
});

test('a repo Dockerfile does not split', function () {
    expect(EdgeReleaseBundle::split("FROM ruby:3.3\nCOPY . .\nCMD [\"rails\", \"s\"]\n"))->toBeNull();
});

test('old releases are pruned to the newest three, and all go with the app', function () {
    Storage::fake('edge_r2');
    config(['edge.disk.name' => 'edge_r2']);
    $site = new Site;
    $site->id = '01SITEPRUNE';
    $other = new Site;
    $other->id = '01SITEOTHER';
    $disk = Storage::disk('edge_r2');
    foreach (range(1, 5) as $n) {
        $disk->put(EdgeReleaseBundle::prefix($site)."r{$n}.tar.gz", 'x');
        touch($disk->path(EdgeReleaseBundle::prefix($site)."r{$n}.tar.gz"), 1_700_000_000 + $n);
    }
    $disk->put(EdgeReleaseBundle::prefix($other).'keep.tar.gz', 'x');

    expect(EdgeReleaseBundle::prune($site))->toBe(2)
        ->and(collect($disk->files(EdgeReleaseBundle::prefix($site)))->map(fn ($p) => basename($p))->sort()->values()->all())->toBe(['r3.tar.gz', 'r4.tar.gz', 'r5.tar.gz']);

    EdgeReleaseBundle::forget($site);
    expect($disk->files(EdgeReleaseBundle::prefix($site)))->toBe([])
        ->and($disk->exists(EdgeReleaseBundle::prefix($other).'keep.tar.gz'))->toBeTrue();
});

test('faster starts ship release bundles unless an app turns them off', function () {
    config(['edge.build.containers.durable_object_scheduling' => true]);
    $on = EdgeContainerSettings::for(new Site(['meta' => ['edge' => ['container' => ['scheduling' => 'durable_object']]]]));
    $off = EdgeContainerSettings::for(new Site(['meta' => ['edge' => ['container' => ['scheduling' => 'durable_object', 'release_bundle' => false]]]]));
    $default = EdgeContainerSettings::for(new Site);

    expect(EdgeContainerSettings::releaseBundle($on))->toBeTrue()
        ->and(EdgeContainerSettings::releaseBundle($off))->toBeFalse()
        ->and(EdgeContainerSettings::releaseBundle($default))->toBeFalse();
});

test('the worker fingerprint ignores the per-deploy build and release, and changes with public/ or the config', function () {
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($dir.'/src');
    File::ensureDirectoryExists($dir.'/public/build');
    File::put($dir.'/Dockerfile', "FROM php\n");
    $write = function (string $build, string $release, string $css = 'a{}', int $max = 1) use ($dir) {
        File::put($dir.'/wrangler.jsonc', json_encode(['name' => 'x', 'containers' => [['images' => ['app' => ['dockerfile' => $dir.'/Dockerfile']]]], 'vars' => ['m' => $max]]));
        File::put($dir.'/src/index.js', "const BUILD_ID = \"{$build}\";\nconst RELEASE_KEY = \"{$release}\";\nexport default {};\n");
        File::put($dir.'/secrets.json', '{"A":"1"}');
        File::put($dir.'/public/build/app.css', $css);
    };

    $write('b1', 'releases/s/1.tar.gz');
    $first = EdgeContainerDeployer::workerFingerprint($dir);
    $write('b2', 'releases/s/2.tar.gz');
    expect(EdgeContainerDeployer::workerFingerprint($dir))->toBe($first);
    $write('b3', 'releases/s/3.tar.gz', 'a{color:red}');
    expect(EdgeContainerDeployer::workerFingerprint($dir))->not->toBe($first);
    $write('b4', 'releases/s/4.tar.gz', 'a{}', 2);
    expect(EdgeContainerDeployer::workerFingerprint($dir))->not->toBe($first);
});

test('pruning keeps the vendor archives the kept releases use, and drops the rest', function () {
    Storage::fake('edge_r2');
    config(['edge.disk.name' => 'edge_r2']);
    $site = new Site;
    $site->id = '01SITEVENDOR';
    $disk = Storage::disk('edge_r2');
    $put = function (string $name, int $at) use ($disk, $site) {
        $disk->put(EdgeReleaseBundle::prefix($site).$name, 'x');
        touch($disk->path(EdgeReleaseBundle::prefix($site).$name), $at);
    };
    $put('d1.aaaaaaaaaaaaaaaa.tar.gz', 1);
    $put('d2.aaaaaaaaaaaaaaaa.tar.gz', 2);
    $put('d3.bbbbbbbbbbbbbbbb.tar.gz', 3);
    $put('d4.bbbbbbbbbbbbbbbb.tar.gz', 4);
    $put('d5.cccccccccccccccc.tar.gz', 5);
    foreach (['aaaaaaaaaaaaaaaa', 'bbbbbbbbbbbbbbbb', 'cccccccccccccccc'] as $i => $hash) {
        $put('vendor-'.$hash.'.tar.gz', 10 + $i);
    }

    EdgeReleaseBundle::prune($site);

    expect(collect($disk->files(EdgeReleaseBundle::prefix($site)))->map(fn ($p) => basename($p))->sort()->values()->all())->toBe([
        'd3.bbbbbbbbbbbbbbbb.tar.gz', 'd4.bbbbbbbbbbbbbbbb.tar.gz', 'd5.cccccccccccccccc.tar.gz',
        'vendor-bbbbbbbbbbbbbbbb.tar.gz', 'vendor-cccccccccccccccc.tar.gz',
    ])->and(EdgeReleaseBundle::appKey($site, 'd6', 'cccccccccccccccc'))->toBe('releases/01sitevendor/d6.cccccccccccccccc.tar.gz')
        ->and(EdgeReleaseBundle::vendorKey($site, 'cccccccccccccccc'))->toBe('releases/01sitevendor/vendor-cccccccccccccccc.tar.gz');
});

test('a wake copy is kept only for pages anyone gets, and only sent while the app sleeps', function () {
    $site = new Site;
    $site->id = '01SITEWAKECOPY';
    $dir = sys_get_temp_dir().'/dply-container-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    expect($worker)->toContain('const WAKE_COPY = true;')
        ->and($worker)->toContain('if (copy && !(await target.container.isAwake().catch(() => true))) {');

    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        return;
    }
    $helpers = 'const WAKE_COPY = true;'.Str::between($worker, 'const WAKE_COPY = true;', "\nfunction keepWakeCopy");
    $script = $helpers.<<<'JS'

    const req = (h = {}) => new Request('https://a.test/p', { headers: h });
    const res = (h = {}, status = 200) => new Response('x', { status, headers: { 'content-type': 'text/html; charset=utf-8', ...h } });
    const sticky = new Response('x', { headers: [['content-type', 'text/html'], ['set-cookie', 'dply_instance=0; Path=/']] });
    const session = new Response('x', { headers: [['content-type', 'text/html'], ['set-cookie', 'laravel_session=abc']] });
    console.log(JSON.stringify([
      wakeCopyEligible(req()), wakeCopyEligible(req({ cookie: 'a=1' })), wakeCopyEligible(req({ authorization: 'Bearer x' })),
      wakeCopyEligible(req({ 'x-dply-uptime': 't' })), wakeCopyEligible(req({ 'user-agent': 'dply-uptime/1.0' })),
      wakeCopyStorable(res()), wakeCopyStorable(sticky), wakeCopyStorable(session),
      wakeCopyStorable(res({ 'cache-control': 'private' })), wakeCopyStorable(res({}, 404)),
      wakeCopyStorable(new Response('{}', { headers: { 'content-type': 'application/json' } })),
    ]));
    JS;
    expect(json_decode(Process::run([$node, '-e', $script])->throw()->output(), true))
        ->toBe([true, false, false, false, false, true, true, false, false, false, false]);

    $site = new Site(['meta' => ['edge' => ['container' => ['wake_copy' => false]]]]);
    $site->id = '01SITEWAKEOFF';
    (new EdgeContainerDeployer)->scaffold($dir.'-off', $site, '/x/Dockerfile', 8080, []);
    expect(File::get($dir.'-off/src/index.js'))->toContain('const WAKE_COPY = false;');
});

test('a generated Dockerfile keeps .git and editor folders out of the image, adding to a repo .dockerignore', function () {
    $dir = checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'artisan' => '']);
    EdgeContainerDockerfile::prepare($dir);
    expect(File::get($dir.'/.dockerignore'))->toContain(".git\n")->toContain('.github');

    $own = checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'artisan' => '', '.dockerignore' => "node_modules\n.git\n"]);
    EdgeContainerDockerfile::prepare($own);
    $lines = explode("\n", trim(File::get($own.'/.dockerignore')));
    expect($lines[0])->toBe('node_modules')
        ->and(array_count_values($lines)['.git'])->toBe(1)
        ->and($lines)->toContain('.cursor');

    $repoDockerfile = checkout(['Dockerfile' => "FROM ruby\n"]);
    EdgeContainerDockerfile::prepare($repoDockerfile);
    expect(is_file($repoDockerfile.'/.dockerignore'))->toBeFalse();
});
