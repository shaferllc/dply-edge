<?php

declare(strict_types=1);

use App\Modules\Edge\Services\EdgeBuildRunner;
use Illuminate\Support\Facades\Process;

/**
 * The flags every customer-code `docker run` gets (build + middleware
 * bundle): see docs/edge-build-isolation.md.
 */
beforeEach(function (): void {
    // Network readiness is cached per process; reset between tests.
    (new ReflectionProperty(EdgeBuildRunner::class, 'readyNetworks'))->setValue(null, []);
    config()->set('edge.build.sandbox', [
        'user' => '1000:1000',
        'memory' => '4g',
        'cpus' => '2',
        'pids_limit' => '2048',
        'network' => 'dply-builds',
        'bridge_name' => 'dply-builds0',
        'subnet' => '172.30.0.0/16',
        'sinkhole_hosts' => ['host.docker.internal', 'metadata.google.internal'],
    ]);
});

test('sandbox flags drop privileges, cap resources and use the build network', function () {
    Process::fake();

    $flags = EdgeBuildRunner::sandboxFlags();

    expect($flags)->toContain('--cap-drop=ALL')
        ->toContain('--security-opt=no-new-privileges')
        ->toContain('--memory=4g')
        ->toContain('--memory-swap=4g')
        ->toContain('--cpus=2')
        ->toContain('--pids-limit=2048')
        ->toContain('--network=dply-builds')
        ->toContain('--add-host=host.docker.internal:127.0.0.1')
        ->toContain('--add-host=metadata.google.internal:127.0.0.1')
        ->toContain('HOME=/tmp')
        ->and(implode(' ', $flags))->toContain('--user 1000:1000')
        ->and(collect($flags)->filter(fn ($f) => str_starts_with($f, '--cap-add')))->toBeEmpty();
});

test('the build network is created with ICC off, a fixed bridge and subnet', function () {
    $created = false;
    Process::fake(function ($p) use (&$created) {
        if (($p->command[2] ?? '') === 'create') {
            $created = true;
        }

        return ($p->command[2] ?? '') === 'inspect' && ! $created
            ? Process::result('', 'No such network', 1)
            : Process::result();
    });

    EdgeBuildRunner::sandboxFlags();
    EdgeBuildRunner::sandboxFlags();

    Process::assertRan(fn ($p) => is_array($p->command)
        && implode(' ', $p->command) === 'docker network create --driver bridge -o com.docker.network.bridge.enable_icc=false -o com.docker.network.bridge.name=dply-builds0 --subnet 172.30.0.0/16 dply-builds');
    // Cached for the rest of the process.
    Process::assertRanTimes(fn ($p) => is_array($p->command) && ($p->command[2] ?? '') === 'create', 1);
});

test('losing the network create race to another build is fine', function () {
    $inspects = 0;
    Process::fake(function ($p) use (&$inspects) {
        if (($p->command[2] ?? '') === 'inspect') {
            return ++$inspects === 1 ? Process::result('', 'No such network', 1) : Process::result();
        }

        return Process::result('', 'network with name dply-builds already exists', 1);
    });

    expect(EdgeBuildRunner::sandboxFlags())->toContain('--network=dply-builds');
});

test('root mode adds back only the capabilities that writing the mounts needs', function () {
    Process::fake();
    config()->set('edge.build.sandbox.user', 'root');

    $flags = EdgeBuildRunner::sandboxFlags();

    expect($flags)->toContain('--cap-drop=ALL')
        ->toContain('--cap-add=CHOWN')
        ->toContain('--cap-add=DAC_OVERRIDE')
        ->not->toContain('--user')
        ->and(EdgeBuildRunner::sandboxScript('corepack enable && pnpm i'))->toBe('corepack enable && pnpm i');
});

test('the non-root script gets a writable PATH prefix and corepack install dir', function () {
    $script = EdgeBuildRunner::sandboxScript('corepack enable && corepack prepare --activate && pnpm build');

    expect($script)
        ->toStartWith('mkdir -p "$NPM_CONFIG_PREFIX/bin" && export PATH="$NPM_CONFIG_PREFIX/bin:$PATH" && ')
        ->toContain('corepack enable --install-directory "$NPM_CONFIG_PREFIX/bin" && corepack prepare --activate');
});
