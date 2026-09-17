<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerHealthTest;

use App\Models\EdgeDeployment;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\CheckEdgeContainerHealthJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function liveContainer(): EdgeDeployment
{
    $org = Organization::factory()->create();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => 'container', 'live_url' => 'https://shop.on-dply.live']],
    ]);

    return EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $org->id, 'status' => EdgeDeployment::STATUS_LIVE, 'meta' => ['container' => ['script_name' => 'dply-ctr-x']]]);
}

test('a healthy container records the check and stays quiet', function () {
    Http::fake(['shop.on-dply.live/*' => Http::response('ok', 200), 'shop.on-dply.live' => Http::response('ok', 200)]);
    $deployment = liveContainer();

    (new CheckEdgeContainerHealthJob($deployment->id))->handle();

    expect($deployment->fresh()->meta['container']['health'])->toMatchArray(['ok' => true, 'status' => 200])
        ->and($deployment->fresh()->meta['container']['script_name'])->toBe('dply-ctr-x')
        ->and(NotificationEvent::query()->count())->toBe(0);
});

test('a 5xx container is reported as a failed deploy', function () {
    Http::fake(['*' => Http::response('boom', 500)]);
    $deployment = liveContainer();

    (new CheckEdgeContainerHealthJob($deployment->id))->handle();

    expect($deployment->fresh()->meta['container']['health'])->toMatchArray(['ok' => false, 'status' => 500])
        ->and(NotificationEvent::query()->where('event_key', 'edge.deploy.failed')->count())->toBe(1);
});
