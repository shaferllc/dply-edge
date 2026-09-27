<?php

declare(strict_types=1);

namespace Tests\Unit\Packages\DplyLaravelPersistentConnectionsTest;

use Dply\Laravel\DplyServiceProvider;

require_once __DIR__.'/../../../packages/laravel-dply/src/DplyServiceProvider.php';

function dplyToken(?string $value): void
{
    foreach (['DPLY_QUEUE_TOKEN', 'REDIS_PERSISTENT', 'DPLY_PERSISTENT_PDO'] as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }
    if ($value !== null) {
        putenv('DPLY_QUEUE_TOKEN='.$value);
        putenv('DPLY_PERSISTENT_PDO=1');
        $_ENV['DPLY_QUEUE_TOKEN'] = $_SERVER['DPLY_QUEUE_TOKEN'] = $value;
        $_ENV['DPLY_PERSISTENT_PDO'] = $_SERVER['DPLY_PERSISTENT_PDO'] = '1';
    }
}

function registerDply(): void
{
    config([
        'database.connections' => [
            'pgsql' => ['driver' => 'pgsql', 'options' => []],
            'mine' => ['driver' => 'mysql', 'options' => [\PDO::ATTR_PERSISTENT => false]],
            'sqlite' => ['driver' => 'sqlite'],
        ],
        'database.redis' => ['client' => 'phpredis', 'options' => ['persistent' => false], 'default' => ['database' => 0], 'cache' => ['database' => 1]],
    ]);
    (new DplyServiceProvider(app()))->register();
}

afterEach(fn () => dplyToken(null));

test('on dply, database and redis connections persist unless the app chose', function () {
    dplyToken('secret');
    registerDply();

    $pgsql = config('database.connections.pgsql.options');
    expect($pgsql[\PDO::ATTR_PERSISTENT])->toBe('dply-pgsql')
        // The one-round-trip tweak is still applied next to it.
        ->and(in_array(true, $pgsql, true))->toBeTrue()->and(count($pgsql))->toBe(2)
        ->and(config('database.connections.mine.options')[\PDO::ATTR_PERSISTENT])->toBeFalse()
        ->and(config('database.connections.sqlite.options'))->toBeNull()
        ->and(config('database.redis.options.persistent'))->toBeTrue()
        ->and(config('database.redis.default.persistent_id'))->toBe('dply-default')
        ->and(config('database.redis.cache.persistent_id'))->toBe('dply-cache');
});

test('without the image flag (frankenphp) only redis persists', function () {
    dplyToken('secret');
    putenv('DPLY_PERSISTENT_PDO');
    unset($_ENV['DPLY_PERSISTENT_PDO'], $_SERVER['DPLY_PERSISTENT_PDO']);
    registerDply();

    expect(config('database.connections.pgsql.options'))->not->toHaveKey(\PDO::ATTR_PERSISTENT)
        ->and(config('database.redis.options.persistent'))->toBeTrue();
});

test('off dply nothing persists', function () {
    dplyToken(null);
    registerDply();

    expect(config('database.connections.pgsql.options'))->toBe([])
        ->and(config('database.redis.options.persistent'))->toBeFalse();
});
