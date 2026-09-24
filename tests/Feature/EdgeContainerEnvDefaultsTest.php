<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerEnvDefaultsTest;

use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Services\Containers\EdgeContainerEnvDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

function repo(array $files): string
{
    $dir = sys_get_temp_dir().'/dply-env-defaults-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($dir.'/'.$path));
        File::put($dir.'/'.$path, $contents);
    }

    return $dir;
}

function site(): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://shop.on-dply.live']],
    ]);
}

test('laravel gets a stable app key and production defaults, without overriding what is set', function () {
    $site = site();
    $dir = repo(['artisan' => '', 'composer.json' => '{}']);

    $first = EdgeContainerEnvDefaults::ensure($site, $dir, ['APP_DEBUG' => 'true']);
    $second = EdgeContainerEnvDefaults::ensure($site, $dir, ['APP_DEBUG' => 'true', 'APP_KEY' => $first['APP_KEY']] + $first);

    expect($first['APP_KEY'])->toStartWith('base64:')
        ->and($first['APP_DEBUG'])->toBe('true')
        ->and($first['APP_URL'])->toBe('https://shop.on-dply.live')
        ->and($first['ASSET_URL'])->toBe('https://shop.on-dply.live')
        ->and($first['SESSION_DRIVER'])->toBe('cookie')
        ->and($second['APP_KEY'])->toBe($first['APP_KEY'])
        ->and(EdgeSiteEnvVar::query()->where('site_id', $site->id)->where('key', 'APP_KEY')->count())->toBe(1)
        ->and(EdgeContainerEnvDefaults::describe(['APP_DEBUG' => 'true'], $first))->toContain('APP_KEY (generated)')->not->toContain($first['APP_KEY']);

    File::deleteDirectory($dir);
});

test('laravel defaults reuse the persisted key when the deploy env snapshot is stale', function () {
    $site = site();
    $dir = repo(['artisan' => '', 'composer.json' => '{}']);

    $first = EdgeContainerEnvDefaults::ensure($site, $dir, []);
    // BuildEdgeSiteJob snapshots production env before clone. A parallel or
    // earlier deploy can persist APP_KEY while this job is still cloning —
    // the in-memory snapshot then misses the key and a blind create() 23505s.
    $second = EdgeContainerEnvDefaults::ensure($site, $dir, []);

    expect($second['APP_KEY'])->toBe($first['APP_KEY'])
        ->and(EdgeSiteEnvVar::query()->where('site_id', $site->id)->where('key', 'APP_KEY')->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->count())->toBe(1);

    File::deleteDirectory($dir);
});

test('rails gets a secret key base', function () {
    $site = site();
    $dir = repo(['Gemfile' => '', 'config/application.rb' => '']);

    $env = EdgeContainerEnvDefaults::ensure($site, $dir, []);

    expect(strlen($env['SECRET_KEY_BASE']))->toBe(128)->and($env['RAILS_ENV'])->toBe('production');
    File::deleteDirectory($dir);
});
