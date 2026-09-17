<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeContainerDeployTest;

use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
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

test('laravel gets a frankenphp image with assets, migrations on boot and port 8080', function () {
    $dir = checkout(['composer.json' => '{"require":{"php":"^8.3"}}', 'artisan' => '', 'package.json' => '{}']);

    $image = EdgeContainerDockerfile::prepare($dir);
    $dockerfile = File::get($image['path']);

    expect($image)->toMatchArray(['stack' => 'php', 'port' => 8080, 'generated' => true])
        ->and($dockerfile)->toContain('FROM node:22-bookworm-slim AS assets')
        ->and($dockerfile)->toContain('FROM dunglas/frankenphp:1-php8.3')
        ->and($dockerfile)->toContain('composer install --no-dev')
        ->and($dockerfile)->toContain('php artisan migrate --force --isolated')
        ->and($dockerfile)->toContain('SERVER_NAME=":8080"');
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
        ->and($worker)->toContain('defaultPort = 8080')
        ->and($worker)->toContain('getRandom(env.APP, 3)')
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
