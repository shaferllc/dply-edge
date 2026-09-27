<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeDatabase;
use App\Models\EdgeDeployment;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
});

/** A container app in its own organization, with an owner to act as. */
function ownedApp(array $edge = []): array
{
    $org = Organization::factory()->create();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'type' => SiteType::Static,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => array_replace_recursive(['runtime_mode' => 'container', 'database' => ['engine' => 'sql']], $edge)],
    ]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $site->forceFill(['user_id' => $user->id])->save();
    $site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    return [$org, $site, $user];
}

test('attach offers only resources this organization created', function () {
    [$org] = ownedApp();
    $mine = EdgeContainerConnections::ownedPrefix($org);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => [
        ['id' => 'ns-1', 'title' => $mine.'cache'],
        ['id' => 'ns-2', 'title' => 'dply-someone-else-cache'],
        ['id' => 'ns-3', 'title' => 'platform-hostmap'],
    ]])]);

    expect(EdgeContainerConnections::catalog('key_value', $org))->toBe([['id' => 'ns-1', 'label' => 'cache']]);
});

test('resources are created under the organization prefix and D1 / queues are recorded', function () {
    [$org] = ownedApp();
    $org->forceFill(['comped_until' => now()->addYear()])->save(); // key-value needs a card
    $prefix = EdgeContainerConnections::ownedPrefix($org);
    Http::fake(function ($request) {
        return match (true) {
            str_ends_with($request->url(), '/d1/database') => Http::response(['success' => true, 'result' => ['uuid' => 'd1-uuid', 'name' => $request['name']]]),
            str_ends_with($request->url(), '/queues') => Http::response(['success' => true, 'result' => ['queue_id' => 'q-id', 'queue_name' => $request['queue_name']]]),
            str_ends_with($request->url(), '/r2/buckets') => Http::response(['success' => true, 'result' => ['name' => $request['name']]]),
            default => Http::response(['success' => true, 'result' => ['id' => 'ns-new', 'title' => $request['title'] ?? '']]),
        };
    });

    expect(EdgeContainerConnections::provision('sql', 'main', $org))->toBe('d1-uuid')
        ->and(EdgeContainerConnections::provision('queue', 'Jobs', $org))->toBe($prefix.'jobs')
        ->and(EdgeContainerConnections::provision('object_storage', 'Uploads', $org))->toBe($prefix.'uploads')
        ->and(EdgeContainerConnections::provision('key_value', 'cache', $org))->toBe('ns-new');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/d1/database') && $request['name'] === $prefix.'main');
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/storage/kv/namespaces') && $request['title'] === $prefix.'cache');
    expect(EdgeDatabase::query()->where('organization_id', $org->id)->where('cloudflare_id', 'd1-uuid')->value('name'))->toBe('main')
        ->and(EdgeQueue::query()->where('organization_id', $org->id)->where('cloudflare_name', $prefix.'jobs')->value('cloudflare_id'))->toBe('q-id');
});

test('delete never touches another organization\'s resource', function () {
    [$org] = ownedApp();
    Http::fake();

    expect(EdgeContainerConnections::destroy('object_storage', 'dply-someone-else-uploads', $org))->toBeFalse()
        ->and(EdgeContainerConnections::destroy('queue', 'shared-queue', $org))->toBeFalse()
        ->and(EdgeContainerConnections::destroy('ai', 'AI', $org))->toBeFalse();
    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
});

test('a queue is deleted by its id, not the name the binding stores', function () {
    [$org] = ownedApp();
    $name = EdgeContainerConnections::ownedPrefix($org).'jobs';
    EdgeQueue::query()->create(['organization_id' => $org->id, 'name' => 'jobs', 'cloudflare_id' => 'q-123', 'cloudflare_name' => $name]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null])]);

    expect(EdgeContainerConnections::destroy('queue', $name, $org))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/queues/q-123'));
    expect(EdgeQueue::query()->where('cloudflare_name', $name)->exists())->toBeFalse();
});

