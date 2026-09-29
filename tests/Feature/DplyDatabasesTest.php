<?php

declare(strict_types=1);

namespace Tests\Feature\DplyDatabasesTest;

use App\Models\DplyDatabase;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Services\DplyDatabases;
use App\Modules\Edge\Services\EdgeAppDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.valkey.api_url' => 'https://gateway.test', 'edge.valkey.token' => 'tok', 'edge.valkey.db_domain' => 'db.dply.test',
        'subscription.standard.stripe.tier_pro' => 'price_tier_pro', 'dply.databases.large_sizes_enabled' => false,
    ]);
    // One fake for the file: a later Http::fake would sit behind a wildcard.
    $this->awake = [];
    $this->insights = [];
    $this->backup = [];
    Http::fake(fn ($request) => Http::response(match (true) {
        str_ends_with($request->url(), '/usage') => ['awake_seconds' => $this->awake],
        str_contains($request->url(), '/insights') => $this->insights,
        str_ends_with($request->url(), '/backup') => $this->backup,
        default => [],
    }));
    $this->org = Organization::factory()->create();
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $this->org->id]);
    $this->site = app_in($this->org, 'Shop');
});

function app_in(Organization $org, string $name): Site
{
    return Site::factory()->create([
        'organization_id' => $org->id, 'name' => $name,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'database' => ['engine' => 'sql', 'name' => 'production']]],
    ]);
}

function envOf(Site $site): array
{
    return EdgeSiteEnvVar::query()->where('site_id', $site->id)->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->get()->pluck('value', 'key')->all();
}

test('an app’s existing database is adopted as its primary, keeping its gateway id and password', function () {
    EdgeAppDatabase::sync($this->site, 'sql', 'postgres');
    $this->site->save();
    $record = $this->site->fresh()->edgeMeta()['database'];

    $databases = DplyDatabases::for($this->site->fresh());

    expect($databases)->toHaveCount(1)
        ->and($databases[0]->remote_id)->toBe($record['remote_id'])
        ->and((bool) $databases[0]->attached_primary)->toBeTrue()
        ->and($databases[0]->password)->toBe(envOf($this->site)['DB_PASSWORD'])
        ->and(DplyDatabases::for($this->site->fresh()))->toHaveCount(1); // adopted once
});

test('a second database gets its own gateway id and prefixed env, leaving DB_* alone', function () {
    $main = DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $analytics = DplyDatabases::create($this->site->fresh(), 'mysql', 'analytics', '0.25', 300, 1);
    $env = envOf($this->site);

    expect($analytics->remote_id)->toBe('my-'.strtolower($analytics->id))
        ->and($env['DB_HOST'])->toBe($main->host)
        ->and($env['DB_CONNECTION'])->toBe('pgsql')
        ->and($env['ANALYTICS_DB_HOST'])->toBe($analytics->host)
        ->and($env['ANALYTICS_DB_CONNECTION'])->toBe('mysql')
        ->and($env)->toHaveKey('ANALYTICS_DATABASE_URL')
        ->and($this->site->fresh()->edgeMeta()['database']['remote_id'])->toBe($main->remote_id);
});

test('making another database primary swaps DB_* and the mirror, and keeps each one’s state', function () {
    $main = DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $this->site->mergeEdgeMeta(['database' => ['history' => [['date' => 'x']]] + $this->site->fresh()->edgeMeta()['database']]);
    $this->site->save();
    $reports = DplyDatabases::create($this->site->fresh(), 'postgres', 'reports', '0.25', 300, 1);

    DplyDatabases::makePrimary($this->site->fresh(), $reports);
    $env = envOf($this->site);

    expect($env['DB_HOST'])->toBe($reports->host)
        ->and($env['MAIN_DB_HOST'])->toBe($main->host)
        ->and($env)->not->toHaveKey('REPORTS_DB_HOST')
        ->and($this->site->fresh()->edgeMeta()['database']['remote_id'])->toBe($reports->remote_id)
        ->and($main->fresh()->state['history'])->toBe([['date' => 'x']]);

    DplyDatabases::makePrimary($this->site->fresh(), $main->fresh());
    expect($this->site->fresh()->edgeMeta()['database']['history'])->toBe([['date' => 'x']]); // state came back with it
});

