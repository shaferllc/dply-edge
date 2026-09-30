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
use App\Modules\Edge\Jobs\DeleteEdgeKvKeysByPrefixJob;
use App\Modules\Edge\Livewire\Buckets;
use App\Modules\Edge\Services\EdgeKvUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
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
        'dply.edge.usage_billing.margin_percent' => 0,
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
        ->assertSet('kvKeys', [['name' => 'user:1', 'expiration' => null, 'metadata' => null]])
        ->assertSet('kvCursor', 'c2')
        ->assertSee('Load more')
        ->call('loadMoreKvKeys')
        ->assertSet('kvKeys', [['name' => 'user:1', 'expiration' => null, 'metadata' => null], ['name' => 'user:2', 'expiration' => null, 'metadata' => null]])
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
        ->assertDontSee('this month')
        ->call('openObject', $host)
        ->assertSet('objectUsage.objects', 1234)
        ->assertSee('1,234')
        ->assertSee('$4.65');

    // Warm now: the card shows it, with no second GraphQL call.
    $component->call('$refresh')->assertSeeHtml('<b class="text-brand-ink">$4.65</b> this month');
    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), 'graphql')))->toHaveCount(1);
});

test('the bucket estimate is left off, not thrown, when Cloudflare fails', function () {
    [$user, $site] = storageApp('object_storage', '{prefix}uploads');
    Http::fake(['*' => Http::response('down', 500)]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->assertOk()
        ->assertDontSee('this month');
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

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/r2/buckets') && ($request['locationHint'] ?? null) === 'weur');
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

test('KV delete by prefix counts the first page, needs the prefix typed, and queues the job', function () {
    [$user, $site, $host] = storageApp('key_value', 'ns-1');
    Queue::fake();
    Http::fake(['*' => Http::response(['success' => true, 'result' => [['name' => 'user:1'], ['name' => 'user:2']], 'result_info' => ['cursor' => '']])]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openKv', $host)
        ->call('previewKvPrefixDelete')
        ->assertHasErrors('kvDelete')
        ->set('kvPrefix', 'user:')
        ->call('refreshKv')
        ->assertSee('Delete keys starting with user:')
        ->call('previewKvPrefixDelete')
        ->assertSet('kvDeleteCount', '2')
        ->set('kvDeleteConfirm', 'user')
        ->call('deleteKvPrefix')
        ->assertHasErrors('kvDelete')
        ->set('kvDeleteConfirm', 'user:')
        ->call('deleteKvPrefix')
        ->assertHasNoErrors()
        ->assertSee('Deleting keys starting with user:');

    Queue::assertPushed(DeleteEdgeKvKeysByPrefixJob::class, fn ($job) => $job->namespaceId === 'ns-1' && $job->prefix === 'user:');
});

test('the prefix delete job walks every page and bulk-deletes each', function () {
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '/bulk/delete')) {
            return Http::response(['success' => true, 'result' => null]);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return ($query['cursor'] ?? '') === 'c2'
            ? Http::response(['success' => true, 'result' => [['name' => 'user:3']], 'result_info' => ['cursor' => '']])
            : Http::response(['success' => true, 'result' => [['name' => 'user:1'], ['name' => 'user:2']], 'result_info' => ['cursor' => 'c2']]);
    });

    Sleep::fake();

    (new DeleteEdgeKvKeysByPrefixJob('ns-1', 'user:'))->handle();

    Sleep::assertSleptTimes(1);

    $deleted = collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_ends_with($r->url(), '/bulk/delete'))->map(fn (Request $r) => $r->data())->values()->all();
    expect($deleted)->toBe([['user:1', 'user:2'], ['user:3']])
        ->and(DeleteEdgeKvKeysByPrefixJob::progress('ns-1'))->toBe(['prefix' => 'user:', 'removed' => 3, 'done' => true, 'failed' => null]);
});

