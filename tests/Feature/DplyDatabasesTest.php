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

test('an app on SQLite manages SQLite: its saved file, a download, and adding a database', function () {
    \Illuminate\Support\Facades\Storage::fake('edge_r2');
    config(['edge.disk.name' => 'edge_r2']);
    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id, 'type' => \App\Enums\SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $this->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();
    $page = \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Sites\Edge\Workspace\Resources::class, ['server' => $this->site->server, 'site' => $this->site])
        ->call('addDatabase');

    $page->call('loadSqliteFile')->assertSet('sqliteFile', ['exists' => false])
        ->assertSee('/tmp/database.sqlite')->assertSee('Add a database')->assertDontSee('Stats &amp; backups', false);

    \Illuminate\Support\Facades\Storage::disk('edge_r2')->put(\App\Modules\Edge\Services\Containers\EdgeContainerDeployer::sqliteKey($this->site), str_repeat('x', 2048));
    $page->call('loadSqliteFile')->assertSet('sqliteFile.exists', true)->assertSet('sqliteFile.bytes', 2048)->assertSee('Download a copy');
});

function workspace(\Tests\TestCase $test): \Livewire\Features\SupportTesting\Testable
{
    $user = \App\Models\User::factory()->create();
    $test->org->users()->attach($user->id, ['role' => 'owner']);
    $test->site->forceFill(['user_id' => $user->id, 'type' => \App\Enums\SiteType::Static, 'status' => Site::STATUS_EDGE_ACTIVE])->save();
    $test->site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    return \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Sites\Edge\Workspace\Resources::class, ['server' => $test->site->server, 'site' => $test->site]);
}

test('the stats, console and backups panel works on another database, keeping its state on its row', function () {
    \Illuminate\Support\Facades\Bus::fake([\App\Modules\Edge\Jobs\TransferEdgeDplyDatabaseJob::class, \App\Modules\Edge\Jobs\RestoreEdgeDplyPostgresJob::class]);
    DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $extra = DplyDatabases::create($this->site->fresh(), 'postgres', 'reports', '0.25', 300, 1);
    $page = workspace($this);

    $page->call('openDatabasePanel', $extra->id)->assertSet('databaseFocus', $extra->id)
        ->assertSee('reports · Postgres')
        ->set('databaseConsoleSql', 'select 1')->call('runDatabaseConsole');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/tenants/'.$extra->remote_id.'/action/query') && $r['sql'] === 'select 1');

    $page->call('exportDatabase');
    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Modules\Edge\Jobs\TransferEdgeDplyDatabaseJob::class, fn ($job) => $job->databaseId === $extra->id && $job->kind === 'export');
    expect($extra->fresh()->state['transfer']['status'])->toBe('running')
        ->and($this->site->fresh()->edgeMeta()['database'])->not->toHaveKey('transfer');

    $page->set('postgresRestoreAt', now()->subHour()->utc()->format('Y-m-d\TH:i'))->call('restorePostgres')->assertSet('postgresRestoreResult', null);
    expect($extra->fresh()->state['restore']['status'])->toBe('running');

    // Back on the primary; an id that is not this app's leaves it there.
    $page->call('openDatabasePanel', null)->assertSet('databaseFocus', null)
        ->call('openDatabasePanel', 'not-this-apps')->assertSet('databaseFocus', null);
});

test('a transfer or restore job lands where the database’s state lives', function () {
    $main = DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $extra = DplyDatabases::create($this->site->fresh(), 'postgres', 'reports', '0.25', 300, 1);

    (new \App\Modules\Edge\Jobs\TransferEdgeDplyDatabaseJob('', 'export', '', $extra->id))->handle();
    (new \App\Modules\Edge\Jobs\RestoreEdgeDplyPostgresJob('', '2026-09-28T10:00:00Z', $main->id))->handle();

    expect($extra->fresh()->state['transfer']['status'])->toBe('done')
        ->and($this->site->fresh()->edgeMeta()['database']['restore']['status'])->toBe('done');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/tenants/'.$main->remote_id.'/restore'));
});

test('the primary’s awake time counts only its own usage, not the other databases on the app', function () {
    $main = DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $extra = DplyDatabases::create($this->site->fresh(), 'postgres', 'reports', '0.25', 300, 1);
    foreach ([[$main, 3600], [$extra, 7200]] as [$db, $cu]) {
        \App\Models\EdgePostgresUsage::query()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'project_id' => $db->remote_id, 'date' => now()->toDateString(), 'compute_unit_seconds' => $cu, 'storage_byte_hours' => 0]);
    }
    $cu = \App\Modules\Billing\Support\UsagePrice::databaseBilledCu('0.25');

    $page = workspace($this);
    expect($page->viewData('databaseUsage')['awake_seconds'])->toBe((int) round(3600 / $cu));
    $page->call('openDatabasePanel', $extra->id);
    expect($page->viewData('databaseUsage')['awake_seconds'])->toBe((int) round(7200 / $cu));
});

