<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDplyDatabaseTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgePostgresUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Jobs\RestoreEdgeDplyPostgresJob;
use App\Modules\Edge\Jobs\TransferEdgeDplyDatabaseJob;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Services\EdgeValkeyUsageCollector;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.valkey.api_url' => 'https://gateway.test',
        'edge.valkey.token' => 'tok',
        'edge.valkey.db_domain' => 'db.dply.test',
        'subscription.standard.stripe.tier_pro' => 'price_tier_pro',
        'dply.databases.large_sizes_enabled' => false,
    ]);
    $org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $org->id]);
    $this->site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'database' => ['engine' => 'sql', 'name' => 'production']]],
    ]);
});

function env(Site $site, string $key): string
{
    return (string) $site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', $key)->first()?->value;
}

test('new postgres is a dply database on the gateway, with credentials the app can use', function () {
    Http::fake(['gateway.test/*' => Http::response(['id' => 'x'])]);
    $id = 'pg-'.strtolower($this->site->id);

    expect(EdgeAppDatabase::sync($this->site, 'sql', 'postgres', 'sleep', '0.5', 300, 5))->toBeNull();
    $this->site->save();
    $record = $this->site->fresh()->edgeMeta()['database'];

    expect($record)->toMatchArray(['engine' => 'postgres', 'provider' => 'dply', 'remote_id' => $id, 'host' => $id.'.db.dply.test', 'size' => '0.5', 'disk_gb' => 5, 'suspend' => 300])
        ->and($record['storage_at'])->toBeInt()
        ->and(env($this->site, 'DB_HOST'))->toBe($id.'.db.dply.test')
        ->and(env($this->site, 'DB_PORT'))->toBe('5432')
        ->and(env($this->site, 'DB_DATABASE'))->toBe('app')
        ->and(env($this->site, 'DB_USERNAME'))->toBe('app')
        ->and(env($this->site, 'DB_SSLMODE'))->toBe('require')
        ->and(strlen(env($this->site, 'DB_PASSWORD')))->toBe(40);
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://gateway.test/tenants/'.$id
        && $request['engine'] === 'postgres' && $request['memory_mb'] === 2048 && $request['disk_gb'] === 5
        && $request['sleep_after'] === 300 && $request['password'] === env($this->site, 'DB_PASSWORD')
        // Pro keeps 14 days of backups (ruling r-78fm1ejqqy4c17en).
        && $request['backup_days'] === 14);
});

test('a size that does not fit yet falls back to the smallest dply size', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);

    EdgeAppDatabase::sync($this->site, 'sql', 'postgres', 'sleep', '4');

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['memory_mb'] === 1024 && $request['disk_gb'] === 1);
});

test('1, 2 and 4 CU are sold only with the large pools enabled, and a database keeps its size if they are switched off', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);
    expect(EdgeDplyDatabase::sizes())->toHaveKeys(['0.25', '0.5'])->not->toHaveKey('1');

    config(['dply.databases.large_sizes_enabled' => true]);
    expect(array_map('strval', array_keys(EdgeDplyDatabase::sizes())))->toBe(['0.25', '0.5', '1', '2', '4']);
    // Asked to sleep, a large size stays on anyway.
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres', 'sleep', '4', 300);
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['memory_mb'] === 16384 && $request['sleep_after'] === 0);
    expect($this->site->edgeMeta()['database'])->toMatchArray(['size' => '4', 'suspend' => -1, 'plan' => 'awake']);

    config(['dply.databases.large_sizes_enabled' => false]);
    expect(EdgeAppDatabase::sync($this->site, 'postgres', 'postgres', 'sleep', '4', 300, 5))->toBeNull()
        ->and($this->site->edgeMeta()['database']['size'])->toBe('4');
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['memory_mb'] === 16384 && $request['disk_gb'] === 5);
    // A new pick of a large size is still refused.
    expect(EdgeDplyDatabase::size('2', '4'))->toBe('0.25');
});

test('the size picker makes 1 vCPU and up stay on, and a smaller size gets the sleep choice back', function () {
    config(['dply.databases.large_sizes_enabled' => true]);
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site])
        ->call('selectDatabase', 'postgres')
        ->call('selectPostgresSize', '1')
        ->assertSet('draftPostgresSize', '1')
        ->assertSet('draftPostgresSuspend', -1)
        ->assertSee('Sizes of 1 vCPU and up stay on.')
        ->assertSee('$129.60/mo')
        ->call('selectPostgresSuspend', 300)
        ->assertSet('draftPostgresSuspend', -1)
        ->call('selectPostgresSize', '0.5')
        ->call('selectPostgresSuspend', 300)
        ->assertSet('draftPostgresSuspend', 300);
});

