<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\ResourcesTest;

use App\Livewire\Admin\Resources;
use App\Models\AuditLog;
use App\Models\DplyDatabase;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\VerifyDatabaseBackupJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake(['gw.test/tenants/pg-shop/action/query' => Http::response(['columns' => ['id'], 'rows' => [[1]]]), '*' => Http::response([])]);
    $this->org = Organization::factory()->create(['name' => 'Acme']);
    $this->site = Site::factory()->create([
        'organization_id' => $this->org->id,
        'server_id' => Server::factory()->create(['organization_id' => $this->org->id])->id,
        'name' => 'shop',
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => [
            'runtime_mode' => 'container',
            'database' => ['provider' => 'dply', 'engine' => 'postgres', 'remote_id' => 'pg-shop', 'size' => 'flex_1', 'disk_gb' => 10, 'suspend' => 300,
                'backup' => ['last_ok_at' => now()->subHour()->toIso8601String(), 'verify' => ['ok' => false, 'at' => now()->toIso8601String(), 'error' => 'wal-g: no backups']]],
            'connections' => [['kind' => 'redis', 'name' => 'CACHE', 'host' => 'cache.internal', 'target' => 'valkey:nyc3:shop-cache']],
        ]],
    ]);
    DplyDatabase::query()->create([
        'organization_id' => $this->org->id, 'name' => 'reports', 'engine' => 'mysql', 'remote_id' => 'my-reports',
        'password' => str_repeat('x', 40), 'size' => 'flex_1', 'suspend' => 300, 'disk_gb' => 5, 'region' => 'nyc3', 'host' => 'my-reports.db.dply.test', 'state' => [],
    ]);
});

test('lists apps, their database and Valkey, and other databases, across organizations', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(Resources::class)
        ->assertSee('shop')
        ->assertSee('Acme')
        ->assertSee('pg-shop')
        ->assertSee('valkey:nyc3:shop-cache')
        ->assertSee('reports')
        ->assertSee('Restore check failed: wal-g: no backups');

    $keys = collect($component->viewData('groups'))->pluck('key')->all();
    expect($keys)->toBe(['app', 'database', 'redis'])
        ->and($component->viewData('trouble'))->toBe(1);
    Http::assertNothingSent(); // never asks an app or Cloudflare
});

test('filters by kind, search and trouble', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Resources::class)
        ->set('kind', 'database')->assertSee('reports')->assertDontSee('valkey:nyc3:shop-cache')
        ->set('kind', '')->set('search', 'reports')->assertSee('my-reports')->assertDontSee('pg-shop')
        ->set('search', '')->set('troubleOnly', true)->assertSee('pg-shop')->assertDontSee('my-reports');
});

test('guests are sent to log in', function () {
    $this->get(route('admin.resources'))->assertRedirect(route('login', absolute: false));
});

test('a database query needs a reason first, then runs, is logged and shows in the customer\'s activity', function () {
    config(['edge.valkey.api_url' => 'https://gw.test', 'edge.valkey.token' => 'tok', 'edge.valkey.regions' => [['key' => 'nyc3']]]);
    $admin = User::factory()->create();
    $this->actingAs($admin);

    $component = Livewire::test(Resources::class)
        ->call('openQuery', 'pg-shop')
        ->assertSee('Say why you need it')
        ->call('runQuery')->assertForbidden();

    $component = Livewire::test(Resources::class)
        ->call('openQuery', 'pg-shop')
        ->set('accessReason', 'no')->call('startAccess')->assertHasErrors('accessReason')
        ->set('accessReason', 'Ticket 42: orders missing')->call('startAccess')->assertHasNoErrors()
        ->set('querySql', 'select id from orders')->call('runQuery')
        ->assertSet('queryResult', ['columns' => ['id'], 'rows' => [[1]]]);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tenants/pg-shop/action/query') && $r['sql'] === 'select id from orders');
    $actions = AuditLog::query()->where('organization_id', $this->org->id)->orderBy('created_at')->pluck('new_values', 'action');
    expect($actions->keys()->all())->toContain('support.access.start', 'support.database.query')
        ->and($actions['support.database.query']['reason'])->toBe('Ticket 42: orders missing')
        ->and($actions['support.database.query']['sql'])->toBe('select id from orders');
});

test('sleep and restore check act only on rows the list shows, and are logged', function () {
    config(['edge.valkey.api_url' => 'https://gw.test', 'edge.valkey.token' => 'tok', 'edge.valkey.regions' => [['key' => 'nyc3']]]);
    Queue::fake();
    $this->actingAs(User::factory()->create());

    Livewire::test(Resources::class)
        ->call('sleepResource', 'database', 'pg-shop')->assertSee('is asleep')
        ->call('verifyBackup', 'pg-shop')->assertSee('Restore check queued')
        ->call('sleepResource', 'database', 'pg-nobody')->assertNotFound();

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/tenants/pg-shop/sleep'));
    Queue::assertPushed(VerifyDatabaseBackupJob::class, fn ($job) => $job->remoteId === 'pg-shop' && $job->siteId === $this->site->id);
    expect(AuditLog::query()->where('organization_id', $this->org->id)->pluck('action')->all())->toContain('support.resource.sleep', 'support.database.verify');
});
