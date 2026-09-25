<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeAppDatabaseTest;

use App\Models\EdgePostgresUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\EdgeAppDatabaseCost;
use App\Modules\Edge\Jobs\FinishEdgeMysqlDatabaseJob;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Services\EdgePostgresUsageCollector;
use App\Modules\Providers\Neon\NeonClient;
use App\Modules\Providers\PlanetScale\PlanetScaleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function databaseSite(): Site
{
    $org = Organization::factory()->create();

    return Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'slug' => 'bookstack',
        'meta' => ['edge' => ['runtime_mode' => 'container', 'database' => ['engine' => 'sql', 'name' => 'production']]],
    ]);
}

function payFor(Site $site): void
{
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    Subscription::factory()->withPrice('price_tier_pro')->active()->create([
        'organization_id' => $site->organization_id,
    ]);
}

test('neon create reads the connection and delete ignores a missing project', function () {
    config(['edge.neon.api_key' => 'neon-key', 'edge.neon.region' => 'aws-us-east-2']);
    Http::fake([
        'https://console.neon.tech/api/v2/projects' => Http::response([
            'project' => ['id' => 'proj-1'],
            'connection_uris' => [[
                'connection_parameters' => [
                    'host' => 'ep.example.neon.tech',
                    'database' => 'neondb',
                    'role' => 'owner',
                    'password' => 's3cret',
                ],
            ]],
        ]),
        'https://console.neon.tech/api/v2/projects/missing' => Http::response([], 404),
    ]);

    $created = NeonClient::fromConfig()->create('book');
    NeonClient::fromConfig()->delete('missing');

    expect($created['id'])->toBe('proj-1')
        ->and($created['host'])->toBe('ep.example.neon.tech')
        ->and($created['port'])->toBe('5432')
        ->and($created['password'])->toBe('s3cret');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['project']['region_id'] === 'aws-us-east-2'
        && $request['project']['default_endpoint_settings']['autoscaling_limit_min_cu'] === 0.25
        && $request['project']['default_endpoint_settings']['suspend_timeout_seconds'] === 300);
});

test('planetscale waits until the cluster is ready then returns a password', function () {
    config([
        'edge.planetscale.organization' => 'acme',
        'edge.planetscale.token_id' => 'id',
        'edge.planetscale.token' => 'token',
        'edge.planetscale.cluster_size' => 'PS-10',
        'edge.planetscale.region' => '',
    ]);
    Http::fake([
        'https://api.planetscale.com/v1/organizations/acme/databases' => Http::response([
            'name' => 'book-mysql',
            'ready' => false,
            'state' => 'pending',
        ]),
        'https://api.planetscale.com/v1/organizations/acme/databases/book-mysql' => Http::response([
            'name' => 'book-mysql',
            'state' => 'ready',
            'default_branch' => 'main',
        ]),
        'https://api.planetscale.com/v1/organizations/acme/databases/book-mysql/branches/main/passwords' => Http::response([
            'username' => 'admin',
            'plain_text' => 'pw',
            'access_host_url' => 'https://aws.connect.psdb.cloud',
        ]),
    ]);

    $client = PlanetScaleClient::fromConfig();
    $created = $client->create('book-mysql');
    expect($created['ready'])->toBeFalse();

    $ready = $client->database('book-mysql');
    $password = $client->createPassword($ready['name'], $ready['branch']);

    expect($password['host'])->toBe('aws.connect.psdb.cloud')
        ->and($password['port'])->toBe('3306')
        ->and($password['database'])->toBe('book-mysql');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases')
        && $request['cluster_size'] === 'PS_10'
        && $request['foreign_keys_enabled'] === true
        && $request['kind'] === 'mysql');
});

test('an unpaid app is not given a database', function () {
    config(['edge.neon.api_key' => 'neon-key']);
    Http::fake();
    $site = databaseSite();

    $error = EdgeAppDatabase::sync($site, 'sql', 'postgres');

    expect($error)->toBe('Add a card before starting a database. It is billed to that card.');
    Http::assertNothingSent();
});

