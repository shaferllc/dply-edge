<?php

use App\Models\Site;
use App\Modules\Edge\Support\EdgeWorkerEntryWrapper;

test('the wrapper becomes the entry and re-exports the app entry', function () {
    $site = new Site;
    $site->forceFill(['meta' => ['edge' => ['connections' => [
        ['kind' => 'durable_object', 'name' => 'COUNTER', 'host' => 'dply.app.counter.internal', 'target' => ''],
    ]]]]);

    [$entry, $modules] = EdgeWorkerEntryWrapper::wrap($site, 'server/index.js', ['server/index.js' => 'export default {}']);

    expect($entry)->toBe('dply-entry.js')
        ->and($modules)->toHaveKeys(['server/index.js', 'dply-entry.js'])
        ->and($modules['dply-entry.js'])->toContain('import app from "./server/index.js";')
        ->and($modules['dply-entry.js'])->toContain('export * from "./server/index.js";')
        ->and($modules['dply-entry.js'])->toContain('const STATE = ["COUNTER"];');
});

test('an already wrapped upload is left alone', function () {
    $modules = ['dply-entry.js' => 'x', 'worker.js' => 'y'];

    expect(EdgeWorkerEntryWrapper::wrap(new Site, 'dply-entry.js', $modules))->toBe(['dply-entry.js', $modules]);
});

test('asleep state is not shimmed', function () {
    $site = new Site;
    $site->forceFill(['meta' => ['edge' => ['connections' => [
        ['kind' => 'durable_object', 'name' => 'COUNTER', 'host' => 'dply.app.counter.internal', 'target' => '', 'asleep' => true],
    ]]]]);

    [, $modules] = EdgeWorkerEntryWrapper::wrap($site, 'worker.js', ['worker.js' => '']);

    expect($modules['dply-entry.js'])->toContain('const STATE = [];');
});