test('detaching a dply Valkey is refused, so it is never left running unattached', function () {
    [, $site, $user] = ownedApp(['connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'dply.app.redis.internal', 'target' => 'valkey:abc-redis']]]);
    Http::fake();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('removeConnection', 'dply.app.redis.internal');

    expect(EdgeContainerConnections::for($site->fresh()))->toHaveCount(1);
    Http::assertNothingSent();
});

test('deleting an attachment that is not ours only detaches it and says so', function () {
    [, $site, $user] = ownedApp(['connections' => [['kind' => 'object_storage', 'name' => 'UPLOADS', 'host' => 'dply.app.uploads.internal', 'target' => 'legacy-bucket']]]);
    Http::fake();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('askDeleteConnection', 'dply.app.uploads.internal')
        ->call('deleteConnection')
        ->assertHasNoErrors();

    expect(EdgeContainerConnections::for($site->fresh()))->toBe([]);
    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
});

test('deleting a resource unbinds it from the organization\'s other apps', function () {
    [$org, $site, $user] = ownedApp();
    $bucket = EdgeContainerConnections::ownedPrefix($org).'uploads';
    $row = ['kind' => 'object_storage', 'name' => 'UPLOADS', 'target' => $bucket];
    $site->mergeEdgeMeta(['connections' => [$row + ['host' => EdgeContainerConnections::resourceHost($site, 'uploads')]]]);
    $site->save();
    $other = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'connections' => [$row + ['host' => 'dply.other.uploads.internal']]]],
    ]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null])]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('askDeleteConnection', EdgeContainerConnections::resourceHost($site, 'uploads'))
        ->call('deleteConnection')
        ->assertHasNoErrors();

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/r2/buckets/'.$bucket));
    expect(EdgeContainerConnections::for($other->fresh()))->toBe([]);
});

test('a queue a deployed Worker still binds is detached now and deleted after the next deploy', function () {
    [$org, $site, $user] = ownedApp();
    $name = EdgeContainerConnections::ownedPrefix($org).'jobs';
    EdgeQueue::query()->create(['organization_id' => $org->id, 'name' => 'jobs', 'cloudflare_id' => 'q-9', 'cloudflare_name' => $name]);
    $host = EdgeContainerConnections::resourceHost($site, 'jobs');
    $site->mergeEdgeMeta(['connections' => [['kind' => 'queue', 'name' => 'JOBS', 'host' => $host, 'target' => $name]]]);
    $site->save();
    $bound = true;
    Http::fake(function ($request) use (&$bound) {
        if ($request->method() === 'DELETE' && str_ends_with($request->url(), '/queues/q-9') && $bound) {
            return Http::response(['success' => false, 'errors' => [['code' => 11005, 'message' => 'Cannot delete queue that is still referenced by a binding in a Worker.']]], 400);
        }

        return Http::response(['success' => true, 'result' => null]);
    });

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('askDeleteConnection', $host)
        ->call('deleteConnection')
        ->assertHasNoErrors();

    $site->refresh();
    expect(EdgeContainerConnections::for($site))->toBe([])
        ->and($site->edgeMeta()['pending_deletes'])->toBe([['kind' => 'queue', 'target' => $name]]);

    // The next deploy removed the binding: the queue goes.
    $bound = false;
    EdgeContainerConnections::deletePending($site);

    expect($site->fresh()->edgeMeta()['pending_deletes'])->toBe([])
        ->and(EdgeQueue::query()->where('cloudflare_name', $name)->exists())->toBeFalse();
});

