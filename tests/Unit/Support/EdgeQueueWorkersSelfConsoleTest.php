<?php

declare(strict_types=1);

use App\Models\Site;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Support\DplyRuntime;

test('dply itself gets a worker group for Console commands, unless its settings cover that queue', function () {
    config(['edge.self.site_id' => '01SELF']);
    $site = new Site;
    $site->forceFill(['id' => '01SELF', 'meta' => ['edge' => ['container' => ['workers' => ['enabled' => true, 'queues' => 'dply,default']]]]]);

    expect(collect(EdgeQueueWorkers::for($site)['groups'])->firstWhere('key', 'console')['queues'] ?? null)->toBe(DplyRuntime::CONSOLE_QUEUE);

    $site->forceFill(['meta' => ['edge' => ['container' => ['workers' => ['enabled' => true, 'queues' => 'dply,'.DplyRuntime::CONSOLE_QUEUE]]]]]);
    expect(EdgeQueueWorkers::for($site)['groups'])->toBe([]);

    $other = new Site;
    $other->forceFill(['id' => '01OTHER', 'meta' => ['edge' => ['container' => ['workers' => ['enabled' => true, 'queues' => 'default']]]]]);
    expect(EdgeQueueWorkers::for($other)['groups'])->toBe([]);
});
