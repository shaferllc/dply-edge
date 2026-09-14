<?php

declare(strict_types=1);

namespace Tests\Unit\Support\Edge;

use App\Modules\Edge\Support\EdgeBuildDockerBootstrap;

test('probe detail mentions missing cli or daemon error', function () {
    $detail = EdgeBuildDockerBootstrap::probeDetail();

    expect($detail)->toBeString()->not->toBeEmpty();
});

test('local desktop environment is macOS only so Linux workers can install Docker', function () {
    config(['app.env' => 'local']);

    expect(EdgeBuildDockerBootstrap::isLocalDesktopEnvironment())->toBe(PHP_OS_FAMILY === 'Darwin');
});

test('queue user honours a configured user', function () {
    config(['edge.build.docker_user' => 'www-data']);

    expect(EdgeBuildDockerBootstrap::queueUser())->toBe('www-data');
});

test('queue user falls back to the process user when unset or invalid', function () {
    $me = posix_getpwuid(posix_geteuid())['name'];

    config(['edge.build.docker_user' => null]);
    expect(EdgeBuildDockerBootstrap::queueUser())->toBe($me);

    config(['edge.build.docker_user' => 'www-data;rm']);
    expect(EdgeBuildDockerBootstrap::queueUser())->toBe($me);
});
