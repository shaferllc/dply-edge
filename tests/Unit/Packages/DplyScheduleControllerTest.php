<?php

declare(strict_types=1);

use Dply\Laravel\ScheduleController;

require_once __DIR__.'/../../../packages/laravel-dply/src/ScheduleController.php';

test('a registered artisan command runs through Artisan, anything else through the shell', function () {
    expect(ScheduleController::isArtisan('migrate --force'))->toBeTrue()
        ->and(ScheduleController::isArtisan('schedule:run'))->toBeTrue()
        ->and(ScheduleController::isArtisan('php scripts/cleanup.php'))->toBeFalse()
        ->and(ScheduleController::isArtisan('node bin/sync.js --all'))->toBeFalse();
});

test('the command list has the app’s own commands first, then the framework’s', function () {
    require_once __DIR__.'/../../../packages/laravel-dply/src/CommandController.php';
    config(['queue.connections.dply.token' => 'secret']);
    $request = Illuminate\Http\Request::create('/_dply/command', 'POST', ['command' => 'commands']);
    $request->headers->set('x-dply-queue-token', 'secret');

    $commands = (new Dply\Laravel\CommandController)($request)->getData(true)['commands'];
    $firstFramework = collect($commands)->search(fn ($c) => ! $c['app']);

    expect(collect($commands)->pluck('name'))->toContain('migrate')
        ->and(collect($commands)->slice($firstFramework)->contains('app', true))->toBeFalse();
});

test('a database command runs against another dply database by its env prefix', function () {
    require_once __DIR__.'/../../../packages/laravel-dply/src/CommandController.php';
    config(['queue.connections.dply.token' => 'secret']);
    $call = function (array $input) {
        $request = Illuminate\Http\Request::create('/_dply/command', 'POST', $input);
        $request->headers->set('x-dply-queue-token', 'secret');

        return (new Dply\Laravel\CommandController)($request);
    };

    expect($call(['command' => 'status', 'database' => 'NOPE'])->getData(true)['error'])->toContain('no NOPE_DB_CONNECTION');

    putenv('REPORTS_DB_CONNECTION=sqlite');
    putenv('REPORTS_DB_DATABASE=:memory:');
    try {
        $call(['command' => 'status', 'database' => 'REPORTS']);
        expect(config('database.connections.reports.driver'))->toBe('sqlite')
            ->and(config('database.connections.reports.database'))->toBe(':memory:');
    } finally {
        putenv('REPORTS_DB_CONNECTION');
        putenv('REPORTS_DB_DATABASE');
    }
});