test('changing a dply database grows the disk, refuses to shrink it, and reuses the password', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres', 'sleep', '0.25', 300, 5);
    $password = env($this->site, 'DB_PASSWORD');

    expect(EdgeAppDatabase::sync($this->site, 'postgres', 'postgres', 'sleep', '0.25', 300, 1))->toBe('A database disk only grows. Pick 5 GB or more.')
        ->and(EdgeAppDatabase::sync($this->site, 'postgres', 'postgres', 'awake', '0.5', -1, 10))->toBeNull()
        ->and($this->site->edgeMeta()['database'])->toMatchArray(['size' => '0.5', 'disk_gb' => 10, 'suspend' => -1]);
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['disk_gb'] === 10
        && $request['memory_mb'] === 2048 && $request['sleep_after'] === 0 && $request['password'] === $password);
});

test('removing a dply database deletes the tenant', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $id = $this->site->edgeMeta()['database']['remote_id'];

    EdgeAppDatabase::sync($this->site, 'postgres', 'sql');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'https://gateway.test/tenants/'.$id);
    expect(env($this->site, 'DB_PASSWORD'))->toBe('');
});

test('the collector bills dply postgres compute while awake and the disk every hour', function () {
    // Storage bills by the second: a tick between setting storage_at and
    // collecting would make it 7201 s, not two hours.
    $this->freezeTime();
    Http::fake(['gateway.test/tenants/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres', 'sleep', '0.5', 300, 5);
    $id = $this->site->edgeMeta()['database']['remote_id'];
    $this->site->mergeEdgeMeta(['database' => array_merge($this->site->edgeMeta()['database'], ['storage_at' => now()->subHours(2)->timestamp])]);
    $this->site->save();
    Http::fake(['gateway.test/usage' => Http::sequence()
        ->push(['awake_seconds' => [$id => 600]])
        ->push(['awake_seconds' => [$id => 660]])]);

    app(EdgeValkeyUsageCollector::class)->collect();
    $row = EdgePostgresUsage::query()->where('project_id', $id)->first();

    // 600 awake seconds x 0.5 compute units; 5 GB for two hours.
    expect($row->compute_unit_seconds)->toBe(300)
        ->and($row->storage_byte_hours)->toBe(5 * 1024 ** 3 * 2)
        ->and($this->site->fresh()->edgeMeta()['database']['usage_counter'])->toBe(600);

    // A second run only adds what changed.
    app(EdgeValkeyUsageCollector::class)->collect();
    expect($row->fresh()->compute_unit_seconds)->toBe(330);
});

test('the resources tab offers disk sizes for dply postgres', function () {
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site])
        ->call('selectDatabase', 'postgres')
        ->assertSeeHtml('id="postgres-disk"')
        ->assertDontSeeHtml('id="postgres-location"')
        ->assertDontSeeHtml('id="postgres-history"')
        ->assertSee('0.25 vCPU')
        ->assertDontSee('4 vCPU · 16 GB')
        ->call('selectPostgresDisk', 10)
        ->assertSet('draftPostgresDisk', 10)
        ->call('selectPostgresSize', '2')
        ->assertNotSet('draftPostgresSize', '2');
});

test('mongodb is a dply database with a tls uri, and resizes with its own password', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);
    $id = 'mg-'.strtolower($this->site->id);

    expect(EdgeAppDatabase::sync($this->site, 'sql', 'mongodb', 'sleep', '0.25', 300, 5))->toBeNull();
    $record = $this->site->edgeMeta()['database'];
    $uri = env($this->site, 'MONGODB_URI');
    $password = rawurldecode((string) parse_url($uri, PHP_URL_PASS));

    expect($record)->toMatchArray(['engine' => 'mongodb', 'provider' => 'dply', 'remote_id' => $id, 'host' => $id.'.db.dply.test', 'disk_gb' => 5])
        ->and($uri)->toStartWith('mongodb://app:')->toEndWith('@'.$id.'.db.dply.test:27017/app?tls=true&authSource=app')
        ->and(env($this->site, 'MONGO_URL'))->toBe($uri)
        ->and(env($this->site, 'MONGODB_DATABASE'))->toBe('app')
        ->and(env($this->site, 'DB_PASSWORD'))->toBe('');
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request->url() === 'https://gateway.test/tenants/'.$id
        && $request['engine'] === 'mongodb' && $request['disk_gb'] === 5 && strlen($password) === 40);

    expect(EdgeAppDatabase::sync($this->site, 'mongodb', 'mongodb', 'sleep', '0.5', 300, 10))->toBeNull();
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['engine'] === 'mongodb'
        && $request['memory_mb'] === 2048 && $request['disk_gb'] === 10 && $request['password'] === $password);

    EdgeAppDatabase::sync($this->site, 'mongodb', 'sql');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'https://gateway.test/tenants/'.$id);
    expect(env($this->site, 'MONGODB_URI'))->toBe('');
});