test('postgres stores the address and a tls url', function () {
    config(['edge.neon.api_key' => 'neon-key', 'edge.neon.region' => 'aws-us-east-2']);
    Http::fake([
        'https://console.neon.tech/api/v2/projects' => Http::response([
            'project' => ['id' => 'proj-1'],
            'connection_uris' => [[
                'connection_parameters' => [
                    'host' => 'ep.example.neon.tech',
                    'database' => 'neondb',
                    'role' => 'owner',
                    'password' => 'p/a+ss',
                ],
            ]],
        ]),
    ]);
    $site = databaseSite();
    payFor($site);

    expect(EdgeAppDatabase::sync($site, 'sql', 'postgres'))->toBeNull();
    $site->save();
    $site->refresh();
    $database = $site->edgeMeta()['database'];
    $url = EdgeSiteEnvVar::query()->where('site_id', $site->id)->where('key', 'DATABASE_URL')->firstOrFail()->value;
    expect($database['engine'])->toBe('postgres')
        ->and($database['remote_id'])->toBe('proj-1')
        ->and($database['host'])->toBe('ep.example.neon.tech')
        ->and($url)->toBe('postgresql://owner:p%2Fa%2Bss@ep.example.neon.tech:5432/neondb?sslmode=require')
        ->and($database['plan'])->toBe('sleep')
        ->and($database['size'])->toBe('0.25')
        ->and($database['region'])->toBe('aws-us-east-2');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['project']['default_endpoint_settings']['autoscaling_limit_max_cu'] === 0.25
        && $request['project']['default_endpoint_settings']['suspend_timeout_seconds'] === 300);
});

test('postgres sends the plan and size that were picked', function () {
    config(['edge.neon.api_key' => 'neon-key']);
    Http::fake([
        'https://console.neon.tech/api/v2/projects' => Http::response([
            'project' => ['id' => 'proj-1'],
            'endpoints' => [['id' => 'ep-1']],
            'connection_uris' => [[
                'connection_parameters' => [
                    'host' => 'ep.example.neon.tech',
                    'database' => 'neondb',
                    'role' => 'owner',
                    'password' => 'secret',
                ],
            ]],
        ]),
        'https://console.neon.tech/api/v2/projects/proj-1/endpoints/ep-1' => Http::response(['endpoint' => ['id' => 'ep-1']]),
    ]);
    $site = databaseSite();
    payFor($site);

    expect(EdgeAppDatabase::sync($site, 'sql', 'postgres', '', 'awake', '1'))->toBeNull();
    $site->save();
    $site->refresh();
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['project']['default_endpoint_settings']['autoscaling_limit_min_cu'] === 1.0
        && $request['project']['default_endpoint_settings']['autoscaling_limit_max_cu'] === 1.0
        && $request['project']['default_endpoint_settings']['suspend_timeout_seconds'] === -1);

    expect(EdgeAppDatabase::sync($site, 'postgres', 'postgres', '', 'sleep', '2'))->toBeNull();
    $site->save();
    $site->refresh();
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/endpoints/ep-1')
        && $request['endpoint']['autoscaling_limit_min_cu'] === 0.25
        && $request['endpoint']['autoscaling_limit_max_cu'] === 2.0
        && $request['endpoint']['suspend_timeout_seconds'] === 300);
    expect($site->edgeMeta()['database']['plan'])->toBe('sleep')
        ->and($site->edgeMeta()['database']['size'])->toBe('2');
});

test('postgres is created in the location that was picked and cannot move later', function () {
    config(['edge.neon.api_key' => 'neon-key', 'edge.neon.region' => 'aws-us-east-1']);
    Http::fake([
        'https://console.neon.tech/api/v2/projects' => Http::response([
            'project' => ['id' => 'proj-1'],
            'endpoints' => [['id' => 'ep-1']],
            'connection_uris' => [[
                'connection_parameters' => [
                    'host' => 'ep.example.neon.tech',
                    'database' => 'neondb',
                    'role' => 'owner',
                    'password' => 'secret',
                ],
            ]],
        ]),
    ]);
    $site = databaseSite();
    payFor($site);

    expect(EdgeAppDatabase::sync($site, 'sql', 'postgres', '', 'sleep', '0.25', 'aws-eu-central-1'))->toBeNull();
    $site->save();
    $site->refresh();
    expect($site->edgeMeta()['database']['region'])->toBe('aws-eu-central-1');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['project']['region_id'] === 'aws-eu-central-1');

    expect(EdgeAppDatabase::sync($site, 'postgres', 'postgres', '', 'sleep', '0.25', 'aws-eu-west-2'))
        ->toBe('The database stays where it was created. Remove it and add it again to use another location.');
    expect($site->fresh()->edgeMeta()['database']['region'])->toBe('aws-eu-central-1');
});

