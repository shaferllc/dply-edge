<?php

declare(strict_types=1);

use App\Models\Site;
use App\Modules\Billing\Support\UsagePrice;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\Containers\EdgeContainerDockerfile;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Modules\Edge\Support\EdgeSizeLadder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

function sizingSite(string $type, string $framework = 'laravel'): Site
{
    $site = new Site(['meta' => ['edge' => ['build' => ['framework' => $framework], 'container' => ['instance_type' => $type]]]]);
    $site->id = '01SIZING';

    return $site;
}

/** @return array{wrangler: array<string, mixed>, worker: string} */
function sizingScaffold(Site $site, string $phpServer = 'fpm'): array
{
    $dir = sys_get_temp_dir().'/dply-sizing-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, [], [], '', false, $phpServer);
    $out = ['wrangler' => json_decode(File::get($dir.'/wrangler.jsonc'), true), 'worker' => File::get($dir.'/src/index.js')];
    File::deleteDirectory($dir);

    return $out;
}

test('the 1 and 2 vCPU rungs deploy as Cloudflare custom shapes; named and legacy types deploy by name', function () {
    expect(sizingScaffold(sizingSite('custom-1'))['wrangler']['containers'][0]['instance_type'])->toBe(['vcpu' => 1, 'memory_mib' => 3072, 'disk_mb' => 6000])
        ->and(sizingScaffold(sizingSite('custom-2'))['wrangler']['containers'][0]['instance_type'])->toBe(['vcpu' => 2, 'memory_mib' => 6144, 'disk_mb' => 12000])
        ->and(sizingScaffold(sizingSite('basic'))['wrangler']['containers'][0]['instance_type'])->toBe('basic')
        ->and(sizingScaffold(sizingSite('standard-2'))['wrangler']['containers'][0]['instance_type'])->toBe('standard-2');

    // Every custom-* shape is one Cloudflare accepts.
    foreach (EdgeContainerSettings::INSTANCE_TYPES as $type => [$vcpu, $memory, $disk]) {
        if (str_starts_with($type, 'custom-')) {
            expect(EdgeContainerSettings::customError((int) $vcpu, (int) $memory, (int) $disk))->toBeNull();
        }
    }
});

test('legacy sizes keep working at their real shape but are not offered', function () {
    $legacy = sizingSite('standard-2');

    expect(EdgeContainerSettings::for($legacy)['instance_type'])->toBe('standard-2')
        ->and(EdgeContainerSettings::shape($legacy)['memory_gib'])->toBe(6.0) // billed for the 6 GiB it runs
        ->and(EdgeSizeLadder::containerLabel('standard-2'))->toBe('1 vCPU · 6 GB (retired)')
        ->and(EdgeSizeLadder::containerLabel('custom-1'))->toBe('1 vCPU')
        ->and(array_keys(EdgeContainerSettings::offeredTypes()))->toBe(['lite', 'basic', 'standard-1', 'custom-1', 'custom-2', 'standard-4'])
        ->and(array_keys(EdgeContainerSettings::offeredTypes('standard-3')))->toContain('standard-3')
        ->and(collect(UsagePrice::sizes())->pluck('app.memory')->all())->toBe(['1 GB', '4 GB', '3 GB', '6 GB', '12 GB']);
});

test('a memory bump always adds memory and never lands on a legacy size', function () {
    foreach (array_keys(EdgeContainerSettings::INSTANCE_TYPES) as $type) {
        $next = EdgeContainerSettings::nextLarger($type);
        if ($next === $type) {
            expect($type)->toBe('standard-4');

            continue;
        }
        expect(EdgeContainerSettings::INSTANCE_TYPES[$next][1])->toBeGreaterThan(EdgeContainerSettings::INSTANCE_TYPES[$type][1])
            ->and(EdgeSizeLadder::LEGACY_CONTAINER_TYPES)->not->toHaveKey($next);
    }
    expect(EdgeContainerSettings::nextLarger('basic'))->toBe('custom-1');
});