test('detaching the primary hands over to the next, and the last leaves the app on SQLite', function () {
    $main = DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $second = DplyDatabases::create($this->site->fresh(), 'postgres', 'second', '0.25', 300, 1);

    DplyDatabases::detach($this->site->fresh(), $main);
    expect(envOf($this->site)['DB_HOST'])->toBe($second->host)
        ->and(envOf($this->site))->not->toHaveKey('SECOND_DB_HOST')
        ->and(DplyDatabase::query()->whereKey($main->id)->exists())->toBeTrue(); // detached, still running

    DplyDatabases::detach($this->site->fresh(), $second);
    expect($this->site->fresh()->edgeMeta()['database']['engine'])->toBe('sql')
        ->and(envOf($this->site))->not->toHaveKey('DB_HOST');
});

test('another app attaches an existing database; delete is refused while it is shared, then destroys it', function () {
    $shared = DplyDatabases::create($this->site, 'postgres', 'shared', '0.25', 300, 1);
    $other = app_in($this->org, 'Admin');
    DplyDatabases::attach($other, $shared);

    expect(envOf($other)['DB_HOST'])->toBe($shared->host)
        ->and(fn () => DplyDatabases::delete($shared->fresh(), $this->site))->toThrow(RuntimeException::class, 'still attached');

    DplyDatabases::detach($other, $shared->fresh());
    DplyDatabases::delete($shared->fresh(), $this->site);

    expect(DplyDatabase::query()->whereKey($shared->id)->exists())->toBeFalse();
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), $shared->remote_id));
});

test('a database cannot be attached to another organization’s app', function () {
    $mine = DplyDatabases::create($this->site, 'postgres', 'mine', '0.25', 300, 1);
    $stranger = app_in(Organization::factory()->create(), 'Other');

    expect(fn () => DplyDatabases::attach($stranger, $mine))->toThrow(RuntimeException::class, 'another organization');
});

test('every database bills once: the primary through its app, the others from their own row', function () {
    $main = DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $extra = DplyDatabases::create($this->site->fresh(), 'postgres', 'extra', '0.25', 300, 1);
    $orphan = DplyDatabases::create(app_in($this->org, 'Old'), 'postgres', 'orphan', '0.25', 300, 1);
    DplyDatabases::detach(Site::query()->where('name', 'Old')->first(), $orphan); // detached, still running

    $this->awake = [$main->remote_id => 100, $extra->remote_id => 200, $orphan->remote_id => 300];
    app(\App\Modules\Edge\Services\EdgeValkeyUsageCollector::class)->collect();
    $rows = \App\Models\EdgePostgresUsage::query()->get()->keyBy('project_id');

    expect($rows)->toHaveCount(3)
        ->and($rows[$main->remote_id]->site_id)->toBe($this->site->id)
        ->and($rows[$extra->remote_id]->site_id)->toBe($this->site->id)
        ->and($rows[$orphan->remote_id]->site_id)->toBeNull()
        ->and((int) $rows[$extra->remote_id]->compute_unit_seconds)->toBeGreaterThan(0)
        ->and((int) $rows[$orphan->remote_id]->compute_unit_seconds)->toBeGreaterThan(0);
});