test('postgres sleep delay and restore window are sent on create and update', function () {
    config(['edge.neon.api_key' => 'neon-key']);
    Http::fake([
        'https://console.neon.tech/api/v2/projects' => Http::response([
            'project' => ['id' => 'proj-1'],
            'endpoints' => [['id' => 'ep-1']],
            'connection_uris' => [[
                'connection_parameters' => [
                    'host' => 'ep.example.neon.tech',
                    'database' => 'neondb',
                    'role' => 'owner',
                    'password' => 'secret',
                ],
            ]],
        ]),
        'https://console.neon.tech/api/v2/projects/proj-1/endpoints/ep-1' => Http::response(['endpoint' => ['id' => 'ep-1']]),
        'https://console.neon.tech/api/v2/projects/proj-1' => Http::response(['project' => ['id' => 'proj-1']]),
    ]);
    $site = databaseSite();
    payFor($site);

    expect(EdgeAppDatabase::sync($site, 'sql', 'postgres', '', 'sleep', '0.25', 'aws-us-east-1', 60, 604800))->toBeNull();
    $site->save();
    $site->refresh();
    expect($site->edgeMeta()['database']['suspend'])->toBe(60)
        ->and($site->edgeMeta()['database']['history'])->toBe(604800)
        ->and($site->edgeMeta()['database']['plan'])->toBe('sleep');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && $request['project']['history_retention_seconds'] === 604800
        && $request['project']['default_endpoint_settings']['suspend_timeout_seconds'] === 60);

    expect(EdgeAppDatabase::sync($site, 'postgres', 'postgres', '', 'sleep', '0.25', 'aws-us-east-1', -1, 86400))->toBeNull();
    $site->save();
    $site->refresh();
    expect($site->edgeMeta()['database']['suspend'])->toBe(-1)
        ->and($site->edgeMeta()['database']['plan'])->toBe('awake')
        ->and($site->edgeMeta()['database']['history'])->toBe(86400);
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/endpoints/ep-1')
        && $request['endpoint']['suspend_timeout_seconds'] === -1);
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/projects/proj-1')
        && $request['project']['history_retention_seconds'] === 86400);
});

test('mysql that is not ready is finished by the job', function () {
    config([
        'edge.planetscale.organization' => 'acme',
        'edge.planetscale.token_id' => 'id',
        'edge.planetscale.token' => 'token',
        'edge.planetscale.cluster_size' => 'PS-10',
    ]);
    Http::fake([
        'https://api.planetscale.com/v1/organizations/acme/databases' => Http::response([
            'name' => 'book-mysql',
            'ready' => false,
        ]),
        'https://api.planetscale.com/v1/organizations/acme/databases/book-mysql' => Http::sequence()
            ->push(['name' => 'book-mysql', 'ready' => false])
            ->push(['name' => 'book-mysql', 'state' => 'ready', 'default_branch' => 'main']),
        'https://api.planetscale.com/v1/organizations/acme/databases/book-mysql/branches/main/passwords' => Http::response([
            'username' => 'admin',
            'plain_text' => 'pw',
            'access_host_url' => 'aws.connect.psdb.cloud',
        ]),
    ]);
    Queue::fake();
    $site = databaseSite();
    payFor($site);

    expect(EdgeAppDatabase::sync($site, 'sql', 'mysql'))->toBeNull();
    $site->save();
    $site->refresh();
    expect($site->edgeMeta()['database']['status'])->toBe('provisioning');
    Queue::assertPushed(FinishEdgeMysqlDatabaseJob::class);

    $job = new FinishEdgeMysqlDatabaseJob((string) $site->id);
    $job->handle();
    expect($site->fresh()->edgeMeta()['database']['status'])->toBe('provisioning');

    $job->handle();
    $fresh = $site->fresh();
    expect($fresh->edgeMeta()['database']['status'])->toBe('ready')
        ->and($fresh->edgeMeta()['database']['host'])->toBe('aws.connect.psdb.cloud')
        ->and(EdgeSiteEnvVar::query()->where('site_id', $site->id)->where('key', 'DB_CONNECTION')->firstOrFail()->value)->toBe('mysql');
});

test('planetscale uses the token already configured and the only organization', function () {
    config([
        'edge.planetscale.organization' => '',
        'edge.planetscale.token_id' => '',
        'edge.planetscale.token' => '',
        'edge.planetscale.id' => 'id',
        'edge.planetscale.secret' => 'secret',
        'edge.planetscale.cluster_size' => 'PS_10',
    ]);
    Http::fake([
        'https://api.planetscale.com/v1/organizations' => Http::response([
            ['name' => 'acme'],
        ]),
        'https://api.planetscale.com/v1/organizations/acme/databases' => Http::response([
            'name' => 'book-mysql',
            'ready' => true,
            'default_branch' => 'main',
        ]),
    ]);

    $created = PlanetScaleClient::fromConfig()->create('book-mysql');

    expect($created['name'])->toBe('book-mysql')->and($created['ready'])->toBeTrue();
});

test('a mysql cluster is a monthly charge and postgres is not', function () {
    config(['edge.planetscale.monthly_cents' => 3000]);
    $mysql = databaseSite();
    $mysql->mergeEdgeMeta(['database' => ['engine' => 'mysql', 'remote_id' => 'book-mysql', 'status' => 'ready']]);
    $mysql->save();
    $postgres = databaseSite();
    $postgres->mergeEdgeMeta(['database' => ['engine' => 'postgres', 'remote_id' => 'proj', 'status' => 'ready']]);
    $postgres->save();

    expect(app(EdgeAppDatabaseCost::class)->forOrganization($mysql->organization)['cents'])->toBe(3900)
        ->and(app(EdgeAppDatabaseCost::class)->forOrganization($postgres->organization)['cents'])->toBe(0);

    $larger = databaseSite();
    $larger->mergeEdgeMeta(['database' => ['engine' => 'mysql', 'remote_id' => 'book-mysql', 'status' => 'ready', 'size' => 'PS_20']]);
    $larger->save();

    expect(app(EdgeAppDatabaseCost::class)->forOrganization($larger->organization)['cents'])->toBe(5900);
});