test('mongodb is only offered when the gateway is configured', function () {
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $test = fn () => Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site]);

    // Engines are picked when adding a database (sheets/database-add); starting one needs the gateway.
    Http::fake(['gateway.test/*' => Http::response([])]);
    $test()->call('openAddDatabase')->openSheet('resources-database-add')->assertSee('MongoDB')
        ->set('newDatabase.engine', 'mongodb')->set('newDatabase.name', 'docs')->call('createDatabase')->assertHasNoErrors();
    expect($this->site->fresh()->edgeMeta()['database']['engine'])->toBe('mongodb');

    config(['edge.valkey.api_url' => null]);
    $test()->call('openAddDatabase')->set('newDatabase.engine', 'mongodb')->set('newDatabase.name', 'more')->call('createDatabase')
        ->assertHasErrors('newDatabase');
});

test('mysql is a dply database: DB_* on 3306, resize with DB_PASSWORD, delete through the gateway', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);
    $id = 'my-'.strtolower($this->site->id);

    expect(EdgeAppDatabase::sync($this->site, 'sql', 'mysql', 'sleep', '0.25', 300, 1))->toBeNull();
    $password = env($this->site, 'DB_PASSWORD');

    expect($this->site->edgeMeta()['database'])->toMatchArray(['engine' => 'mysql', 'provider' => 'dply', 'remote_id' => $id])
        ->and(env($this->site, 'DB_CONNECTION'))->toBe('mysql')
        ->and(env($this->site, 'DB_HOST'))->toBe($id.'.db.dply.test')
        ->and(env($this->site, 'DB_PORT'))->toBe('3306')
        ->and(env($this->site, 'DB_USERNAME'))->toBe('app')
        ->and(env($this->site, 'DATABASE_URL'))->toContain('@'.$id.'.db.dply.test:3306/app?ssl-mode=REQUIRED');
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['engine'] === 'mysql' && $request['password'] === $password);

    expect(EdgeAppDatabase::sync($this->site, 'mysql', 'mysql', 'sleep', '0.5', 300, 5))->toBeNull();
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['engine'] === 'mysql' && $request['memory_mb'] === 2048 && $request['disk_gb'] === 5 && $request['password'] === $password);

    EdgeAppDatabase::sync($this->site, 'mysql', 'sql');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'https://gateway.test/tenants/'.$id);
});

test('mysql is offered only when the gateway is configured', function () {
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $test = fn () => Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site]);

    Http::fake(['gateway.test/*' => Http::response([])]);
    $test()->call('openAddDatabase')->set('newDatabase.engine', 'mysql')->set('newDatabase.name', 'shop')->call('createDatabase')->assertHasNoErrors();
    expect($this->site->fresh()->edgeMeta()['database']['engine'])->toBe('mysql');

    config(['edge.valkey.api_url' => null]);
    $test()->call('openAddDatabase')->set('newDatabase.engine', 'mysql')->set('newDatabase.name', 'shop2')->call('createDatabase')
        ->assertHasErrors('newDatabase');
});

test('a point-in-time restore runs as a queued job and records its result', function () {
    Queue::fake();
    Http::fake(['gateway.test/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $target = now()->utc()->subHour()->startOfSecond();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site])
        ->set('postgresRestoreAt', now()->utc()->subDays(15)->format('Y-m-d\TH:i:s'))->call('restorePostgres')->assertSet('postgresRestoreResult', 'Pick a time in the last 14 days.')
        ->set('postgresRestoreAt', $target->format('Y-m-d\TH:i:s'))->call('restorePostgres')->assertSet('postgresRestoreResult', null);

    Queue::assertPushedOn('dply', RestoreEdgeDplyPostgresJob::class);
    expect($this->site->fresh()->edgeMeta()['database']['restore'])->toBe(['status' => 'running', 'target' => $target->format('Y-m-d\TH:i:s\Z')]);

    (new RestoreEdgeDplyPostgresJob((string) $this->site->id, $target->format('Y-m-d\TH:i:s\Z')))->handle();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/restore') && $request['target_time'] === $target->format('Y-m-d\TH:i:s\Z'));
    expect($this->site->fresh()->edgeMeta()['database']['restore']['status'])->toBe('done');
});

test('mongodb and mysql restore to a point in time through the same job', function (string $engine) {
    Http::fake(['gateway.test/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', $engine);
    $this->site->save();
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $target = now()->utc()->subDay()->startOfSecond()->format('Y-m-d\TH:i:s\Z');

    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site])
        ->call('selectDatabase', $engine)->assertSee('Restore to a point in time');

    (new RestoreEdgeDplyPostgresJob((string) $this->site->id, $target))->handle();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/restore') && $request['target_time'] === $target);
    expect($this->site->fresh()->edgeMeta()['database']['restore']['status'])->toBe('done');
    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()])
        ->call('selectDatabase', $engine)->assertSee('Restored to');
})->with(['mongodb', 'mysql']);