test('from the workspace: add two databases, see both, swap the primary, delete one by name', function () {
    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => \App\Enums\SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    $page = \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Sites\Edge\Workspace\Resources::class, ['server' => $this->site->server, 'site' => $this->site])
        ->call('openAddDatabase')
        ->set('newDatabase.name', 'main')
        ->call('createDatabase')->assertHasNoErrors()
        ->call('openAddDatabase')
        ->set('newDatabase.engine', 'mysql')
        ->set('newDatabase.name', 'reports')
        ->call('createDatabase')->assertHasNoErrors()
        ->assertSee('reports')
        ->assertSee('REPORTS_*');

    $reports = DplyDatabase::query()->where('name', 'reports')->firstOrFail();
    $page->call('openExtraDatabase', $reports->id)
        ->openSheet('resources-database-extra')
        ->assertSee('REPORTS_DB_HOST')
        ->assertSee('Make primary')
        ->call('makeDatabasePrimary', $reports->id);
    expect(envOf($this->site)['DB_CONNECTION'])->toBe('mysql')
        ->and(envOf($this->site))->toHaveKey('MAIN_DB_HOST');

    $main = DplyDatabase::query()->where('name', 'main')->firstOrFail();
    $page->call('openExtraDatabase', $main->id)
        ->set('deleteDatabaseConfirm', 'nope')->call('deleteDatabase', $main->id)->assertHasErrors('database')
        ->set('deleteDatabaseConfirm', 'main')->call('deleteDatabase', $main->id);
    expect(DplyDatabase::query()->whereKey($main->id)->exists())->toBeFalse()
        ->and(envOf($this->site))->not->toHaveKey('MAIN_DB_HOST');
});

test('deleting an app deletes the databases only it used and detaches the shared ones', function () {
    $own = DplyDatabases::create($this->site, 'postgres', 'own', '0.25', 300, 1);
    $shared = DplyDatabases::create($this->site->fresh(), 'postgres', 'shared', '0.25', 300, 1);
    $other = app_in($this->org, 'Admin');
    DplyDatabases::attach($other, $shared->fresh());

    (new \App\Modules\Edge\Jobs\TeardownEdgeSiteJob((string) $this->site->id))->handle();

    expect(DplyDatabase::query()->whereKey($own->id)->exists())->toBeFalse()
        ->and(DplyDatabase::query()->whereKey($shared->id)->exists())->toBeTrue()
        ->and($shared->fresh()->sites()->pluck('sites.id')->all())->toBe([$other->id])
        ->and(envOf($other)['DB_HOST'])->toBe($shared->host);
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), $own->remote_id));
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), $shared->remote_id));
});

test('another database is sampled and alerted on its own, and keeps its backup status', function () {
    DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $extra = DplyDatabases::create($this->site->fresh(), 'postgres', 'events', '0.25', 300, 1);
    $this->insights = ['disk_bytes' => 1000, 'disk_used_bytes' => 950, 'connections' => 3, 'max_connections' => 100];
    $this->backup = ['last_ok_at' => now()->subHour()->toIso8601String()];

    $this->artisan('dply:edge:sample-databases')->assertSuccessful();
    app(\App\Modules\Edge\Services\EdgeValkeyUsageCollector::class)->collect();

    $alerts = \App\Models\NotificationEvent::query()->where('event_key', 'edge.database.disk_filling')->get();
    expect($alerts->pluck('title')->all())->toContain('The events database disk is 95% full')
        ->and($alerts->firstWhere('title', 'The events database disk is 95% full')->subject_id)->toBe($this->site->id)
        ->and($extra->fresh()->state['history'][0])->toMatchArray(['disk_used' => 950, 'disk' => 1000])
        ->and($extra->fresh()->state['backup']['last_ok_at'])->toBe($this->backup['last_ok_at']);
});

test('the organization Databases page attaches, detaches and deletes a database no app uses', function () {
    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);
    $other = app_in($this->org, 'Blog');
    $db = DplyDatabases::create($this->site, 'postgres', 'reports', '0.25', 300, 1);

    $page = \Livewire\Livewire::actingAs($user)->test(\App\Modules\Edge\Livewire\Databases::class)
        ->assertSee('reports')->assertSee('Shop')
        ->set("dplyAttachSite.{$db->id}", $other->id)->call('attachDply', $db->id)->assertHasNoErrors();
    expect($db->sites()->pluck('sites.id')->all())->toContain($other->id);

    $page->set("dplyDeleteConfirm.{$db->id}", 'reports')->call('deleteDply', $db->id)->assertHasErrors("dply.{$db->id}");
    $page->call('detachDply', $db->id, $this->site->id)->call('detachDply', $db->id, $other->id)
        ->assertSee('No app uses it');
    $page->call('deleteDply', $db->id)->assertHasNoErrors();
    expect(DplyDatabase::query()->find($db->id))->toBeNull();
});
