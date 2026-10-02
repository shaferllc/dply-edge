<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\ResourcesTest;

use App\Livewire\Admin\Resources;
use App\Models\DplyDatabase;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
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
