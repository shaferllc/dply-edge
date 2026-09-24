<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\FrontendAssetBuildTest;

use App\Modules\Edge\Services\RuntimeDetection\FrontendAssetBuild;
use App\Modules\Edge\Services\RuntimeDetection\PhpRuntimeDetector;
use Illuminate\Support\Facades\File;

function checkout(array $files): string
{
    $dir = sys_get_temp_dir().'/dply-frontend-assets-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $contents) {
        File::ensureDirectoryExists(dirname($dir.'/'.$path));
        File::put($dir.'/'.$path, $contents);
    }

    return $dir;
}

afterEach(fn () => collect(glob(sys_get_temp_dir().'/dply-frontend-assets-*'))->each(fn ($d) => File::deleteDirectory($d)));

test('default laravel vite package.json uses npm install not npm ci', function () {
    $package = ['scripts' => ['build' => 'vite build']];

    expect(FrontendAssetBuild::commandForPackage($package))->toBe('npm install && npm run build');
});

test('a lockfile at the checkout picks the matching installer', function () {
    $dir = checkout([
        'package.json' => '{"scripts":{"build":"vite build"}}',
        'package-lock.json' => '{}',
    ]);

    expect(FrontendAssetBuild::commandForDirectory($dir))->toBe('npm ci && npm run build');
});

test('package.json without a build script is ignored', function () {
    expect(FrontendAssetBuild::commandForPackage(['scripts' => ['dev' => 'vite']]))->toBeNull();
});

test('laravel mix production script is used when there is no build script', function () {
    expect(FrontendAssetBuild::commandForPackage(['scripts' => ['production' => 'mix --production']]))
        ->toBe('npm install && npm run production');
});

test('composer setup npm run build is used when package.json has no build script', function () {
    $dir = checkout([
        'package.json' => '{"scripts":{"dev":"vite"}}',
        'composer.json' => '{"scripts":{"setup":["composer install","npm install","npm run build"]}}',
    ]);

    expect(FrontendAssetBuild::commandForDirectory($dir))->toBe('npm install && npm run build');
});

test('laravel mix production script is the compile step', function () {
    $package = ['scripts' => ['dev' => 'npm run development', 'production' => 'mix --production']];

    expect(FrontendAssetBuild::commandForPackage($package))->toBe('npm install && npm run production');
});

test('composer setup npm lines are the compile step and migrate is left out', function () {
    $composer = [
        'scripts' => [
            'setup' => [
                'composer install',
                '@php artisan key:generate',
                '@php artisan migrate --force',
                'npm install',
                'npm run build',
            ],
            'dev' => [
                'npx concurrently "php artisan serve" "npm run dev"',
            ],
            'post-update-cmd' => [
                '@php artisan vendor:publish --tag=laravel-assets --ansi --force',
            ],
        ],
    ];

    expect(FrontendAssetBuild::commandForPackage([], null, $composer))
        ->toBe('npm install && npm run build')
        ->and(FrontendAssetBuild::phpAssetCommands($composer))
        ->toBe(['php artisan vendor:publish --tag=laravel-assets --ansi --force']);
});

test('a workspace package build runs when the root package.json has no build script', function () {
    $dir = checkout([
        'package.json' => '{"scripts":{"postinstall":"patch-package"},"workspaces":["resources/assets/v3"]}',
        'package-lock.json' => '{}',
        'resources/assets/v3/package.json' => '{"scripts":{"build":"vite build"}}',
    ]);

    expect(FrontendAssetBuild::commandForDirectory($dir))
        ->toBe('npm ci && npm run build --prefix resources/assets/v3');
});

test('a pnpm lockfile runs the build with pnpm', function () {
    $dir = checkout([
        'package.json' => '{"scripts":{"build":"vite build"}}',
        'pnpm-lock.yaml' => '',
    ]);

    expect(FrontendAssetBuild::commandForDirectory($dir))
        ->toBe('pnpm install --frozen-lockfile && pnpm run build');
});

test('php detector appends the vite build on a default laravel tree', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.2","laravel/framework":"^12.0"}}',
        'artisan' => '',
        'package.json' => '{"scripts":{"build":"vite build"}}',
    ]);

    $detection = (new PhpRuntimeDetector)->detect($dir);

    expect($detection?->framework)->toBe('laravel')
        ->and($detection?->buildCommand)->toBe('composer install --no-dev --optimize-autoloader && npm install && npm run build')
        ->and($detection?->detectedFiles)->toContain('package.json');
});

test('php detector stays composer-only when there is no frontend build', function () {
    $dir = checkout([
        'composer.json' => '{"require":{"php":"^8.2"}}',
    ]);

    expect((new PhpRuntimeDetector)->detect($dir)?->buildCommand)
        ->toBe('composer install --no-dev --optimize-autoloader');
});