test('the collector records backup status and notifies once when backups start failing', function () {
    $status = ['last_ok_at' => '2026-09-24T10:00:00Z', 'last_error' => 'mysqldump: disk full', 'last_error_at' => '2026-09-25T10:00:00Z'];
    Http::fake([
        'gateway.test/usage' => Http::response(['awake_seconds' => []]),
        'gateway.test/tenants/*/backup' => Http::response($status),
        'gateway.test/*' => Http::response([]),
    ]);
    EdgeAppDatabase::sync($this->site, 'sql', 'mysql');
    $this->site->save();

    app(EdgeValkeyUsageCollector::class)->collect();
    app(EdgeValkeyUsageCollector::class)->collect();

    expect($this->site->fresh()->edgeMeta()['database']['backup'])->toMatchArray($status + ['alerted' => true])
        ->and(NotificationEvent::query()->where('event_key', 'site.errors.operation_failed')->count())->toBe(1);
});

test('a pod still pruning to an old plan\'s days gets the plan\'s days', function () {
    Http::fake([
        'gateway.test/usage' => Http::response(['awake_seconds' => []]),
        'gateway.test/tenants/*/backup' => Http::response(['last_ok_at' => '2026-10-02T04:00:00Z', 'retention_days' => 7]),
        'gateway.test/*' => Http::response([]),
    ]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();
    $days = EdgeDplyDatabase::backupDays($this->site);

    app(EdgeValkeyUsageCollector::class)->collect();

    expect($days)->not->toBe(7);
    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/backup-days') && $request['days'] === $days);
});

test('a failing change log and a lost window of changes are reported', function () {
    $status = [
        'last_ok_at' => '2026-09-25T10:00:00Z',
        'log_ok_at' => '2026-09-25T10:05:00Z', 'log_error' => 'upload binlog.000004: timeout', 'log_error_at' => '2026-09-25T10:06:00Z',
        'lost' => 'changes between 2026-09-25 09:00:00 and 2026-09-25 09:01:00 UTC were written faster than they could be saved and cannot be restored', 'lost_at' => '2026-09-25T09:02:00Z',
    ];
    Http::fake([
        'gateway.test/usage' => Http::response(['awake_seconds' => []]),
        'gateway.test/tenants/*/backup' => Http::response($status),
        'gateway.test/*' => Http::response([]),
    ]);
    EdgeAppDatabase::sync($this->site, 'sql', 'mongodb');
    $this->site->save();

    app(EdgeValkeyUsageCollector::class)->collect();
    app(EdgeValkeyUsageCollector::class)->collect();

    expect(EdgeDplyDatabase::backupProblem($status))->toBe('Saving recent changes is failing: upload binlog.000004: timeout')
        ->and(EdgeDplyDatabase::backupProblem(['last_ok_at' => '2026-09-25T10:00:00Z', 'log_ok_at' => '2026-09-25T10:07:00Z'] + $status))->toBeNull()
        ->and(NotificationEvent::query()->pluck('title')->all())->toBe([
            'Database backup failed for '.$this->site->name,
            'Some database changes for '.$this->site->name.' cannot be restored',
        ]);
});

test('the database panel shows its state, backups, and how to connect without waking it', function () {
    $id = EdgeDplyDatabase::tenantId($this->site);
    Http::fake([
        "gateway.test/tenants/{$id}/backup" => Http::response(['last_ok_at' => now()->subMinutes(20)->toIso8601String()]),
        "gateway.test/tenants/{$id}" => fn ($request) => $request->method() === 'GET'
            ? Http::response(['id' => $id, 'awake' => true, 'idle_seconds' => 60, 'sleep_after' => 300])
            : Http::response([]),
        'gateway.test/*' => Http::response([]),
    ]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()])
        ->call('loadDatabaseStatus')
        ->assertSee('Awake')
        ->assertSee('Sleeps in 4m without a connection')
        ->assertSee('Healthy')
        ->assertSee('psql')
        ->assertSee($this->site->edgeMeta()['database']['host']);

    // Status only reads: it never asks the gateway to sleep, restore, or change the database.
    Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'DELETE'], true));
});