test('php workers per instance come from memory', function () {
    $fpm = fn (string $type): int => EdgeContainerSettings::phpFpmPool($type)['max_children'];
    $octane = fn (string $type): int => EdgeContainerSettings::phpFpmPool($type, null, 'swoole')['max_children'];

    expect(array_map($fpm, ['lite', 'basic', 'standard-1', 'custom-1', 'custom-2', 'standard-4']))->toBe([1, 12, 16, 32, 64, 128])
        ->and(array_map($octane, ['basic', 'standard-1', 'custom-1']))->toBe([8, 16, 30])
        ->and(EdgeContainerSettings::requestsPerInstance(sizingSite('basic')))->toBe(12)
        ->and(EdgeContainerSettings::requestsPerInstance(sizingSite('basic', 'nextjs')))->toBe(50); // not PHP

    // Every worker's average fits beside the reserve.
    foreach (['basic', 'standard-1', 'custom-1', 'custom-2', 'standard-4'] as $type) {
        expect($fpm($type) * EdgeContainerSettings::PHP_WORKER_MB + EdgeContainerSettings::PHP_RESERVED_MB)
            ->toBeLessThanOrEqual((int) (EdgeContainerSettings::INSTANCE_TYPES[$type][1] * 1024));
    }
});

test('the generated worker and image pass the pool size to fpm, frankenphp and octane', function () {
    expect(sizingScaffold(sizingSite('basic'))['worker'])->toContain("DPLY_PHP_FPM_MAX_CHILDREN: '12'")
        ->and(sizingScaffold(sizingSite('custom-1'))['worker'])->toContain("DPLY_PHP_FPM_MAX_CHILDREN: '32'")
        ->and(sizingScaffold(sizingSite('basic'), 'swoole')['worker'])->toContain("DPLY_PHP_FPM_MAX_CHILDREN: '8'");

    $dockerfile = function (array $require): string {
        $dir = sys_get_temp_dir().'/dply-sizing-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/composer.json', json_encode(['require' => ['php' => '^8.3'] + $require]));
        File::put($dir.'/artisan', '');
        $contents = File::get(EdgeContainerDockerfile::prepare($dir)['path']);
        File::deleteDirectory($dir);

        return $contents;
    };

    expect($dockerfile([]))->toContain('pm.max_children = %s')
        ->and($dockerfile(['laravel/octane' => '*', 'ext-swoole' => '*']))->toContain('--workers=\"${DPLY_PHP_FPM_MAX_CHILDREN:-2}\"');
});

test('every generated php start command is valid shell', function () {
    $cases = [
        'fpm' => ['composer.json' => ['require' => ['php' => '^8.3']]],
        'swoole' => ['composer.json' => ['require' => ['php' => '^8.3', 'laravel/octane' => '*']]],
        'roadrunner' => ['composer.json' => ['require' => ['php' => '^8.3', 'laravel/octane' => '*', 'spiral/roadrunner-http' => '*']]],
        'ssr' => ['composer.json' => ['require' => ['php' => '^8.3']], 'package.json' => ['scripts' => ['build' => 'vite build', 'build:ssr' => 'vite build && vite build --ssr'], 'dependencies' => ['@inertiajs/vue3' => '^2']], 'resources/js/ssr.ts' => ''],
    ];
    foreach ($cases as $name => $files) {
        $dir = sys_get_temp_dir().'/dply-sizing-test-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        File::put($dir.'/artisan', '');
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($dir.'/'.$path));
            File::put($dir.'/'.$path, is_array($contents) ? json_encode($contents) : $contents);
        }
        $dockerfile = File::get(EdgeContainerDockerfile::prepare($dir)['path']);
        File::deleteDirectory($dir);
        preg_match('/^CMD (\[.*\])$/m', $dockerfile, $m);
        $cmd = json_decode($m[1], true)[2];
        $check = Process::run(['sh', '-n', '-c', $cmd]);

        expect($check->successful())->toBeTrue($name.': '.$check->errorOutput());
        if ($name === 'ssr') {
            expect($cmd)->toContain('DPLY_PHP_FPM_MAX_CHILDREN=$((c - 2))');
        }
    }
});
