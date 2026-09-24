<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeContainerDeployTest;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Services\Containers\EdgeContainerRollout;
use App\Modules\Edge\Support\EdgeContainerSettings;
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

afterEach(fn () => collect(glob(sys_get_temp_dir().'/dply-container-test-*'))->each(fn ($d) => File::deleteDirectory($d)));

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
        ->and($dockerfile)->toContain('php artisan migrate --force --isolated')
        ->and($dockerfile)->toContain('RUN npm run build')
        ->and($dockerfile)->toContain('SERVER_NAME=":8080"')
        ->and(EdgeContainerDockerfile::logSummary($dockerfile))->toContain('RUN npm run build');
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
        ->and($dockerfile)->toContain('RUN npm install')
        ->and($dockerfile)->toContain('RUN npm run production')
        ->and($dockerfile)->not->toContain('npm run build --if-present')
        ->and($dockerfile)->not->toContain('package-lock.json*')
        ->and($dockerfile)->not->toContain('@php artisan migrate');
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
        ->and($config['containers'][0])->toMatchArray(['class_name' => 'App', 'image' => '/build/src/Dockerfile.dply', 'max_instances' => 4])
        ->and($config['migrations'][0]['new_sqlite_classes'])->toBe(['App'])
        ->and($config['queues']['producers'][0])->toBe(['binding' => 'JOBS', 'queue' => 'site-jobs'])
        ->and($config['queues']['consumers'][0]['queue'])->toBe('site-jobs')
        ->and($worker)->toContain("headers.set('x-forwarded-proto'")
        ->and($worker)->toContain('defaultPort = 8080')
        ->and($worker)->toContain('getRandom(env.APP, 3)')
        ->and($worker)->toContain('async function proxy(env, request)')
        ->and($worker)->toContain('portReadyTimeoutMS: 45000')
        ->and($worker)->toContain('return proxy(env, new Request(request, { headers }))')
        ->and($worker)->toContain('async function proxy(env, request)')
        ->and($worker)->toContain('portReadyTimeoutMS: 45000')
        ->and($worker)->toContain('return proxy(env, new Request(request, { headers }))')
        ->and($worker)->toContain('"/_dply/queue/send"')
        ->and($worker)->toContain("request.headers.get('x-dply-queue-token') !== env.DPLY_QUEUE_TOKEN")
        ->and($worker)->toContain('{"site-jobs":"JOBS"}')
        ->and($worker)->not->toContain('__');
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
        ->and(File::get($dir.'/src/index.js'))->toContain('"/_dply/schedule"');
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

test('a laravel site on lite is raised to basic and the fpm pool fits that memory', function () {
    $site = new Site(['meta' => ['edge' => ['build' => ['framework' => 'laravel'], 'container' => ['instance_type' => 'lite']]]]);

    expect(EdgeContainerSettings::for($site)['instance_type'])->toBe('basic')
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

    expect($rolling['in_progress'])->toBeTrue()
        ->and($rolling['percentage'])->toBe(0)
        ->and($done['in_progress'])->toBeFalse()
        ->and(EdgeContainerRollout::rolloutProgress([])['in_progress'])->toBeTrue();
});

test('wrangler gets a spare instance so a gradual rollout can start the new image', function () {
    expect(EdgeContainerSettings::wranglerMaxInstances(1))->toBe(2)
        ->and(EdgeContainerSettings::wranglerMaxInstances(5))->toBe(6)
        ->and(EdgeContainerSettings::wranglerMaxInstances(20))->toBe(21);
});
