<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge;

use App\Modules\Edge\Services\NodeVersionDetector;
use Illuminate\Support\Str;

function fakeRepo(array $files): string
{
    $dir = sys_get_temp_dir().'/dply-node-detect-'.Str::random(8);
    if (! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        throw new \RuntimeException("Could not create temp dir: {$dir}");
    }

    foreach ($files as $relativePath => $contents) {
        file_put_contents($dir.'/'.$relativePath, $contents);
    }

    return $dir;
}

function detect(array $files): array
{
    $dir = fakeRepo($files);

    try {
        return app(NodeVersionDetector::class)->detect($dir);
    } finally {
        foreach (glob($dir.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir);
    }
}

test('falls back to default Node 22 when nothing is declared', function () {
    $result = detect([]);

    expect($result['detected'])->toBeFalse()
        ->and($result['major'])->toBe(22)
        ->and($result['image'])->toBe('node:22-bookworm')
        ->and($result['source'])->toBe('default');
});

test('picks engines.node major over other sources', function () {
    $result = detect([
        'package.json' => json_encode(['engines' => ['node' => '>=22.12.0']]),
        '.nvmrc' => '20',
    ]);

    expect($result['detected'])->toBeTrue()
        ->and($result['source'])->toBe('package.json#engines.node')
        // >=22.12 → lowest supported satisfying = 22 (don't jump to 24)
        ->and($result['major'])->toBe(22)
        ->and($result['image'])->toBe('node:22-bookworm');
});

test('open-ended engines.node >=18 never drops below the default LTS', function () {
    // eleventy-base-blog ships ">=18" while sharp needs >=20.9 — Node 18 broke the build.
    $result = detect([
        'package.json' => json_encode(['engines' => ['node' => '>=18']]),
    ]);

    expect($result['major'])->toBe(22);
});

test('capped engines.node range picks the lowest satisfying LTS', function () {
    $result = detect([
        'package.json' => json_encode(['engines' => ['node' => '>=16 <19']]),
    ]);

    expect($result['major'])->toBe(18);
});

test('caret range pins to the named major', function () {
    $result = detect([
        'package.json' => json_encode(['engines' => ['node' => '^20.10.0']]),
    ]);

    expect($result['major'])->toBe(20)
        ->and($result['image'])->toBe('node:20-bookworm');
});

test('plain major in engines.node is honored', function () {
    $result = detect([
        'package.json' => json_encode(['engines' => ['node' => '22.x']]),
    ]);

    expect($result['major'])->toBe(22);
});

test('.nvmrc is used when package.json has no engines block', function () {
    $result = detect([
        'package.json' => json_encode(['name' => 'demo']),
        '.nvmrc' => 'v20.10.0',
    ]);

    expect($result['detected'])->toBeTrue()
        ->and($result['source'])->toBe('.nvmrc')
        ->and($result['major'])->toBe(20);
});

test('.node-version is used when .nvmrc is missing', function () {
    $result = detect([
        '.node-version' => '18.18.0',
    ]);

    expect($result['source'])->toBe('.node-version')
        ->and($result['major'])->toBe(18);
});

test('packageManager pin implies a Node floor', function () {
    $result = detect([
        'package.json' => json_encode(['packageManager' => 'pnpm@11.3.0']),
    ]);

    expect($result['detected'])->toBeTrue()
        ->and($result['source'])->toBe('package.json#packageManager')
        ->and($result['major'])->toBe(22);
});

test('older pnpm pins map to older Node floors', function () {
    expect(detect(['package.json' => json_encode(['packageManager' => 'pnpm@10.0.0'])])['major'])->toBe(20);
    expect(detect(['package.json' => json_encode(['packageManager' => 'pnpm@9.0.0'])])['major'])->toBe(18);
});

test('lts/* aliases are ignored and fall through to default', function () {
    $result = detect([
        '.nvmrc' => 'lts/iron',
    ]);

    expect($result['detected'])->toBeFalse()
        ->and($result['major'])->toBe(22);
});

test('odd-numbered Node majors round up to the next supported LTS', function () {
    $result = detect([
        '.nvmrc' => '21',
    ]);

    expect($result['major'])->toBe(22);
});