test('a resource the live deploy binds is detached now and deleted after the next deploy', function () {
    [$org, $site, $user] = ownedApp();
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $org->id, 'status' => EdgeDeployment::STATUS_LIVE, 'storage_prefix' => 'edge/test/live']);
    $bucket = EdgeContainerConnections::ownedPrefix($org).'uploads';
    $host = EdgeContainerConnections::resourceHost($site, 'uploads');
    $site->mergeEdgeMeta(['connections' => [['kind' => 'object_storage', 'name' => 'UPLOADS', 'host' => $host, 'target' => $bucket]]]);
    $site->save();
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('askDeleteConnection', $host)
        ->call('deleteConnection')
        ->assertHasNoErrors();

    // Nothing deleted while the live app still binds it.
    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE');
    $site->refresh();
    expect(EdgeContainerConnections::for($site))->toBe([])
        ->and($site->edgeMeta()['pending_deletes'])->toBe([['kind' => 'object_storage', 'target' => $bucket]]);

    EdgeContainerConnections::deletePending($site);

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/r2/buckets/'.$bucket));
    expect($site->fresh()->edgeMeta()['pending_deletes'])->toBe([]);
});

test('a pending delete re-attached before the deploy is kept', function () {
    [$org, $site] = ownedApp();
    $bucket = EdgeContainerConnections::ownedPrefix($org).'uploads';
    $site->mergeEdgeMeta([
        'connections' => [['kind' => 'object_storage', 'name' => 'UPLOADS', 'host' => EdgeContainerConnections::resourceHost($site, 'uploads'), 'target' => $bucket]],
        'pending_deletes' => [['kind' => 'object_storage', 'target' => $bucket]],
    ]);
    $site->save();
    Http::fake();

    EdgeContainerConnections::deletePending($site);

    Http::assertNothingSent();
    expect($site->fresh()->edgeMeta()['pending_deletes'])->toBe([]);
});

test('a pending delete another app still binds waits, and goes once no app does', function () {
    [$org, $site] = ownedApp();
    $bucket = EdgeContainerConnections::ownedPrefix($org).'uploads';
    $site->mergeEdgeMeta(['pending_deletes' => [['kind' => 'object_storage', 'target' => $bucket]]]);
    $site->save();
    $other = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $site->server_id, 'edge_backend' => 'dply_edge', 'type' => SiteType::Static, 'meta' => ['edge' => [
        'connections' => [['kind' => 'object_storage', 'name' => 'UPLOADS', 'host' => EdgeContainerConnections::resourceHost($site, 'uploads'), 'target' => $bucket]],
    ]]]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);

    EdgeContainerConnections::deletePending($site);

    // Was: dropped from the list, so the bucket stayed and billed for good.
    Http::assertNothingSent();
    expect($site->fresh()->edgeMeta()['pending_deletes'])->toBe([['kind' => 'object_storage', 'target' => $bucket, 'after_own_deploy' => true]]);

    // The other app lets go and deploys: its deploy finishes this app's delete.
    $other->mergeEdgeMeta(['connections' => []]);
    $other->save();
    EdgeContainerConnections::deletePending($other->fresh());

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/r2/buckets/'.$bucket));
    expect($site->fresh()->edgeMeta()['pending_deletes'])->toBe([]);
});

test('another app’s deploy never deletes what this app’s live deploy still binds', function () {
    [$org, $site] = ownedApp();
    $bucket = EdgeContainerConnections::ownedPrefix($org).'uploads';
    $site->mergeEdgeMeta(['pending_deletes' => [['kind' => 'object_storage', 'target' => $bucket]]]);
    $site->save();
    $other = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $site->server_id, 'edge_backend' => 'dply_edge', 'type' => SiteType::Static]);
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []])]);

    EdgeContainerConnections::deletePending($other);

    Http::assertNothingSent();
    expect($site->fresh()->edgeMeta()['pending_deletes'])->toBe([['kind' => 'object_storage', 'target' => $bucket]]);
});

test('auto-save refuses a sleep value saveRuntime would reject', function () {
    [, $site, $user] = ownedApp(['container' => ['sleep_after' => '10m']]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->set('sleepAfter', 'bogus')
        ->assertHasErrors('sleepAfter');

    expect($site->fresh()->edgeMeta()['container']['sleep_after'] ?? '10m')->toBe('10m');
});
