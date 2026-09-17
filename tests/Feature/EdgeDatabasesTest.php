<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDatabasesTest;

use App\Models\EdgeDatabase;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Livewire\Databases;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
    $this->org->users()->attach($this->user->id, ['role' => 'owner']);
    session(['current_organization_id' => $this->org->id]);
});

test('creating a database names it per org in cloudflare and records it', function () {
    Http::fake(['api.cloudflare.com/client/v4/accounts/acct/d1/database' => Http::response(['success' => true, 'result' => ['uuid' => 'uuid-1']])]);

    Livewire::actingAs($this->user)->test(Databases::class)
        ->set('name', 'app-db')
        ->set('location', 'weur')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSet('selected', EdgeDatabase::query()->first()->id);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['name'] === 'dply-'.strtolower((string) $this->org->id).'-app-db' && $r['primary_location_hint'] === 'weur');
    expect(EdgeDatabase::query()->first()->cloudflare_id)->toBe('uuid-1');
});

test('the free plan allows one database', function () {
    EdgeDatabase::query()->create(['organization_id' => $this->org->id, 'name' => 'first', 'cloudflare_id' => 'u1']);

    Livewire::actingAs($this->user)->test(Databases::class)
        ->set('name', 'second')
        ->call('create')
        ->assertHasErrors('name');

    expect(EdgeDatabase::query()->count())->toBe(1);
});

test('sql runs against the selected database and results render', function () {
    $db = EdgeDatabase::query()->create(['organization_id' => $this->org->id, 'name' => 'app', 'cloudflare_id' => 'u1']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/d1/database/u1/query' => Http::response(['success' => true, 'result' => [
            ['results' => [['id' => 1, 'email' => 'a@example.test']], 'success' => true, 'meta' => ['rows_read' => 1, 'rows_written' => 0, 'duration' => 0.4]],
        ]]),
        'api.cloudflare.com/client/v4/accounts/acct/d1/database/u1' => Http::response(['success' => true, 'result' => ['file_size' => 8192, 'num_tables' => 1]]),
    ]);

    Livewire::actingAs($this->user)->test(Databases::class)
        ->call('select', $db->id)
        ->set('sql', 'select * from users')
        ->call('run')
        ->assertSee('a@example.test')
        ->assertSee('1 table');
});

test('attaching adds a d1 binding override to the project', function () {
    $db = EdgeDatabase::query()->create(['organization_id' => $this->org->id, 'name' => 'app', 'cloudflare_id' => 'u1']);
    $server = Server::factory()->create(['organization_id' => $this->org->id, 'user_id' => $this->user->id]);
    $site = Site::factory()->create(['organization_id' => $this->org->id, 'server_id' => $server->id, 'user_id' => $this->user->id, 'edge_backend' => 'dply_edge']);
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);

    Livewire::actingAs($this->user)->test(Databases::class)
        ->call('select', $db->id)
        ->set('attachSite', $site->id)
        ->set('bindingName', 'APP_DB')
        ->call('attach')
        ->assertHasNoErrors();

    expect($site->fresh()->edgeMeta()['bindings_overrides'])->toBe([['name' => 'APP_DB', 'kind' => 'd1', 'value' => 'u1']]);
});

test('another organization cannot open your database', function () {
    $other = Organization::factory()->create();
    $db = EdgeDatabase::query()->create(['organization_id' => $other->id, 'name' => 'theirs', 'cloudflare_id' => 'u9']);

    expect(fn () => Livewire::actingAs($this->user)->test(Databases::class)
        ->call('select', $db->id)
        ->call('run'))->toThrow(ModelNotFoundException::class);
});
