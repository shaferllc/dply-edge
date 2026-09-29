<?php

declare(strict_types=1);

use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Support\EdgeCronExpression;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;

test('the expressions a container app can schedule', function (string $expression, bool $supported) {
    expect(EdgeCronExpression::supported($expression))->toBe($supported);
})->with([
    ['* * * * *', true],
    ['*/5 * * * *', true],
    ['0 6 * * 1-5', true],
    ['30 2 1,15 * *', true],
    ['0 9 * JAN,JUL MON', true],
    ['0 0 * * 7', true],
    ['5/15 * * * *', true],
    ['0 6 * * *   ', true],
    ['0 6 * *', false],          // four fields
    ['60 * * * *', false],       // minute out of range
    ['0 6 L * *', false],        // Cloudflare's last-day extra
    ['0 6 * * MON#2', false],
    ['0 6 * * FOO', false],
    ['0 6 * * 5-1', false],      // backwards range
    ['*/0 * * * *', false],
]);

test('the Worker’s matcher agrees: due when it should be, never for what PHP rejects', function () {
    $node = (new ExecutableFinder)->find('node');
    if ($node === null) {
        $this->markTestSkipped('node is not installed');
    }
    $site = new App\Models\Site(['meta' => ['edge' => []]]);
    $site->id = '01CRONMATCH';
    $dir = sys_get_temp_dir().'/dply-cron-test-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    $fn = 'const CRON_NAMES'.Str::betweenFirst($worker, 'const CRON_NAMES', "\n}\n")."\n}\n";
    // Monday 2026-09-28 06:00 UTC.
    $cases = [
        ['0 6 * * *', true], ['0 6 * * MON', true], ['0 6 * * 1-5', true], ['0 6 * SEP *', true],
        ['*/15 6 * * *', true], ['0 7 * * *', false], ['0 6 * * TUE', false], ['0 6 1 * *', false],
        ['0 6 28 * SUN', true], // day-of-month OR day-of-week when both are set
        ['0 6 L * *', false], ['0 6 * * FOO', false], ['nonsense', false],
    ];
    $script = $fn.'const at = new Date(Date.UTC(2026, 8, 28, 6, 0));'
        .'console.log(JSON.stringify('.json_encode(array_column($cases, 0)).'.map((e) => cronDue(e, "UTC", at, true))));';
    $result = Process::run([$node, '-e', $script]);

    expect(json_decode($result->output(), true))->toBe(array_column($cases, 1));
    foreach ($cases as [$expression, $due]) {
        if ($due) {
            expect(EdgeCronExpression::supported($expression))->toBeTrue();
        }
    }
});
