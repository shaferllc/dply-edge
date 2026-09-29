<?php

declare(strict_types=1);

use App\Modules\Edge\Support\EdgeQueueNames;
use Illuminate\Support\Facades\File;

function queueApp(array $files): string
{
    $dir = sys_get_temp_dir().'/dply-queue-scan-'.bin2hex(random_bytes(4));
    foreach ($files as $path => $body) {
        File::ensureDirectoryExists(dirname($dir.'/'.$path));
        File::put($dir.'/'.$path, $body);
    }

    return $dir;
}

test('queue names come from config and code, most urgent first', function () {
    $dir = queueApp([
        'config/queue.php' => "<?php return ['connections' => ['redis' => ['queue' => env('REDIS_QUEUE', 'default')], 'database' => ['queue' => 'default']]];",
        'app/Jobs/SendInvoice.php' => "<?php class SendInvoice { public \$queue = 'emails'; }",
        'app/Http/Controllers/X.php' => "<?php Report::dispatch()->onQueue('low'); Alert::dispatch()->onQueue(\"high\"); Thing::dispatch()->onQueue(\$dynamic);",
    ]);

    expect(EdgeQueueNames::scan($dir))->toBe(['queues' => ['high', 'default', 'emails', 'low'], 'groups' => []]);
    File::deleteDirectory($dir);
});

test('Horizon supervisors become groups', function () {
    $dir = queueApp([
        'config/horizon.php' => "<?php return ['environments' => ['production' => [
            'supervisor-1' => ['connection' => 'redis', 'queue' => ['default', 'high']],
            'supervisor-2' => ['connection' => 'redis', 'queue' => ['exports']],
        ], 'local' => ['supervisor-1' => ['queue' => ['default', 'high']]]]];",
    ]);

    expect(EdgeQueueNames::scan($dir))->toBe(['queues' => ['high', 'default', 'exports'], 'groups' => [['high', 'default'], ['exports']]]);
    File::deleteDirectory($dir);
});

test('an app with no queue code suggests nothing', function () {
    $dir = queueApp(['app/Models/User.php' => '<?php class User {}']);

    expect(EdgeQueueNames::scan($dir))->toBe(['queues' => [], 'groups' => []]);
    File::deleteDirectory($dir);
});
