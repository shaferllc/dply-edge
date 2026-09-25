<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDplyDatabaseTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgePostgresUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Services\EdgePostgresUsageCollector;
use App\Modules\Edge\Services\EdgeValkeyUsageCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.valkey.api_url' => 'https://gateway.test',
        'edge.valkey.token' => 'tok',
        'edge.valkey.db_domain' => 'db.dply.test',
        'subscription.standard.stripe.tier_pro' => 'price_tier_pro',
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

    expect(EdgeAppDatabase::sync($this->site, 'sql', 'postgres', '', 'sleep', '0.5', '', 300, 0, 5))->toBeNull();
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
        && $request['sleep_after'] === 300 && $request['password'] === env($this->site, 'DB_PASSWORD'));
});

test('a size only neon offers falls back to the smallest dply size', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);

    EdgeAppDatabase::sync($this->site, 'sql', 'postgres', '', 'sleep', '4');

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['memory_mb'] === 1024 && $request['disk_gb'] === 1);
});

test('changing a dply database grows the disk, refuses to shrink it, and reuses the password', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres', '', 'sleep', '0.25', '', 300, 0, 5);
    $password = env($this->site, 'DB_PASSWORD');

    expect(EdgeAppDatabase::sync($this->site, 'postgres', 'postgres', '', 'sleep', '0.25', '', 300, 0, 1))->toBe('A database disk only grows. Pick 5 GB or more.')
        ->and(EdgeAppDatabase::sync($this->site, 'postgres', 'postgres', '', 'awake', '0.5', '', -1, 0, 10))->toBeNull()
        ->and($this->site->edgeMeta()['database'])->toMatchArray(['size' => '0.5', 'disk_gb' => 10, 'suspend' => -1]);
    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['disk_gb'] === 10
        && $request['memory_mb'] === 2048 && $request['sleep_after'] === 0 && $request['password'] === $password);
});

test('removing a dply database deletes the tenant, and a neon record still goes to neon', function () {
    config(['edge.neon.api_key' => 'neon-key']);
    Http::fake(['gateway.test/*' => Http::response([]), 'console.neon.tech/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $id = $this->site->edgeMeta()['database']['remote_id'];

    EdgeAppDatabase::sync($this->site, 'postgres', 'sql');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && $request->url() === 'https://gateway.test/tenants/'.$id);

    // An older Neon record (no provider) is released through Neon, not the gateway.
    $this->site->mergeEdgeMeta(['database' => ['engine' => 'postgres', 'name' => 'production', 'status' => 'ready', 'remote_id' => 'autumn-voice-123']]);
    EdgeAppDatabase::sync($this->site, 'postgres', 'sql');
    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'console.neon.tech') && str_contains($request->url(), 'autumn-voice-123'));
    Http::assertNotSent(fn ($request): bool => $request->method() === 'DELETE' && str_contains($request->url(), 'autumn-voice-123') && str_contains($request->url(), 'gateway.test'));
});

test('the collector bills dply postgres compute while awake and the disk every hour', function () {
    Http::fake(['gateway.test/tenants/*' => Http::response([])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres', '', 'sleep', '0.5', '', 300, 0, 5);
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

test('the neon usage collector leaves dply databases alone', function () {
    config(['edge.neon.api_key' => 'neon-key']);
    Http::fake(['gateway.test/*' => Http::response([]), 'console.neon.tech/*' => Http::response(['periods' => []])]);
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();

    app(EdgePostgresUsageCollector::class)->collectForDate(now());

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'console.neon.tech'));
});

test('the resources tab offers disk sizes instead of neon location and restore for dply postgres', function () {
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
        ->assertSee('1/4 vCPU')
        ->assertDontSee('4 vCPU · 16 GB')
        ->call('selectPostgresDisk', 10)
        ->assertSet('draftPostgresDisk', 10)
        ->call('selectPostgresSize', '2')
        ->assertNotSet('draftPostgresSize', '2');
});

test('mongodb is a dply database with a tls uri, and resizes with its own password', function () {
    Http::fake(['gateway.test/*' => Http::response([])]);
    $id = 'mg-'.strtolower($this->site->id);

    expect(EdgeAppDatabase::sync($this->site, 'sql', 'mongodb', '', 'sleep', '0.25', '', 300, 0, 5))->toBeNull();
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

    expect(EdgeAppDatabase::sync($this->site, 'mongodb', 'mongodb', '', 'sleep', '0.5', '', 300, 0, 10))->toBeNull();
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

    $test()->assertSeeHtml("selectDatabase('mongodb')")->call('selectDatabase', 'mongodb')->assertSet('draftDatabase', 'mongodb')->assertSeeHtml('id="postgres-disk"');

    config(['edge.valkey.api_url' => null]);
    $test()->assertDontSeeHtml("selectDatabase('mongodb')")->call('selectDatabase', 'mongodb')->assertNotSet('draftDatabase', 'mongodb');
});
