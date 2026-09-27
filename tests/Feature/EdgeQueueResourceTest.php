<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeQueueResourceTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeDataUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'dply.edge.usage_billing.queue_operations_millicents_per_million' => 40_000,
        'dply.edge.usage_billing.margin_percent' => 0,
    ]);
});

/** A container app with one queue attached; returns [user, server, site, host, queue name]. */
function queueApp(string $runtime = 'container', ?string $target = null): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime]],
    ]);
    $name = $target ?? EdgeContainerConnections::ownedPrefix($org).'jobs';
    if ($target === null) {
        EdgeQueue::query()->create(['organization_id' => $org->id, 'name' => 'jobs', 'cloudflare_id' => 'q-id', 'cloudflare_name' => $name]);
    }
    $host = EdgeContainerConnections::resourceHost($site, 'jobs');
    $site->mergeEdgeMeta(['connections' => [['kind' => 'queue', 'name' => 'JOBS', 'host' => $host, 'target' => $name]]]);
    $site->save();

    return [$user, $server, $site->fresh(), $host, $name];
}

function fakeQueueApi(): void
{
    Http::fake(function (Request $request) {
        return match (true) {
            str_ends_with($request->url(), '/graphql') => Http::response(['data' => ['viewer' => ['accounts' => [['queueBacklogAdaptiveGroups' => [
                ['dimensions' => ['queueId' => 'q-id', 'datetimeMinute' => now()->toIso8601String()], 'avg' => ['messages' => 7]],
            ]]]]]]),
            str_ends_with($request->url(), '/queues/q-id') => Http::response(['success' => true, 'result' => [
                'queue_id' => 'q-id',
                'consumers' => [['consumer_id' => 'c1', 'script' => 'dply-app-worker', 'type' => 'worker', 'dead_letter_queue' => 'jobs-dlq', 'settings' => ['batch_size' => 10, 'max_retries' => 5, 'max_wait_time_ms' => 5000]]],
            ]]),
            str_ends_with($request->url(), '/queues/q-id/messages') => Http::response(['success' => true, 'result' => null]),
            default => Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$request->url()]]], 500),
        };
    });
}

test('opening the queue loads its backlog, consumers, owner and delivery settings', function () {
    fakeQueueApi();
    [$user, $server, $site, $host] = queueApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->assertDispatched('open-modal', 'resources-queue')
        ->call('queueLoad')
        ->assertSet('queueError', null)
        ->assertSet('queueDetail.backlog', 7)
        ->assertSet('queueDetail.owner_is_this', true)
        ->assertSet('queueDetail.consumers.0.script', 'dply-app-worker')
        ->assertSee('This app runs this queue’s jobs.')
        ->assertSee('jobs-dlq')
        ->assertSee('http://'.$host.'/send')
        ->assertSee('Delete queue');
});

test('a worker app is shown env.NAME.send()', function () {
    fakeQueueApi();
    [$user, $server, $site, $host] = queueApp('ssr');

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->assertSee('await env.JOBS.send(');
});

test('a failing consumers call still shows the backlog', function () {
    Http::fake([
        '*/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [['queueBacklogAdaptiveGroups' => [['dimensions' => ['queueId' => 'q-id'], 'avg' => ['messages' => 3]]]]]]]]),
        '*' => Http::response(['success' => false, 'errors' => [['message' => 'boom']]], 500),
    ]);
    [$user, $server, $site, $host] = queueApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->call('queueLoad')
        ->assertSet('queueDetail.backlog', 3)
        ->assertSet('queueDetail.consumers', null)
        ->assertSee('Could not read the queue’s consumers.');
});

test('send a test posts the decoded JSON to this queue', function () {
    fakeQueueApi();
    [$user, $server, $site, $host] = queueApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->set('queueTestBody', '{"order": 42}')
        ->call('queueSendTest')
        ->assertSet('queueTestOk', true);

    Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/queues/q-id/messages') && $r['body'] === ['order' => 42] && $r['content_type'] === 'json');
});

test('invalid JSON sends nothing', function () {
    Http::fake();
    [$user, $server, $site, $host] = queueApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->set('queueTestBody', '{order: 42')
        ->call('queueSendTest')
        ->assertSet('queueTestOk', false)
        ->assertSet('queueTestResult', 'That is not valid JSON.');

    Http::assertNothingSent();
});

test('a queue this organization does not own is never read or sent to', function () {
    Http::fake();
    [$user, $server, $site, $host] = queueApp(target: 'dply-someone-else-jobs');

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->call('queueLoad')
        ->set('queueTestBody', '{}')
        ->call('queueSendTest')
        ->assertSet('queueTestOk', false);

    Http::assertNothingSent();
});

test('sending needs update and loading needs view on the app', function (string $ability, string $method) {
    Http::fake();
    [$user, $server, $site, $host] = queueApp();

    $component = Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host);
    Gate::before(fn ($user, string $checked) => $checked === $ability ? false : null);

    $component->call($method)->assertForbidden();
    Http::assertNothingSent();
})->with([['update', 'queueSendTest'], ['view', 'queueLoad']]);

test('the card cost counts only this queue', function () {
    [$user, $server, $site, $host, $name] = queueApp();
    EdgeQueue::query()->create(['organization_id' => $site->organization_id, 'name' => 'other', 'cloudflare_id' => 'q-other', 'cloudflare_name' => 'dply-x-other']);
    foreach (['q-id' => 1_000_000, 'q-other' => 9_000_000] as $id => $operations) {
        DB::table('edge_queue_usage')->insert(['id' => (string) Str::ulid(), 'organization_id' => $site->organization_id, 'queue_id' => $id, 'date' => now()->toDateString(), 'operations' => $operations]);
    }

    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $server, 'site' => $site]);

    expect($component->instance()->queueCostCents(['target' => $name]))->toBe(40)
        ->and($component->instance()->queueCostCents(['target' => 'dply-not-mine']))->toBeNull()
        ->and($component->viewData('connectionEstimates')[$host])->toBe(40);
});

test('the collector keeps operations per queue', function () {
    [, , $site] = queueApp();
    Http::fake(['*/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [[
        'd1AnalyticsAdaptiveGroups' => [],
        'd1StorageAdaptiveGroups' => [],
        'queueMessageOperationsAdaptiveGroups' => [
            ['dimensions' => ['queueId' => 'q-id'], 'sum' => ['billableOperations' => 5]],
            ['dimensions' => ['queueId' => 'q-unknown'], 'sum' => ['billableOperations' => 9]],
        ],
    ]]]]])]);

    $collector = new EdgeDataUsageCollector(new EdgeCloudflareClient('acct', 'tok'));
    $collector->collectForDate(now()->startOfDay());
    $collector->collectForDate(now()->startOfDay());

    expect(DB::table('edge_queue_usage')->where('queue_id', 'q-id')->where('organization_id', $site->organization_id)->sum('operations'))->toEqual(5)
        ->and(DB::table('edge_queue_usage')->where('queue_id', 'q-unknown')->exists())->toBeFalse();
});
