<?php

declare(strict_types=1);

use Dply\Laravel\DplyKvStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../packages/laravel-dply/src/DplyKvStore.php';

test('flush follows the cursor past the first page', function () {
    Http::fake([
        'kv.internal/?cursor=p2' => Http::response(['keys' => ['c'], 'cursor' => null]),
        'kv.internal/' => Http::response(['keys' => ['a', 'b'], 'cursor' => 'p2']),
        '*' => Http::response(null, 204),
    ]);

    expect((new DplyKvStore('kv.internal'))->flush())->toBeTrue();

    $deleted = collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => $r->method() === 'DELETE')->map(fn (Request $r) => $r->url())->values()->all();
    expect($deleted)->toBe(['http://kv.internal/a', 'http://kv.internal/b', 'http://kv.internal/c']);
});

test('many reads up to 100 keys per request and decodes serialized values', function () {
    Http::fake(fn (Request $r) => Http::response(['values' => collect($r['keys'])->mapWithKeys(fn ($k) => [$k => $k === 'k0' ? serialize(['x' => 1]) : null])->all()]));

    $found = (new DplyKvStore('kv.internal'))->many(array_map(fn ($i) => 'k'.$i, range(0, 149)));

    expect($found['k0'])->toBe(['x' => 1])
        ->and($found['k149'])->toBeNull()
        ->and($found)->toHaveCount(150);
    Http::assertSentCount(2);
});

test('putMany writes every key and increment refuses', function () {
    Http::fake(['*' => Http::response(null, 204)]);
    $store = new DplyKvStore('kv.internal');

    expect($store->putMany(['a' => 1, 'b' => 2], 120))->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === 'http://kv.internal/b' && $r->header('x-dply-ttl') === ['120']);
    Http::assertSentCount(2);

    expect(fn () => $store->increment('hits'))->toThrow(BadMethodCallException::class, "can't count atomically");
});