test('picking a KV key loads its value so Write edits it in place', function () {
    [$user, $site, $host] = storageApp('key_value', 'ns-1');
    Http::fake([
        '*/values/*' => Http::response('hello', 200),
        '*' => Http::response(['success' => true, 'result' => [['name' => 'greeting', 'expiration' => null]], 'result_info' => ['cursor' => '']]),
    ]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openKv', $host)
        ->call('pickKvKey', 'greeting')
        ->assertSet('kvDemoKey', 'greeting')
        ->assertSet('kvDemoValue', 'hello');
});

test('a long prefix delete hands the cursor to a fresh run instead of racing the shared quota', function () {
    Sleep::fake();
    Queue::fake();
    Http::fake(fn (Request $request) => str_ends_with($request->url(), '/bulk/delete')
        ? Http::response(['success' => true, 'result' => null])
        : Http::response(['success' => true, 'result' => [['name' => 'k']], 'result_info' => ['cursor' => 'more']]));

    (new DeleteEdgeKvKeysByPrefixJob('ns-1', 'user:'))->handle();

    Queue::assertPushed(DeleteEdgeKvKeysByPrefixJob::class, fn ($job) => $job->cursor === 'more' && $job->removed === DeleteEdgeKvKeysByPrefixJob::PAGES_PER_RUN);
    expect(DeleteEdgeKvKeysByPrefixJob::progress('ns-1')['done'])->toBeFalse();
});

/** Adds a second bucket (MEDIA) to the app from storageApp(); returns its host. */
function addMediaBucket(Site $site): string
{
    $host = EdgeContainerConnections::resourceHost($site, 'media');
    $rows = EdgeContainerConnections::for($site);
    $rows[] = ['kind' => 'object_storage', 'name' => 'MEDIA', 'host' => $host, 'target' => EdgeContainerConnections::ownedPrefix($site->organization).'media'];
    $site->mergeEdgeMeta(['connections' => $rows]);
    $site->save();

    return $host;
}

it('uses the first bucket as the default disk until another is made default', function () {
    [$user, $site] = storageApp('object_storage', '{prefix}uploads');
    $media = addMediaBucket($site);

    $env = EdgeContainerConnections::storageDriverEnv($site->fresh());
    expect($env['DPLY_STORAGE_DISK'])->toBe('uploads')
        ->and($env['DPLY_STORAGE_DISKS'])->toContain('uploads=')->toContain('media=');

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->set('objectHost', $media)
        ->call('makeDefaultStorage');

    $env = EdgeContainerConnections::storageDriverEnv($site->fresh());
    expect($env['DPLY_STORAGE_DISK'])->toBe('media')->and($env['DPLY_STORAGE_HOST'])->toBe($media);

    // Asleep, the chosen bucket gives way to the first awake one.
    $rows = collect(EdgeContainerConnections::for($site->fresh()))->map(fn ($c) => $c['name'] === 'MEDIA' ? ['asleep' => true] + $c : $c)->all();
    $site = $site->fresh();
    $site->mergeEdgeMeta(['connections' => $rows]);
    $site->save();
    expect(EdgeContainerConnections::storageDriverEnv($site->fresh())['DPLY_STORAGE_DISK'])->toBe('uploads');
});

it('signs a share link for the attached bucket only, with an allowed expiry', function () {
    config(['filesystems.disks.edge_r2' => ['driver' => 's3', 'key' => 'k', 'secret' => 's', 'region' => 'auto', 'bucket' => 'platform', 'endpoint' => 'https://acct.r2.cloudflarestorage.com', 'use_path_style_endpoint' => true]]);
    [$user, $site, $host] = storageApp('object_storage', '{prefix}uploads');
    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])->set('objectHost', $host);

    $url = $component->instance()->objectSignedUrl('docs/a.pdf', 'get', 24)['url'];
    expect($url)->toContain('/'.EdgeContainerConnections::ownedPrefix($site->organization).'uploads/docs/a.pdf')
        ->toContain('X-Amz-Expires=86400');
    expect($component->instance()->objectSignedUrl('docs/a.pdf', 'put', 1)['url'])->toContain('X-Amz-Expires=3600');

    expect($component->instance()->objectSignedUrl('../etc', 'get', 24))->toHaveKey('error')
        ->and($component->instance()->objectSignedUrl('a.txt', 'get', 999))->toHaveKey('error');

    // A bucket another organization owns is refused even when bound here.
    $site->mergeEdgeMeta(['connections' => [['kind' => 'object_storage', 'name' => 'UPLOADS', 'host' => $host, 'target' => 'dply-someoneelse-uploads']]]);
    $site->save();
    $other = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site->fresh()])->set('objectHost', $host);
    expect($other->instance()->objectSignedUrl('a.txt', 'get', 24))->toHaveKey('error');
});

