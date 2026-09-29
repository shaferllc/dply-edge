<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDatabaseResizeTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Support\EdgeDatabaseResize;
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
    // One fake for the whole test: a later Http::fake would sit behind this wildcard.
    $this->insights = [];
    Http::fake(fn ($request) => Http::response(str_contains($request->url(), '/insights') ? $this->insights : []));
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();
    $this->site->refresh();
});

/** Hourly samples ending now: [share of memory held, cache hit %]. */
function withSamples(Site $site, int $count, float $share, ?float $hit = 99.5, int $everySeconds = 3600): void
{
    $database = $site->edgeMeta()['database'];
    $database['memory'] = array_map(fn (int $i) => [now()->getTimestamp() - ($count - 1 - $i) * $everySeconds, (int) round(1024 * $share), 1024, $hit], range(0, $count - 1));
    $site->mergeEdgeMeta(['database' => $database]);
    $site->save();
}

test('a fresh snapshot adds one sample; a repeated or stale one does not', function () {
    $snapshot = ['memory_bytes' => 1073741824, 'memory_anon_bytes' => 536870912, 'cache_hit_ratio' => 98.2, 'taken_at' => now()->toIso8601String()];

    expect(EdgeDatabaseResize::record($this->site, $snapshot))->toBe([now()->getTimestamp(), 512, 1024, 98.2])
        ->and(EdgeDatabaseResize::record($this->site, $snapshot))->toBeNull() // asleep: same snapshot again
        ->and(EdgeDatabaseResize::record($this->site, ['taken_at' => now()->subHours(2)->toIso8601String()] + $snapshot))->toBeNull()
        ->and(EdgeDatabaseResize::samples($this->site->fresh()))->toHaveCount(1);
});

test('six hours near the memory limit, or of cache misses, suggest the next size up', function () {
    withSamples($this->site, 5, 0.9);
    expect(EdgeDatabaseResize::suggestion($this->site))->toBeNull(); // not six yet

    withSamples($this->site, 6, 0.9);
    expect(EdgeDatabaseResize::suggestion($this->site))->toMatchArray(['from' => '0.25', 'size' => '0.5', 'direction' => 'up']);

    withSamples($this->site, 6, 0.4, 82.0);
    expect(EdgeDatabaseResize::suggestion($this->site))->toMatchArray(['size' => '0.5', 'direction' => 'up'])
        ->and(EdgeDatabaseResize::suggestion($this->site)['reason'])->toContain('from memory');
});

test('a week of little memory suggests the next size down, and a dismissal holds it back', function () {
    $database = $this->site->edgeMeta()['database'];
    $this->site->mergeEdgeMeta(['database' => ['size' => '0.5'] + $database]);
    $this->site->save();
    withSamples($this->site, 30, 0.2, 99.8, 6 * 3600); // every 6h across 7+ days

    expect(EdgeDatabaseResize::suggestion($this->site))->toMatchArray(['from' => '0.5', 'size' => '0.25', 'direction' => 'down']);

    EdgeDatabaseResize::dismiss($this->site, '0.25');
    expect(EdgeDatabaseResize::suggestion($this->site->fresh()))->toBeNull();
    $this->travel(31)->days();
    withSamples($this->site->fresh(), 30, 0.2, 99.8, 6 * 3600);
    expect(EdgeDatabaseResize::suggestion($this->site->fresh()))->not->toBeNull();
});

test('the sampler alerts on memory and sends a suggestion once', function () {
    withSamples($this->site, 5, 0.92);
    $this->travel(1)->hours(); // the new snapshot comes an hour after the last sample
    $this->insights = ['awake' => true, 'disk_bytes' => 1000, 'disk_used_bytes' => 10, 'memory_bytes' => 1073741824, 'memory_anon_bytes' => 1010000000, 'cache_hit_ratio' => 97, 'taken_at' => now()->toIso8601String()];

    $this->artisan('dply:edge:sample-databases')->assertSuccessful();
    $this->artisan('dply:edge:sample-databases')->assertSuccessful();

    $suggested = NotificationEvent::query()->where('event_key', 'edge.database.resize_suggested')->get();
    expect(NotificationEvent::query()->where('event_key', 'edge.database.memory_high')->count())->toBe(1)
        ->and($suggested)->toHaveCount(1)
        ->and($suggested[0]->url)->toEndWith('?sheet=database');
});

test('approving now resizes through the gateway and says so', function () {
    withSamples($this->site, 6, 0.9);
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()])
        ->call('resizeDatabaseNow');

    expect($this->site->fresh()->edgeMeta()['database']['size'])->toBe('0.5')
        ->and(NotificationEvent::query()->where('event_key', 'edge.database.resized')->count())->toBe(1);
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && ($r['memory_mb'] ?? null) === 2048);
});

test('approving for tonight waits for the org’s 03:00, then the command resizes', function () {
    withSamples($this->site, 6, 0.9);
    $this->site->organization->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    $this->travelTo(now('America/Los_Angeles')->setTime(15, 0));
    $at = EdgeDatabaseResize::schedule($this->site->fresh(), '0.5', null);

    expect($at->format('H:i'))->toBe('03:00');
    $this->artisan('dply:edge:resize-databases')->assertSuccessful();
    expect($this->site->fresh()->edgeMeta()['database']['size'])->toBe('0.25'); // not yet

    $this->travelTo($at->copy()->addMinute());
    $this->artisan('dply:edge:resize-databases')->assertSuccessful();
    expect($this->site->fresh()->edgeMeta()['database'])->toMatchArray(['size' => '0.5'])->not->toHaveKey('resize_scheduled')
        ->and(NotificationEvent::query()->where('event_key', 'edge.database.resized')->count())->toBe(1);
});

test('the database sheet shows the suggestion with its choices', function () {
    withSamples($this->site, 6, 0.9);
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()])
        ->openSheet('resources-database')
        ->assertSee('Suggested: a bigger size')
        ->assertSee('Resize now')
        ->assertSee('Resize tonight')
        ->call('dismissDatabaseResize');

    expect(EdgeDatabaseResize::suggestion($this->site->fresh()))->toBeNull();
});

test('a bigger disk is only picked after confirming, and a smaller one never', function () {
    $user = User::factory()->create();
    $this->site->organization->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $database = $this->site->fresh()->edgeMeta()['database'];
    $this->site->mergeEdgeMeta(['database' => ['disk_gb' => 5] + $database]);
    $this->site->save();

    $sheet = Livewire::actingAs($user)->test(Resources::class, ['server' => $this->site->server, 'site' => $this->site->fresh()])
        ->call('selectPostgresDisk', 1)   // smaller: ignored
        ->assertSet('growDiskTo', null)
        ->call('selectPostgresDisk', 25)  // bigger: asks, changes nothing yet
        ->assertSet('growDiskTo', 25);
    expect($this->site->fresh()->edgeMeta()['database']['disk_gb'])->toBe(5);

    $sheet->call('cancelDiskGrow')->assertSet('growDiskTo', null);
    expect($this->site->fresh()->edgeMeta()['database']['disk_gb'])->toBe(5);

    $sheet->call('selectPostgresDisk', 10)->call('confirmDiskGrow')->assertSet('growDiskTo', null);
    expect($this->site->fresh()->edgeMeta()['database']['disk_gb'])->toBe(10);
    Http::assertSent(fn ($r) => $r->method() === 'PUT' && ($r['disk_gb'] ?? $r['disk'] ?? null) !== null);
});