test('mongodb stats come from its agent through the gateway, without the app password', function () {
    $id = EdgeDplyDatabase::tenantId($this->site, 'mongodb');
    Http::fake([
        "gateway.test/tenants/{$id}/stats" => Http::response(['engine' => 'mongodb', 'version' => 'MongoDB 7.0.43', 'uptime_seconds' => 60, 'size_bytes' => 126721, 'tables' => 2, 'rows' => 501, 'connections' => 5, 'max_connections' => 8192, 'cache_hit_ratio' => 100, 'commits' => 501, 'rollbacks' => 0, 'largest' => [['name' => 'notes', 'rows' => 500, 'bytes' => 122596]]]),
        'gateway.test/*' => Http::response([]),
    ]);
    EdgeAppDatabase::sync($this->site, 'sql', 'mongodb');
    $this->site->save();
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()])
        ->call('loadDatabaseStats')
        ->assertSet('databaseStatsError', null)
        ->assertSet('databaseStats.version', 'MongoDB 7.0.43')
        ->assertSee('notes')
        ->assertSee('501');
});

test('the panel reads insights from the snapshot, never waking the database', function () {
    $id = EdgeDplyDatabase::tenantId($this->site);
    Http::fake([
        "gateway.test/tenants/{$id}/insights*" => Http::response([
            'awake' => false, 'taken_at' => now()->subHour()->toIso8601String(), 'disk_bytes' => 10, 'disk_used_bytes' => 9,
            'queries' => [['query' => 'select * from orders where user_id = $1', 'calls' => 1200, 'total_ms' => 4800.5, 'mean_ms' => 4, 'rows' => 1200]],
            'full_scans' => [['table' => 'orders', 'scans' => 900, 'index_scans' => 3, 'rows' => 50000]],
        ]),
        'gateway.test/*' => Http::response([]),
    ]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()])
        ->call('loadDatabaseInsights')
        ->assertSee('select * from orders where user_id = $1')
        ->assertSee('Probably needs an index')
        ->assertSee('a disk only grows');

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/insights?cached=1'));
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/insights') && ! str_contains($request->url(), 'cached=1'));
});

test('an import loads only files under the app\'s own database, as one queued job', function () {
    Queue::fake();
    Http::fake(['gateway.test/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();
    $id = $this->site->fresh()->edgeMeta()['database']['remote_id'];
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    $panel = Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()]);
    $panel->call('importDatabase', 'exports', '../../other/exports/x.pgdump');
    $panel->call('importDatabase', 'backups', 'x.pgdump');
    Queue::assertNotPushed(TransferEdgeDplyDatabaseJob::class);

    $panel->call('importDatabase', 'imports', 'app.pgdump');
    Queue::assertPushed(TransferEdgeDplyDatabaseJob::class, fn ($job): bool => $job->kind === 'import' && $job->key === "tenants/{$id}/imports/app.pgdump");
    $panel->call('exportDatabase');
    Queue::assertPushed(TransferEdgeDplyDatabaseJob::class, 1); // one at a time
    expect((new TransferEdgeDplyDatabaseJob('x', 'import'))->tries)->toBe(1);
});

test('the database sampler keeps one point a day and alerts once on a filling disk', function () {
    Http::fake([
        'gateway.test/tenants/*/insights*' => Http::response(['awake' => false, 'size_bytes' => 800, 'disk_bytes' => 1000, 'disk_used_bytes' => 850, 'connections' => 2, 'max_connections' => 100]),
        'gateway.test/*' => Http::response([]),
    ]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();

    $this->artisan('dply:edge:sample-databases')->assertSuccessful();
    $this->artisan('dply:edge:sample-databases')->assertSuccessful();

    expect($this->site->fresh()->edgeMeta()['database']['history'])->toHaveCount(1)
        ->and($this->site->fresh()->edgeMeta()['database']['history'][0])->toMatchArray(['size' => 800, 'disk_used' => 850, 'disk' => 1000])
        ->and(NotificationEvent::query()->where('event_key', 'edge.database.disk_filling')->count())->toBe(1)
        ->and(NotificationEvent::query()->where('event_key', 'edge.database.connections_high')->count())->toBe(0);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/insights') && ! str_contains($request->url(), 'cached=1'));
});