test('mysql create and resize use the size the operator picked', function () {
    config([
        'edge.planetscale.organization' => 'acme',
        'edge.planetscale.token_id' => 'id',
        'edge.planetscale.token' => 'token',
        'edge.planetscale.cluster_size' => 'PS_10',
    ]);
    Http::fake([
        'https://api.planetscale.com/v1/organizations/acme/databases' => Http::response([
            'name' => 'book-mysql',
            'ready' => true,
            'default_branch' => 'main',
        ]),
        'https://api.planetscale.com/v1/organizations/acme/databases/book-mysql' => Http::response([
            'name' => 'book-mysql',
            'ready' => true,
            'default_branch' => 'main',
        ]),
        'https://api.planetscale.com/v1/organizations/acme/databases/book-mysql/branches/main' => Http::response([
            'name' => 'main',
            'cluster_size' => 'PS_20',
        ]),
        'https://api.planetscale.com/v1/organizations/acme/databases/book-mysql/branches/main/passwords' => Http::response([
            'username' => 'admin',
            'plain_text' => 'pw',
            'access_host_url' => 'aws.connect.psdb.cloud',
        ]),
    ]);
    $site = databaseSite();
    payFor($site);

    expect(EdgeAppDatabase::sync($site, 'sql', 'mysql', 'PS_20'))->toBeNull();
    $site->save();

    expect($site->fresh()->edgeMeta()['database']['size'])->toBe('PS_20');
    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), '/databases')
        && $request['cluster_size'] === 'PS_20');

    expect(EdgeAppDatabase::sync($site->fresh(), 'mysql', 'mysql', 'PS_40'))->toBeNull();
    Http::assertSent(fn ($request): bool => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/branches/main')
        && $request['cluster_size'] === 'PS_40');
});

test('postgres usage is compute hours plus storage', function () {
    config(['dply.edge.usage_billing.markup_percent' => 0]);
    $site = databaseSite();
    $site->mergeEdgeMeta(['database' => ['engine' => 'postgres', 'remote_id' => 'proj-1', 'status' => 'ready']]);
    $site->save();
    EdgePostgresUsage::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'project_id' => 'proj-1',
        'date' => now()->startOfMonth()->toDateString(),
        'compute_unit_seconds' => 3600,
        'storage_byte_hours' => 1024 ** 3 * now()->daysInMonth * 24,
    ]);

    $cost = app(EdgeAppDatabaseCost::class)->forOrganization($site->organization, now()->startOfMonth(), now()->endOfMonth());

    expect($cost['postgres'])->toBe(1)
        ->and($cost['cents'])->toBe(11 + 35);

    $stored = app(EdgeAppDatabaseCost::class)->stored($site);
    expect($stored['recorded'])->toBeTrue()
        ->and($stored['gigabytes'])->toBe(number_format(now()->daysInMonth, 2));
    expect(app(EdgeAppDatabaseCost::class)->presentation()['history'])->toBe('0.20');
});

test('postgres consumption is stored for the app', function () {
    config(['edge.neon.api_key' => 'neon-key', 'edge.neon.organization' => 'org-1']);
    $day = now()->utc()->startOfDay();
    Http::fake([
        'https://console.neon.tech/api/v2/consumption_history/v2/projects*' => Http::response([
            'projects' => [[
                'project_id' => 'proj-1',
                'periods' => [[
                    'consumption' => [[
                        'timeframe_start' => $day->toDateString().'T00:00:00Z',
                        'metrics' => [
                            ['metric_name' => 'compute_unit_seconds', 'value' => 84],
                            ['metric_name' => 'root_branch_bytes_month', 'value' => 1000],
                        ],
                    ]],
                ]],
            ]],
        ]),
    ]);
    $site = databaseSite();
    $site->mergeEdgeMeta(['database' => ['engine' => 'postgres', 'remote_id' => 'proj-1', 'status' => 'ready']]);
    $site->save();

    $result = app(EdgePostgresUsageCollector::class)->collectForDate($day);

    expect($result['sites'])->toBe(1);
    $row = EdgePostgresUsage::query()->where('project_id', 'proj-1')->first();
    expect($row)->not->toBeNull()
        ->and($row->compute_unit_seconds)->toBe(84)
        ->and($row->storage_byte_hours)->toBe(1000);
});