test('another database gets its own resize suggestion, approved for tonight and run by the scheduler', function () {
    config(['dply.databases.large_sizes_enabled' => true]);
    DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $extra = DplyDatabases::create($this->site->fresh(), 'postgres', 'reports', '0.25', 300, 1);
    $now = now()->getTimestamp();
    $samples = array_map(fn ($h) => [$now - $h * 3600, 950, 1024, 99.0], range(5, 0));
    DplyDatabases::remember($extra, ['memory' => $samples]);

    $suggestion = \App\Modules\Edge\Support\EdgeDatabaseResize::suggestion($extra->fresh());
    expect($suggestion['direction'] ?? null)->toBe('up');

    $page = workspace($this)->call('openExtraDatabase', $extra->id)->assertSee('Suggested: a bigger size');
    $page->call('resizeDatabaseTonight', $extra->id);
    expect($extra->fresh()->state['resize_scheduled']['size'])->toBe($suggestion['size'])
        ->and($this->site->fresh()->edgeMeta()['database'])->not->toHaveKey('resize_scheduled');

    $this->travelTo(now()->addDay()->addHours(2));
    $this->artisan('dply:edge:resize-databases')->assertSuccessful();
    expect($extra->fresh()->size)->toBe($suggestion['size'])
        ->and($extra->fresh()->state)->not->toHaveKey('resize_scheduled');
});

test('the API lists an app’s databases and runs a query, an export and a restore on one by name', function () {
    \Illuminate\Support\Facades\Bus::fake([\App\Modules\Edge\Jobs\TransferEdgeDplyDatabaseJob::class, \App\Modules\Edge\Jobs\RestoreEdgeDplyPostgresJob::class]);
    DplyDatabases::create($this->site, 'postgres', 'main', '0.25', 300, 1);
    $reports = DplyDatabases::create($this->site->fresh(), 'mysql', 'reports', '0.25', 300, 1);
    $user = \App\Models\User::factory()->create();
    $this->org->users()->attach($user->id, ['role' => 'owner']);
    $this->site->forceFill(['user_id' => $user->id])->save();
    $token = fn (array $abilities) => ['Authorization' => 'Bearer '.\App\Models\ApiToken::createToken($user, $this->org, 't', null, $abilities)['plaintext'], 'Accept' => 'application/json'];
    $base = '/api/v1/edge/sites/'.$this->site->id.'/databases';

    $this->getJson($base, $token(['edge.read']))->assertOk()
        ->assertJsonPath('data.0.name', 'main')->assertJsonPath('data.0.primary', true)->assertJsonPath('data.0.env_prefix', '')
        ->assertJsonPath('data.1.name', 'reports')->assertJsonPath('data.1.engine', 'mysql')->assertJsonPath('data.1.env_prefix', 'REPORTS');

    // A read token can look but not query.
    $this->postJson($base.'/reports/query', ['sql' => 'select 1'], $token(['edge.read']))->assertForbidden();
    $write = $token(['edge.read', 'edge.write']);
    $this->postJson($base.'/reports/query', ['sql' => 'select 1'], $write)->assertOk();
    Http::assertSent(fn ($r) => str_contains($r->url(), '/tenants/'.$reports->remote_id.'/action/query'));
    $this->postJson($base.'/reports/query', [], $write)->assertStatus(422);

    $this->postJson($base.'/reports/exports', [], $write)->assertStatus(202)->assertJsonPath('data.transfer.status', 'running');
    $this->postJson($base.'/reports/exports', [], $write)->assertStatus(422)->assertJsonPath('message', 'An export or import is already running.');
    $this->postJson($base.'/'.$reports->id.'/restore', ['at' => now()->subDays(8)->toIso8601String()], $write)->assertStatus(422);
    $this->postJson($base.'/'.$reports->id.'/restore', ['at' => now()->subHour()->toIso8601String()], $write)->assertStatus(202)->assertJsonPath('data.restore.status', 'running');
    \Illuminate\Support\Facades\Bus::assertDispatched(\App\Modules\Edge\Jobs\RestoreEdgeDplyPostgresJob::class, fn ($job) => $job->databaseId === $reports->id);

    $this->getJson($base.'/nope', $token(['edge.read']))->assertNotFound();
});

test('database tools say to redeploy when the running app has another database, and run a named one by its prefix', function () {
    $this->site->forceFill(['meta' => array_replace_recursive($this->site->meta ?? [], ['edge' => ['build' => ['framework' => 'laravel'], 'live_url' => 'https://shop.on-dply.live']])])->save();
    $live = \App\Models\EdgeDeployment::query()->create(['site_id' => $this->site->id, 'organization_id' => $this->org->id, 'status' => \App\Models\EdgeDeployment::STATUS_LIVE, 'meta' => ['container' => ['database' => ['' => ['connection' => 'pgsql', 'host' => 'pg-old.db.dply.test']]]]]);
    $page = workspace($this);

    // On SQLite now, but the running deploy still has the old Postgres.
    $page->call('runDatabaseCommand', 'status')->assertSet('databaseCommandOutput', fn ($out) => str_contains($out, 'still uses Postgres') && str_contains($out, 'Redeploy'));
    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/_dply/command'));

    DplyDatabases::create($this->site->fresh(), 'postgres', 'main', '0.25', 300, 1);
    $reports = DplyDatabases::create($this->site->fresh(), 'mysql', 'reports', '0.25', 300, 1);
    $page->call('runDatabaseCommand', 'migrate', $reports->id)->assertSet('databaseCommandOutput', fn ($out) => str_contains($out, 'doesn’t have reports yet'));

    $live->forceFill(['meta' => ['container' => ['database' => \App\Modules\Edge\Services\Containers\EdgeContainerDeployer::deployedDatabases(['REPORTS_DB_CONNECTION' => 'mysql', 'REPORTS_DB_HOST' => $reports->host])]]])->save();
    $page->call('runDatabaseCommand', 'migrate', $reports->id);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/_dply/command') && $r['command'] === 'migrate' && $r['database'] === 'REPORTS');
});