it('lists the organization’s buckets with the apps that use them', function () {
    [$user, $site] = storageApp('object_storage', '{prefix}uploads');
    $prefix = EdgeContainerConnections::ownedPrefix($site->organization);
    session(['current_organization_id' => $site->organization_id]);
    Http::fake(['*' => Http::response(['success' => true, 'result' => ['buckets' => [['name' => $prefix.'uploads'], ['name' => $prefix.'spare'], ['name' => 'dply-other-org-x']]]])]);

    Livewire::actingAs($user)->test(Buckets::class)
        ->assertSee('uploads')->assertSee('spare')->assertDontSee('other-org')
        ->assertSee($site->name)
        ->call('delete', $prefix.'uploads')
        ->assertHasErrors('bucket');
});

it('sets and clears a bucket’s public path, refusing a bad or taken one', function () {
    [$user, $site, $host] = storageApp('object_storage', '{prefix}uploads');
    $media = addMediaBucket($site);
    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site->fresh()]);

    $component->set('objectHost', $host)->call('setObjectPublicPath', 'Files/')->assertHasNoErrors();
    expect(EdgeContainerConnections::publicStorage($site->fresh()))->toBe([['name' => 'UPLOADS', 'path' => '/files']]);

    $component->set('objectHost', $media)->call('setObjectPublicPath', '/files')->assertHasErrors('objectPublicPath');
    $component->call('setObjectPublicPath', '/../etc')->assertHasErrors('objectPublicPath');

    $component->set('objectHost', $host)->call('setObjectPublicPath', '');
    expect(EdgeContainerConnections::publicStorage($site->fresh()))->toBe([]);
});

it('offers Attach existing only for buckets this app does not use yet', function () {
    [$user, $site] = storageApp('object_storage', '{prefix}uploads');
    $prefix = EdgeContainerConnections::ownedPrefix($site->organization);
    $buckets = [['name' => $prefix.'uploads']];
    Http::fake(['*/r2/buckets' => function () use (&$buckets) {
        return Http::response(['success' => true, 'result' => ['buckets' => $buckets]]);
    }]);

    // Its only bucket is already attached: no toggle.
    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('chooseConnectionKind', 'object_storage')
        ->assertSet('connectionOptions', [])
        ->assertDontSeeHtml("setConnectionMode('attach')");

    $buckets[] = ['name' => $prefix.'media'];
    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('chooseConnectionKind', 'object_storage')
        ->assertSet('connectionOptions', [['id' => $prefix.'media', 'label' => 'media']])
        ->assertSeeHtml("setConnectionMode('attach')");
});

it('makes a key-value store the default cache only when chosen, and never over Redis', function () {
    [$user, $site, $host] = storageApp('key_value', 'ns-1');
    $site->forceFill(['meta' => array_replace_recursive($site->meta ?? [], ['edge' => ['build' => ['framework' => 'laravel']]])])->save();
    $site = $site->fresh();
    $env = EdgeContainerConnections::kvDriverEnv($site);
    expect($env['DPLY_KV_STORE'])->toBe('uploads')
        ->and($env)->not->toHaveKey('CACHE_STORE')
        ->and($env)->not->toHaveKey('DPLY_KV_DEFAULT');

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->set('kvHost', $host)
        ->call('setKvDefaultCache', true);
    $env = EdgeContainerConnections::kvDriverEnv($site->fresh());
    expect($env['DPLY_KV_DEFAULT'])->toBe('uploads')
        ->and($env['CACHE_STORE'] ?? null)->toBe($site->fresh()->isLaravelFrameworkDetected() ? 'uploads' : null);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site->fresh()])
        ->set('kvHost', $host)
        ->call('setKvDefaultCache', false);
    expect(EdgeContainerConnections::kvDriverEnv($site->fresh()))->not->toHaveKey('DPLY_KV_DEFAULT');
});
