<?php

declare(strict_types=1);

namespace Tests\Unit\Config\ConfigDirectoryAliasesTest;

use App\Support\Config\ConfigDirectoryAliases;

test('every alias key equals its nested tree', function () {
    ConfigDirectoryAliases::apply();

    foreach (ConfigDirectoryAliases::MAP as $legacy => $nested) {
        $path = ConfigDirectoryAliases::pathForNestedKey($nested);

        expect(is_file($path))->toBeTrue("Missing [{$path}] for [{$nested}].");
        expect(config($legacy))->toEqual(require $path, "Alias [{$legacy}] does not match [{$nested}].");
    }
});

test('legacy call sites still resolve after the folder move', function () {
    expect(config('server_logs.sources'))->toBeArray()->not->toBeEmpty();
    expect(config('feedback.types'))->toBeArray()->not->toBeEmpty();
    expect(config('dply_runtime.mode'))->toBeString()->not->toBeEmpty();
    expect(config('dply.queues'))->toBeArray()->toHaveKeys(['interactive', 'background']);
    expect(config('edge.r2'))->toBeArray();
    expect(config('insights.thresholds'))->toBeArray();
    expect(config('insights_eol'))->toBeArray();
    expect(config('insights_playbooks'))->toBeArray();
    expect(config('sites.supervisor_systemd_unit'))->toBeString()->not->toBeEmpty();
    expect(config('notifications.retention_days'))->toBeInt();
    expect(config('deploy.health_check_default'))->not->toBeNull();
    expect(config('serverless'))->toBeArray();
    expect(config('testing_domains.vm_apex'))->toBeString()->not->toBeEmpty();
    expect(config('testing_domains.vm'))->toBeArray()->not->toBeEmpty();
});

test('config root only keeps Laravel, packages, and Pennant landmines', function () {
    $root = collect(glob(config_path('*.php')) ?: [])
        ->map(fn (string $path): string => basename($path))
        ->sort()
        ->values();

    expect($root->all())->toBe(ConfigDirectoryAliases::ROOT_ALLOW_LIST);

    expect(is_file(config_path('product/dply.php')))->toBeTrue();
    expect(is_file(config_path('product/edge.php')))->toBeTrue();
    expect(is_file(config_path('insights/core.php')))->toBeTrue();
    expect(is_file(config_path('sites/core.php')))->toBeTrue();
    expect(is_file(config_path('servers/logs.php')))->toBeTrue();
});
