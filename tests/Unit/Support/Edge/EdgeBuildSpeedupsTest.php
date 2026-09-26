<?php

declare(strict_types=1);

use App\Modules\Edge\Services\EdgeBuildRunner;
use Illuminate\Support\Facades\File;

/**
 * Unit cover for Edge build speedups that don't need Docker/git:
 * monorepo filtered install scripts + package-store volume flags.
 */
function invokePrivate(EdgeBuildRunner $runner, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($runner, $method);
    $ref->setAccessible(true);

    return $ref->invoke($runner, ...$args);
}

afterEach(function (): void {
    File::deleteDirectory(storage_path('framework/testing/edge-mono'));
});

test('composeBuildScript uses pnpm --filter for workspace package roots', function () {
    $root = storage_path('framework/testing/edge-mono');
    $pkg = $root.'/apps/web';
    File::ensureDirectoryExists($pkg);
    File::put($root.'/pnpm-workspace.yaml', "packages:\n  - 'apps/*'\n");
    File::put($root.'/pnpm-lock.yaml', "lockfileVersion: '9.0'\n");
    File::put($root.'/package.json', json_encode(['name' => 'mono', 'private' => true], JSON_THROW_ON_ERROR));
    File::put($pkg.'/package.json', json_encode(['name' => '@demo/web'], JSON_THROW_ON_ERROR));

    config()->set('edge.build.monorepo_filter_install', true);

    $script = invokePrivate(new EdgeBuildRunner, 'composeBuildScript', [
        $pkg,
        'npm run build',
        $root,
        'apps/web',
    ]);

    expect($script)
        ->toContain('--filter')
        ->toContain('./apps/web...')
        ->toContain('cd /src &&')
        ->toContain('pnpm run --if-present build');
});

test('packageStoreVolumeFlags mounts host npm/pnpm/yarn caches', function () {
    $store = storage_path('framework/testing/edge-mono/pkg-store');
    config()->set('edge.build.package_store_enabled', true);
    config()->set('edge.build.package_store_dir', $store);

    $flags = invokePrivate(new EdgeBuildRunner, 'packageStoreVolumeFlags');

    expect($flags)->toContain('-v')
        ->and($flags)->toContain($store.'/npm:/npm-cache')
        ->and($flags)->toContain($store.'/pnpm:/pnpm-store')
        ->and(is_dir($store.'/npm'))->toBeTrue();
});

test('dockerEnvFlags point package managers at mounted stores', function () {
    config()->set('edge.build.package_store_enabled', true);

    $flags = invokePrivate(new EdgeBuildRunner, 'dockerEnvFlags', [[]]);

    $joined = implode(' ', $flags);
    expect($joined)->toContain('PNPM_STORE_DIR=/pnpm-store')
        ->and($joined)->toContain('npm_config_cache=/npm-cache');
});

test('warm images are the exact tags builds and generated images use', function () {
    $images = config('edge.build.warm_images');

    // The build image, the generated Node/PHP images, and composer.
    expect($images)->toContain(config('edge.build.docker_image'))
        ->and($images)->toContain('node:22-bookworm-slim')
        ->and($images)->toContain('composer:2')
        ->and($images)->toContain('php:8.4-fpm-alpine');
});
