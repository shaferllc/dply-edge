<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeSqlResourceTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeDatabase;
use App\Models\EdgeDataUsage;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Livewire\Databases;
use App\Modules\Edge\Services\EdgeDataUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'dply.edge.usage_billing.markup_percent' => 0,
        'dply.edge.usage_billing.d1_rows_read_millicents_per_million' => 0,
        'dply.edge.usage_billing.d1_rows_written_millicents_per_million' => 100_000,
        'dply.edge.usage_billing.d1_storage_millicents_per_gb_month' => 0,
    ]);
});

/**
 * A container app with a D1 this organization created, bound as APP_DB.
 *
 * @return array{0: User, 1: Server, 2: Site, 3: string}
 */
function sqlApp(string $runtime = 'container', bool $owned = true): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime]],
    ]);
    if ($owned) {
        EdgeDatabase::query()->create(['organization_id' => $org->id, 'name' => 'orders', 'cloudflare_id' => 'd1-uuid']);
        EdgeContainerConnections::attach($site, 'sql', 'APP_DB', 'd1-uuid');
    } else {
        // A row from before attach() checked ownership.
        $site->mergeEdgeMeta(['connections' => [['kind' => 'sql', 'name' => 'APP_DB', 'host' => EdgeContainerConnections::resourceHost($site, 'app-db'), 'target' => 'd1-uuid']]]);
        $site->save();
    }

    return [$user, $server, $site->fresh(), EdgeContainerConnections::resourceHost($site, 'app-db')];
}

/** Cloudflare: details for d1-uuid, a users table, and one row per query. */
function fakeD1(): void
{
    Http::fake(function (Request $request) {
        $url = $request->url();
        if (str_ends_with($url, '/query')) {
            $rows = str_contains((string) $request['sql'], 'sqlite_master')
                ? [['name' => 'users']]
                : [['id' => 1, 'email' => 'ada@example.test']];

            return Http::response(['success' => true, 'result' => [['results' => $rows, 'success' => true, 'meta' => ['changes' => 0, 'duration' => 0.5]]]]);
        }
        if (str_contains($url, '/d1/database/')) {
            return Http::response(['success' => true, 'result' => ['uuid' => 'd1-uuid', 'name' => 'someone-else', 'file_size' => 2 * 1024 ** 2, 'num_tables' => 1, 'running_in_region' => 'WEUR']]);
        }

        return Http::response(['success' => true, 'result' => []]);
    });
}

test('the sheet opens, loads details and tables, and shows how to connect', function () {
    fakeD1();
    [$user, $server, $site, $host] = sqlApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->assertDispatched('open-modal', 'resources-sql')
        ->call('sqlLoad')
        ->assertSet('sqlTables', ['users'])
        ->assertSee('orders')
        ->assertSee('2.0 MB')
        ->assertSee('WEUR')
        ->assertSee('http://'.$host.'/query')
        ->assertSee("->json('results')");

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/d1/database/d1-uuid/query') && str_contains((string) $r['sql'], 'sqlite_master'));
});

test('a worker app is shown its env binding', function () {
    fakeD1();
    [$user, $server, $site, $host] = sqlApp('ssr');

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->assertSee('const { results } = await env.APP_DB');
});

test('the console runs against this connection\'s database only', function () {
    fakeD1();
    [$user, $server, $site, $host] = sqlApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->set('sqlQuery', 'SELECT * FROM users')
        ->call('sqlRun')
        ->assertSet('sqlQueryError', null)
        ->assertSee('ada@example.test');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/accounts/acct/d1/database/d1-uuid/query') && $r['sql'] === 'SELECT * FROM users');
});

test('a table opens its first rows, with the name quoted', function () {
    fakeD1();
    [$user, $server, $site, $host] = sqlApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->call('sqlOpenTable', 'users')
        ->assertSet('sqlTable', 'users')
        ->assertSee('ada@example.test')
        ->call('sqlOpenTable', 'x"; DROP TABLE users; --');

    Http::assertSent(fn (Request $r) => ($r['sql'] ?? null) === 'SELECT * FROM "users" LIMIT 50');
    Http::assertSent(fn (Request $r) => ($r['sql'] ?? null) === 'SELECT * FROM "x""; DROP TABLE users; --" LIMIT 50');
});

test('a database another organization created is never queried', function () {
    fakeD1();
    [$user, $server, $site, $host] = sqlApp(owned: false);

    EdgeDatabase::query()->create(['organization_id' => $site->organization_id, 'name' => 'owned', 'cloudflare_id' => 'owned-uuid']);
    EdgeContainerConnections::attach($site, 'sql', 'OWNED_DB', 'owned-uuid');

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site->fresh()])
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'owned-db'))
        ->call('sqlLoad')
        ->assertSet('sqlTables', ['users'])
        // Switching to the unowned one clears what the owned one showed.
        ->call('openResource', $host)
        ->call('sqlLoad')
        ->assertSet('sqlTables', null)
        ->set('sqlQuery', 'SELECT 1')
        ->call('sqlRun')
        ->assertSee('not created by this organization');

    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/d1-uuid/query'));
});

test('the card and sheet show this database\'s cost, not the organization\'s', function () {
    [$user, $server, $site, $host] = sqlApp();
    Http::fake(['api.cloudflare.com/client/v4/graphql' => Http::response(['data' => ['viewer' => ['accounts' => [[
        'd1AnalyticsAdaptiveGroups' => [
            ['dimensions' => ['databaseId' => 'd1-uuid'], 'sum' => ['rowsRead' => 0, 'rowsWritten' => 2_000_000]],
            ['dimensions' => ['databaseId' => 'other-uuid'], 'sum' => ['rowsRead' => 0, 'rowsWritten' => 5_000_000]],
        ],
        'd1StorageAdaptiveGroups' => [],
        'queueMessageOperationsAdaptiveGroups' => [],
    ]]]]])]);
    EdgeDatabase::query()->create(['organization_id' => $site->organization_id, 'name' => 'other', 'cloudflare_id' => 'other-uuid']);

    app(EdgeDataUsageCollector::class)->collectForDate(now());

    $day = EdgeDataUsage::query()->where('organization_id', $site->organization_id)->sole();
    expect($day->d1_rows_written)->toBe(7_000_000)
        ->and($day->d1_by_database['d1-uuid']['rows_written'])->toBe(2_000_000);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->assertSee('Cost estimate · $2.00')
        ->assertDontSee('Cost estimate · $7.00')
        ->call('openResource', $host)
        ->assertSee('2,000,000');
});

test('renaming changes the binding and host, not the database', function () {
    fakeD1();
    [$user, $server, $site, $host] = sqlApp();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', $host)
        ->set('sqlName', 'main')
        ->call('sqlRename')
        ->assertHasNoErrors()
        ->assertSet('resourceHost', EdgeContainerConnections::resourceHost($site, 'main'));

    $connection = collect(EdgeContainerConnections::for($site->fresh()))->firstWhere('kind', 'sql');
    expect($connection['name'])->toBe('MAIN')->and($connection['target'])->toBe('d1-uuid');
});

test('deleting from the org page detaches every app still bound to it', function () {
    [$user, , $site] = sqlApp();
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);
    session(['current_organization_id' => $site->organization_id]);
    $database = EdgeDatabase::query()->where('cloudflare_id', 'd1-uuid')->sole();

    Livewire::actingAs($user)->test(Databases::class)
        ->call('delete', $database->id, 'orders')
        ->assertHasNoErrors();

    expect(EdgeContainerConnections::for($site->fresh()))->toBe([])
        ->and(EdgeDatabase::query()->count())->toBe(0);
});
