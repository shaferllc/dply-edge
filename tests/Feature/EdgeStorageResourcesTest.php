<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeStorageResourcesTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeKvUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeKvUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'dply.edge.usage_billing.r2_storage_cents_per_gb_month' => 3,
        'dply.edge.usage_billing.r2_class_a_cents_per_million' => 450,
        'dply.edge.usage_billing.r2_class_b_cents_per_million' => 36,
        'dply.edge.usage_billing.included_r2_storage_gb_per_site' => 0,
        'dply.edge.usage_billing.included_r2_class_a_ops_per_site' => 0,
        'dply.edge.usage_billing.included_r2_class_b_ops_per_site' => 0,
        'dply.edge.usage_billing.markup_percent' => 0,
    ]);
});

/** An app with one resource of $kind; returns [user, site, host]. */
function storageApp(string $kind, string $target, string $runtime = 'container'): array
{
    $org = Organization::factory()->create();
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime]],
    ]);
    $host = EdgeContainerConnections::resourceHost($site, 'uploads');
    $site->mergeEdgeMeta(['connections' => [['kind' => $kind, 'name' => 'UPLOADS', 'host' => $host, 'target' => str_replace('{prefix}', EdgeContainerConnections::ownedPrefix($org), $target)]]]);
    $site->save();

    return [$user, $site->fresh(), $host];
}

function graphqlUsage(int $bytes, int $objects, int $puts, int $gets): array
{
    return ['data' => ['viewer' => ['accounts' => [[
        'r2StorageAdaptiveGroups' => [['max' => ['payloadSize' => $bytes, 'metadataSize' => 0, 'objectCount' => $objects]]],
        'r2OperationsAdaptiveGroups' => [
            ['sum' => ['requests' => $puts], 'dimensions' => ['actionType' => 'PutObject']],
            ['sum' => ['requests' => $gets], 'dimensions' => ['actionType' => 'GetObject']],
        ],
    ]]]]];
}

test('KV usage is collected for Worker apps, not only containers', function () {
    storageApp('key_value', 'ns-ssr', 'ssr');
    Http::fake(['api.cloudflare.com/client/v4/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [[
        'kvOperationsAdaptiveGroups' => [['dimensions' => ['namespaceId' => 'ns-ssr', 'actionType' => 'read'], 'sum' => ['requests' => 42]]],
        'kvStorageAdaptiveGroups' => [],
    ]]]]])]);

    expect(app(EdgeKvUsageCollector::class)->collectForDate(now())['sites'])->toBe(1)
        ->and((int) EdgeKvUsage::query()->where('namespace_id', 'ns-ssr')->value('reads'))->toBe(42);
});

test('KV keys search by prefix and page with a cursor', function () {
    [$user, $site, $host] = storageApp('key_value', 'ns-1');
    Http::fake(function (Request $request) {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['cursor'] ?? '') === 'c2'
            ? Http::response(['success' => true, 'result' => [['name' => 'user:2']], 'result_info' => ['cursor' => '']])
            : Http::response(['success' => true, 'result' => [['name' => 'user:1']], 'result_info' => ['cursor' => 'c2']]);
    });

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openKv', $host)
        ->set('kvPrefix', 'user:')
        ->call('refreshKv')
        ->assertSet('kvKeys', ['user:1'])
        ->assertSet('kvCursor', 'c2')
        ->assertSee('Load more')
        ->call('loadMoreKvKeys')
        ->assertSet('kvKeys', ['user:1', 'user:2'])
        ->assertSet('kvCursor', null)
        ->assertSee('Delete store');

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/keys?') && str_contains($request->url(), 'prefix=user%3A'));
});

test('a KV demo write can expire, and a read shows the expiration', function () {
    [$user, $site, $host] = storageApp('key_value', 'ns-1');
    $expires = now()->addHour()->getTimestamp();
    Http::fake(function (Request $request) use ($expires) {
        if (str_contains($request->url(), '/values/')) {
            return $request->method() === 'GET' ? Http::response('hi') : Http::response(['success' => true, 'result' => null]);
        }

        return Http::response(['success' => true, 'result' => [['name' => 'hello', 'expiration' => $expires, 'metadata' => ['v' => 1]]], 'result_info' => ['cursor' => '']]);
    });

    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openKv', $host)
        ->set('kvDemoValue', 'hi')
        ->set('kvDemoTtl', '30')
        ->call('runKvDemo', 'write')
        ->assertSee('at least 60 seconds');
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');

    $component->set('kvDemoTtl', '3600')
        ->call('runKvDemo', 'write')
        ->assertSee('It expires in 3,600 seconds')
        ->call('runKvDemo', 'read')
        ->assertSet('kvDemoMeta.expiration', $expires)
        ->assertSee('{"v":1}');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/values/hello?expiration_ttl=3600'));
});

