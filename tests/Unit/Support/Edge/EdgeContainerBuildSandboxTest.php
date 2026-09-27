<?php

declare(strict_types=1);

use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;

/**
 * Container image builds: customer Dockerfiles build in the isolated BuildKit
 * builder, not the host daemon. See docs/edge-build-isolation.md.
 */
beforeEach(function (): void {
    config()->set('edge.cloudflare.api_token', 'platform-token');
    config()->set('edge.cloudflare.account_id', 'acct');
    config()->set('edge.build.sandbox.network', 'dply-builds');
    config()->set('edge.build.containers', array_merge((array) config('edge.build.containers'), [
        'deployer_image' => 'dply/edge-container-deployer:1',
        'builder' => 'dply-builds',
        'builder_image' => 'moby/buildkit:v0.32.2',
        'builder_memory' => '8g',
        'builder_cpus' => '4',
        'deploy_api_token' => null,
    ]));
});

function containerBuildDeployerCommand(): array
{
    return EdgeContainerDeployer::deployerCommand('dply-ctr-build-1', '/w/b1', '/w/b1/container-worker', 'ns', 'immediate');
}

test('wrangler builds through the isolated builder on the build network', function () {
    $cmd = containerBuildDeployerCommand();
    $script = $cmd[array_search('-c', $cmd, true) + 1];

    expect($cmd)->toContain('BUILDX_BUILDER=dply-builds')
        ->and($script)->toStartWith("{ docker buildx inspect 'dply-builds' >/dev/null 2>&1 || docker buildx create --name 'dply-builds' --driver docker-container")
        ->toContain("--driver-opt 'image=moby/buildkit:v0.32.2'")
        ->toContain("--driver-opt 'network=dply-builds'")
        ->toContain("--driver-opt 'memory=8g' --driver-opt 'memory-swap=8g'")
        ->toContain("--driver-opt 'cpu-period=100000' --driver-opt 'cpu-quota=400000'")
        // A missing builder aborts the deploy instead of building on the host daemon.
        ->toContain('>/dev/null; } && npm install')
        ->toContain('--ignore-scripts')
        ->not->toContain('WRANGLER_CI_OVERRIDE_NETWORK_MODE_HOST');
    expect(array_slice($cmd, -2))->toBe(['ns', 'immediate']);
});

test('the Cloudflare token reaches wrangler only as deployer env, never the build', function () {
    $cmd = containerBuildDeployerCommand();
    $script = $cmd[array_search('-c', $cmd, true) + 1];

    expect($cmd)->toContain('CLOUDFLARE_API_TOKEN=platform-token')
        ->and($script)->not->toContain('platform-token')->not->toContain('CLOUDFLARE')->not->toContain('--build-arg');

    config()->set('edge.build.containers.deploy_api_token', 'narrow-token');
    expect(containerBuildDeployerCommand())->toContain('CLOUDFLARE_API_TOKEN=narrow-token')->not->toContain('CLOUDFLARE_API_TOKEN=platform-token');
});

test('an empty builder falls back to the host default builder', function () {
    config()->set('edge.build.containers.builder', '');
    $cmd = containerBuildDeployerCommand();

    expect(implode(' ', $cmd))->not->toContain('BUILDX_BUILDER')->not->toContain('buildx')
        ->and($cmd[array_search('-c', $cmd, true) + 1])->toStartWith('npm install');
});

test('every cache mount id is org-scoped, explicit ones included', function () {
    $dockerfile = "RUN --mount=type=cache,target=/root/.npm npm ci\n"
        ."RUN --mount=target=/tmp/c,type=cache,sharing=locked composer install\n"
        ."RUN --mount=type=cache,id=pnpm,target=/pnpm/store pnpm i\n"
        ."RUN --mount=type=bind,target=/src true\n";
    $scoped = EdgeContainerDeployer::scopeCacheMounts($dockerfile, 'abc');

    expect($scoped)->toBe(
        "RUN --mount=id=dply-abc-root/.npm,type=cache,target=/root/.npm npm ci\n"
        ."RUN --mount=id=dply-abc-tmp/c,target=/tmp/c,type=cache,sharing=locked composer install\n"
        ."RUN --mount=id=dply-abc-pnpm,type=cache,target=/pnpm/store pnpm i\n"
        ."RUN --mount=type=bind,target=/src true\n",
    )->and(EdgeContainerDeployer::scopeCacheMounts($scoped, 'abc'))->toBe($scoped);
});

test('composer ext- names that are not extension names never reach install-php-extensions', function () {
    $delta = EdgeContainerDockerfile::extraPhpExtensions(
        ['require' => ['ext-zzfake' => '*', 'ext-x;curl${IFS}169.254.169.254|sh;#' => '*', 'ext--flag' => '*']],
        [],
    );

    expect($delta)->toBe('zzfake');
});
