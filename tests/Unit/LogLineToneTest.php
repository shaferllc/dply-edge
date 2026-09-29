<?php

declare(strict_types=1);

use App\Support\LogLineTone;

test('log lines are coloured by what they say', function (string $line, string $tone) {
    expect(LogLineTone::classes($line))->toContain($tone);
})->with([
    ['[dply:step] publish', 'text-sky-300'],
    ['App answered HTTP 500.', 'text-rose-300'],
    ['production.ERROR: SQLSTATE[42P01]: Undefined table', 'text-rose-300'],
    ['npm WARN deprecated inflight@1.0.6', 'text-amber-300'],
    ['│  SUCCESS  Modified application dply-ctr-x-app', 'text-emerald-300'],
    ['App answered HTTP 200.', 'text-emerald-300'],
    ['#14 [stage-1 6/13] COPY dply-laravel /opt/dply/laravel', 'text-zinc-500'],
    ['#15 ERROR: failed to solve', 'text-rose-300'], // an error beats docker's dim step
]);

test('ordinary lines keep the pane colour', function () {
    expect(LogLineTone::classes('Cloning into src...'))->toBe('')
        ->and(LogLineTone::classes(''))->toBe('');
});