test('the bucket card and sheet show this bucket\'s usage and cost, cached', function () {
    [$user, $site, $host] = storageApp('object_storage', '{prefix}uploads');
    Http::fake([
        'api.cloudflare.com/client/v4/graphql' => Http::response(graphqlUsage(10 * 1024 ** 3, 1234, 1_000_000, 0)),
        '*' => Http::response(['success' => true, 'result' => [], 'result_info' => ['cursor' => '', 'is_truncated' => false]]),
    ]);

    // Priced like the bill (Cloudflare list, no allowance): 10 GB x 1.5c + 1M class A x 450c = $4.65. The card never waits on
    // Cloudflare: a cold cache shows no estimate; opening the sheet fetches.
    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->assertDontSee('Cost estimate · $')
        ->call('openObject', $host)
        ->assertSet('objectUsage.objects', 1234)
        ->assertSee('1,234')
        ->assertSee('$4.65');

    // Warm now: the card shows it, with no second GraphQL call.
    $component->call('$refresh')->assertSee('Cost estimate · $4.65');
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'graphql')))->toHaveCount(1);
});

test('the bucket estimate is left off, not thrown, when Cloudflare fails', function () {
    [$user, $site] = storageApp('object_storage', '{prefix}uploads');
    Http::fake(['*' => Http::response('down', 500)]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->assertOk()
        ->assertDontSee('Cost estimate · $');
});

test('the object list filters by prefix and pages', function () {
    [$user, $site, $host] = storageApp('object_storage', '{prefix}uploads');
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'graphql')) {
            return Http::response(graphqlUsage(0, 0, 0, 0));
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['cursor'] ?? '') === 'next'
            ? Http::response(['success' => true, 'result' => [['key' => 'img/b.png', 'size' => 2]], 'result_info' => ['is_truncated' => false]])
            : Http::response(['success' => true, 'result' => [['key' => 'img/a.png', 'size' => 1]], 'result_info' => ['cursor' => 'next', 'is_truncated' => true]]);
    });

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openObject', $host)
        ->set('objectPrefix', 'img/')
        ->call('refreshObjectList')
        ->assertSet('objectCursor', 'next')
        ->call('loadMoreObjects')
        ->assertSet('objectList', [['key' => 'img/a.png', 'size' => 1], ['key' => 'img/b.png', 'size' => 2]])
        ->assertSet('objectCursor', null);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/objects?') && str_contains($request->url(), 'prefix=img%2F'));
});

test('a new bucket can be placed with a location hint', function () {
    [$user, $site] = storageApp('key_value', 'ns-1');
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['name' => 'x']])]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('chooseConnectionKind', 'object_storage')
        ->set('connectionLabel', 'Media')
        ->set('objectLocationHint', 'weur')
        ->call('saveConnection')
        ->assertHasNoErrors();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/r2/buckets') && $request['locationHint'] === 'weur');
});

test('empty and delete removes the files, then the bucket', function () {
    [$user, $site, $host] = storageApp('object_storage', '{prefix}uploads');
    $objects = ['a.txt', 'b.txt'];
    Http::fake(function (Request $request) use (&$objects) {
        if ($request->method() === 'DELETE' && str_contains($request->url(), '/objects/')) {
            $objects = array_values(array_diff($objects, [basename($request->url())]));
        }
        if ($request->method() === 'GET' && str_contains($request->url(), '/objects')) {
            return Http::response(['success' => true, 'result' => array_map(fn ($k) => ['key' => $k, 'size' => 1], $objects), 'result_info' => ['is_truncated' => false]]);
        }

        return Http::response(['success' => true, 'result' => []]);
    });

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('askDeleteConnection', $host)
        ->assertSee('Empty and delete')
        ->call('emptyAndDeleteConnection')
        ->assertHasNoErrors();

    expect(EdgeContainerConnections::for($site->fresh()))->toBe([]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/objects/b.txt'));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/r2/buckets/'.EdgeContainerConnections::ownedPrefix($site->organization).'uploads'));
});

test('empty and delete never empties a bucket another organization created', function () {
    [$user, $site, $host] = storageApp('object_storage', 'someone-elses-bucket');
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('askDeleteConnection', $host)
        ->call('emptyAndDeleteConnection')
        ->assertHasErrors('connectionDelete');

    expect(EdgeContainerConnections::for($site->fresh()))->toHaveCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');
});
