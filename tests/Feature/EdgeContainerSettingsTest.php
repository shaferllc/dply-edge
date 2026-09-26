<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerSettingsTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Container;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Livewire\Sites\Edge\Workspace\Security;
use App\Models\EdgeKvUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Billing\Services\EdgeDeliveryCost;
use App\Modules\Billing\Services\EdgeKvCost;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeKvUsageCollector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeContainerSettings;
use App\Support\SiteSettingsSidebar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function containerSite(string $runtime = 'container'): array
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

    return [$user, $server, $site];
}

test('the container tab only appears for container sites', function () {
    [, $server, $container] = containerSite();
    [, $staticServer, $static] = containerSite('static');

    $ids = fn (Site $site, Server $server) => collect(SiteSettingsSidebar::items($site, $server))->pluck('id')->all();

    expect($ids($container, $server))->toContain('container')
        ->and($ids($static, $staticServer))->not->toContain('container');
});

test('saved settings reach the generated wrangler config and worker', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->set('instance_type', 'standard-2')
        ->set('max_instances', 8)
        ->set('min_instances', 2)
        ->set('sleep_after', '30m')
        ->set('jurisdiction', 'eu')
        ->set('regions', ['WEUR', 'ENAM'])
        ->call('save')
        ->assertHasNoErrors();

    $dir = sys_get_temp_dir().'/dply-container-settings-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site->fresh(), '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    expect($config['containers'][0])->toMatchArray(['instance_type' => 'standard-2', 'max_instances' => 8, 'constraints' => ['regions' => ['WEUR'], 'jurisdiction' => 'eu']])
        ->and($worker)->toContain('sleepAfter = "30m"')
        ->and($worker)->toContain('const INSTANCES = 8')
        ->and($worker)->toContain('const MIN_INSTANCES = 2')
        ->and($worker)->not->toContain('getRandom');
});

test('a custom size is written as vcpu memory and disk', function () {
    [$user, $server, $site] = containerSite();
    $site->mergeEdgeMeta(['container' => [
        'instance_type' => 'custom',
        'custom_vcpu' => 2,
        'custom_memory_gib' => 6,
        'custom_disk_gb' => 12,
    ]]);
    $site->save();

    $dir = sys_get_temp_dir().'/dply-container-custom-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site->fresh(), '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    File::deleteDirectory($dir);

    expect($config['containers'][0]['instance_type'])->toBe([
        'vcpu' => 2,
        'memory_mib' => 6144,
        'disk_mb' => 12000,
    ]);
});

test('a custom rollout is written into wrangler and the deploy flag', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->set('rollout_mode', 'immediate')
        ->set('rollout_steps', '10, 100')
        ->set('rollout_active_grace_period', 300)
        ->call('save')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    $dir = sys_get_temp_dir().'/dply-container-rollout-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $fresh, '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    File::deleteDirectory($dir);

    expect($config['containers'][0])->toMatchArray([
        'rollout_step_percentage' => [10, 100],
        'rollout_active_grace_period' => 300,
    ])->and(EdgeContainerSettings::for($fresh)['rollout_mode'])->toBe('immediate');
});

test('a connection is written as a binding and a private host', function () {
    [, , $site] = containerSite();
    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'key_value',
        'name' => 'STORE',
        'host' => 'store.internal',
        'target' => 'ns-1',
    ]]]);
    $site->save();

    $dir = sys_get_temp_dir().'/dply-container-conn-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site->fresh(), '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    expect($config['kv_namespaces'])->toBe([['binding' => 'STORE', 'id' => 'ns-1']])
        ->and($worker)->toContain('store.internal')
        ->and($worker)->toContain('binding.list')
        ->and($worker)->toContain('Name a key.');
});

test('a client certificate is created and presented on the app https', function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'token',
    ]);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/mtls_certificates' => Http::response([
            'success' => true,
            'result' => ['id' => 'cert-1'],
        ]),
    ]);
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Security::class, ['server' => $server, 'site' => $site])
        ->call('enableOutboundCertificate')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    $rows = EdgeContainerConnections::clientCertificateId($fresh);
    $dir = sys_get_temp_dir().'/dply-container-cert-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $fresh, '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    expect($rows)->toBe('cert-1')
        ->and($config['mtls_certificates'])->toBe([['binding' => 'CLIENT_CERT', 'certificate_id' => 'cert-1']])
        ->and($worker)->toContain('interceptHttps')
        ->and($worker)->toContain('env[CLIENT_CERT].fetch(request)');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_contains((string) $request['certificates'], 'BEGIN CERTIFICATE')
        && $request['ca'] === false);
});

test('static files in public are served automatically', function () {
    [, , $site] = containerSite();
    $checkout = sys_get_temp_dir().'/dply-public-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($checkout.'/public/build');
    File::put($checkout.'/public/build/app.css', 'body{}');
    File::put($checkout.'/public/index.php', '<?php');

    $dir = sys_get_temp_dir().'/dply-container-assets-'.bin2hex(random_bytes(4));
    $deployer = new EdgeContainerDeployer;
    $deployer->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    expect($deployer->attachStaticAssets($dir, $checkout, $site))->toBeTrue();

    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $copied = File::get($dir.'/public/build/app.css');
    $skippedPhp = File::exists($dir.'/public/index.php');
    File::deleteDirectory($dir);
    File::deleteDirectory($checkout);

    expect($config['assets'])->toMatchArray(['directory' => './public', 'binding' => 'ASSETS', 'run_worker_first' => true])
        ->and($copied)->toBe('body{}')
        ->and($skippedPhp)->toBeFalse();
});

test('invalid sizes are rejected', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->set('instance_type', 'huge')
        ->set('max_instances', 99)
        ->call('save')
        ->assertHasErrors(['instance_type', 'max_instances']);
});

test('logs load from workers observability for the container script', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    [$user, $server, $site] = containerSite();
    Http::fake(['api.cloudflare.com/client/v4/accounts/acct/containers/applications' => Http::response(['success' => true, 'result' => []]), 'api.cloudflare.com/client/v4/accounts/acct/workers/observability/telemetry/query' => Http::response(['success' => true, 'result' => ['events' => ['events' => [
        ['timestamp' => 1_757_000_000_000, '$metadata' => ['message' => 'Laravel booted', 'level' => 'info', 'service' => 'dply-ctr-x']],
        ['timestamp' => 1_757_000_001_000, '$metadata' => ['message' => 'SQLSTATE connection refused', 'level' => 'error']],
    ]]]])]);

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->call('loadLogs')
        ->assertSet('logsError', null)
        ->assertSee('Laravel booted')
        ->assertSee('SQLSTATE connection refused');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/telemetry/query') && $request['parameters']['filters'][0]['value'] === 'dply-ctr-'.strtolower((string) $site->id));
});

test('state is one durable object the app calls by host', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->assertSee('State')
        ->assertSee('Redis')
        ->set('connectionKind', 'durable_object')
        ->set('connectionLabel', 'Visits')
        ->call('saveConnection')
        ->assertHasNoErrors();

    $site->refresh();
    $dir = sys_get_temp_dir().'/dply-container-state-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    $host = EdgeContainerConnections::resourceHost($site, 'visits');
    expect(EdgeContainerConnections::for($site)[0])->toMatchArray([
        'kind' => 'durable_object',
        'name' => 'VISITS',
        'host' => $host,
        'target' => '',
    ])->and($config['durable_objects']['bindings'])->toContain([
        'name' => 'VISITS',
        'class_name' => 'EdgeState',
    ])->and($config['migrations'])->toContain([
        'tag' => 'v2',
        'new_sqlite_classes' => ['EdgeState'],
    ])->and($worker)->toContain($host)
        ->and($worker)->toContain('idFromName')
        ->and($worker)->toContain('incr/');
});

test('redis stores an encrypted address and does not ride the worker', function () {
    [$user, $server, $site] = containerSite();
    $url = 'rediss://default:s3cret@cache.example:6379';

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->set('connectionKind', 'redis')
        ->set('connectionMode', 'attach')
        ->set('connectionLabel', 'Cache')
        ->set('connectionPick', $url)
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->assertDontSee('s3cret');

    $site->refresh();
    $env = $site->edgeEnvVars()->where('key', 'REDIS_URL')->first();
    $dir = sys_get_temp_dir().'/dply-container-redis-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site, '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    File::deleteDirectory($dir);

    expect($env->value)->toBe($url)
        ->and($env->getRawOriginal('value_encrypted'))->not->toBe($url)
        ->and(json_encode($config))->not->toContain('s3cret')
        ->and(EdgeContainerConnections::redisDriverEnv($site))->toMatchArray([
            'REDIS_URL' => $url,
            'REDIS_USERNAME' => 'default',
            'REDIS_PASSWORD' => 's3cret',
            'REDIS_HOST' => 'cache.example',
            'REDIS_PORT' => '6379',
        ])
        ->and(json_encode(EdgeContainerConnections::redisInjectionPreview($site)))->not->toContain('s3cret')
        ->and(json_encode($site->edgeMeta()))->not->toContain('s3cret');

    $host = collect(EdgeContainerConnections::for($site))->firstWhere('kind', 'redis')['host'];
    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('sleepConnection', $host, true);

    $site->refresh();
    $asleep = collect(EdgeContainerConnections::for($site))->firstWhere('kind', 'redis');

    expect($asleep)->not->toBeNull()
        ->and($asleep['asleep'])->toBeTrue()
        ->and($site->edgeEnvVars()->where('key', 'REDIS_URL')->first()->value)->toBe($url)
        ->and(EdgeContainerConnections::redisDriverEnv($site))->toBe([])
        ->and(EdgeContainerConnections::omitAsleepRedis($site, ['REDIS_URL' => $url, 'APP_NAME' => 'book']))->toBe(['APP_NAME' => 'book']);
});

test('starting redis requires a card', function () {
    config(['edge.valkey.api_url' => 'http://gateway.test', 'edge.valkey.token' => 'tok']);
    Http::fake();
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->set('connectionKind', 'redis')
        ->set('connectionMode', 'create')
        ->set('connectionLabel', 'Cache')
        ->call('saveConnection')
        ->assertHasErrors('connection')
        ->assertSee('Add a card before starting dply Valkey');

    Http::assertNothingSent();

    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'redis',
        'name' => 'CACHE',
        'host' => 'cache.internal',
        'target' => 'valkey:app-cache',
    ]]]);
    $site->save();
    (new EdgeSiteEnvVar([
        'site_id' => $site->id,
        'key' => 'REDIS_URL',
        'value' => 'rediss://default:s3cret@app-cache.cache.dply.test:6380',
        'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
        'created_by_user_id' => $user->id,
    ]))->save();

    // dply Valkey is on every plan: a Flex size is wired in on Free.
    expect(EdgeContainerConnections::redisDriverEnv($site->fresh()))->toHaveKey('REDIS_HOST', 'app-cache.cache.dply.test');

    // Only the Pro sizes need a paid plan.
    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'redis',
        'name' => 'CACHE',
        'host' => 'cache.internal',
        'target' => 'valkey:app-cache',
        'plan' => 'pro_5g',
    ]]]);
    $site->save();
    expect(EdgeContainerConnections::redisDriverEnv($site->fresh()))->toBe([])
        ->and(EdgeContainerConnections::omitAsleepRedis($site->fresh(), ['REDIS_URL' => 'rediss://x', 'APP_NAME' => 'book']))->toBe(['APP_NAME' => 'book']);
});

test('key value requires a card and bills reads writes and storage', function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'token',
        'dply.edge.usage_billing.kv_reads_millicents_per_million' => 100_000,
        'dply.edge.usage_billing.kv_writes_millicents_per_million' => 1_000_000,
        'dply.edge.usage_billing.kv_storage_millicents_per_gb_month' => 100_000,
    ]);
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->set('connectionKind', 'key_value')
        ->set('connectionMode', 'create')
        ->set('connectionLabel', 'Flags')
        ->call('saveConnection')
        ->assertHasErrors('connection')
        ->assertSee('Add a card before starting a key-value store');

    Http::assertNothingSent();

    $cost = app(EdgeKvCost::class);
    expect($cost->cents(1_000_000, 0, 0, 0, 0))->toBe(100)
        ->and($cost->cents(0, 1_000_000, 0, 0, 0))->toBe(1000)
        ->and($cost->cents(0, 0, 0, 0, 2 * 1024 ** 3))->toBe(100)
        ->and($cost->cents(0, 0, 0, 0, 1024 ** 3))->toBe(0);

    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'key_value',
        'name' => 'FLAGS',
        'host' => 'flags.internal',
        'target' => 'ns-1',
    ]]]);
    $site->save();

    Http::fake([
        'api.cloudflare.com/client/v4/graphql' => Http::response([
            'data' => ['viewer' => ['accounts' => [[
                'kvOperationsAdaptiveGroups' => [[
                    'dimensions' => ['namespaceId' => 'ns-1', 'actionType' => 'read'],
                    'sum' => ['requests' => 1_000_000],
                ]],
                'kvStorageAdaptiveGroups' => [[
                    'dimensions' => ['namespaceId' => 'ns-1'],
                    'max' => ['byteCount' => 2 * 1024 ** 3],
                ]],
            ]]]],
        ]),
    ]);

    expect(app(EdgeKvUsageCollector::class)->collectForDate(now())['sites'])->toBe(1)
        ->and((int) EdgeKvUsage::query()->where('namespace_id', 'ns-1')->value('reads'))->toBe(1_000_000)
        ->and($cost->forOrganization($site->organization, now()->startOfMonth(), now()->endOfMonth())['cents'])->toBe(200);
});

test('key value settings show how it works and rename the store', function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'token',
        'dply.edge.usage_billing.kv_reads_millicents_per_million' => 100_000,
        'dply.edge.usage_billing.kv_writes_millicents_per_million' => 1_000_000,
        'dply.edge.usage_billing.kv_storage_millicents_per_gb_month' => 100_000,
    ]);
    Http::fake(function ($request) {
        if ($request->method() === 'PUT') {
            return Http::response(['success' => true, 'result' => []]);
        }

        return Http::response(['success' => true, 'result' => [['name' => 'session']]]);
    });
    [$user, $server, $site] = containerSite();
    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'key_value',
        'name' => 'FLAGS',
        'host' => 'flags.internal',
        'target' => 'ns-1',
    ]]]);
    $site->save();
    EdgeKvUsage::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'namespace_id' => 'ns-1',
        'date' => now()->toDateString(),
        'reads' => 1_000_000,
        'writes' => 0,
        'deletes' => 0,
        'lists' => 0,
        'storage_bytes' => 0,
    ]);

    $host = EdgeContainerConnections::resourceHost($site, 'flags');

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site->fresh()])
        ->call('openKv', $host)
        ->assertSee('GET http://'.$host.'/ lists up to 100 keys.')
        ->assertSee('Reads are $1 per million')
        ->assertSee('Implementation')
        ->assertSee('The next deploy adds dply/laravel')
        ->assertSee('dply-rails')
        ->assertSee("Cache::store('flags')")
        ->assertSee('Rails.cache.write')
        ->assertSee('session')
        ->assertSee('1,000,000')
        ->assertSee('Cost estimate · $1.00')
        ->assertSee('$1.00')
        ->set('kvName', 'Notes')
        ->call('saveKvSettings')
        ->assertHasNoErrors();

    $fresh = $site->fresh();
    expect(collect(EdgeContainerConnections::for($fresh))->firstWhere('kind', 'key_value')['host'])->toBe(EdgeContainerConnections::resourceHost($fresh, 'notes'))
        ->and(EdgeContainerConnections::kvDriverEnv($fresh)['DPLY_KV_HOST'])->toBe(EdgeContainerConnections::resourceHost($fresh, 'notes'))
        ->and(EdgeContainerConnections::kvDriverEnv($fresh)['DPLY_KV_STORE'])->toBe('notes');
});

test('an asleep key value store drops its env and is not billed', function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'token',
        'dply.edge.usage_billing.kv_reads_millicents_per_million' => 100_000,
    ]);
    Http::fake(['*' => Http::response(['success' => true, 'result' => []])]);
    [$user, $server, $site] = containerSite();
    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'key_value',
        'name' => 'FLAGS',
        'host' => 'flags.internal',
        'target' => 'ns-sleep',
        'asleep' => true,
    ]]]);
    $site->save();
    EdgeKvUsage::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'namespace_id' => 'ns-sleep',
        'date' => now()->toDateString(),
        'reads' => 1_000_000,
        'writes' => 0,
        'deletes' => 0,
        'lists' => 0,
        'storage_bytes' => 2 * 1024 ** 3,
    ]);

    $fresh = $site->fresh();
    expect(EdgeContainerConnections::kvDriverEnv($fresh))->toBe([])
        ->and(app(EdgeKvCost::class)->forOrganization($fresh->organization, now()->startOfMonth(), now()->endOfMonth())['cents'])->toBe(0);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $fresh])
        ->call('openKv', EdgeContainerConnections::resourceHost($fresh, 'flags'))
        ->call('runKvDemo', 'write')
        ->assertSee('This store is asleep')
        ->assertSee('Cost estimate · $0.00');

    Http::assertNotSent(fn ($request): bool => $request->method() === 'PUT');
});

test('http delivery requires a card and bills messages', function () {
    config([
        'edge.upstash.email' => 'ops@example.com',
        'edge.upstash.api_key' => 'secret-key',
        'edge.upstash.qstash_token' => 'qstash-token',
        'dply.edge.usage_billing.delivery_messages_millicents_per_100k' => 200_000,
        'dply.edge.usage_billing.delivery_bandwidth_millicents_per_gb' => 10_000,
    ]);
    Http::fake(function ($request) {
        if (str_contains($request->url(), '/qstash/users')) {
            return Http::response([['id' => 'qstash-user', 'type' => 'free', 'reserved_type' => '']]);
        }

        return Http::response('OK');
    });
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->set('connectionKind', 'http_delivery')
        ->set('connectionMode', 'create')
        ->set('connectionLabel', 'Hooks')
        ->call('saveConnection')
        ->assertHasErrors('connection')
        ->assertSee('Add a card before starting HTTP delivery');

    Http::assertNothingSent();

    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $site->organization_id]);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site->fresh()])
        ->set('connectionKind', 'http_delivery')
        ->set('connectionMode', 'create')
        ->set('connectionLabel', 'Hooks')
        ->call('saveConnection')
        ->assertHasNoErrors();

    expect(EdgeContainerConnections::for($site->fresh())[0]['kind'])->toBe('http_delivery')
        ->and(app(EdgeDeliveryCost::class)->cents(100_000, 0))->toBe(200)
        ->and(app(EdgeDeliveryCost::class)->cents(0, 2 * 1024 ** 3))->toBe(10);

    $this->post(route('hooks.edge.delivery', $site), ['messages' => 1, 'bytes' => 40], [
        'x-dply-queue-token' => EdgeContainerDeployer::queueToken($site),
    ])->assertNoContent();

    expect(app(EdgeDeliveryCost::class)->forOrganization($site->organization, now()->startOfMonth(), now()->endOfMonth())['cents'])->toBe(1);
});

test('starting redis starts a dply Valkey and stores its address', function () {
    config(['edge.valkey.api_url' => 'http://gateway.test', 'edge.valkey.token' => 'tok', 'edge.valkey.domain' => 'cache.dply.test']);
    Http::fake(['gateway.test/*' => Http::response(['id' => 'x'])]);
    [$user, $server, $site] = containerSite();
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    Subscription::factory()->withPrice('price_tier_pro')->active()->create(['organization_id' => $site->organization_id]);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('chooseConnectionKind', 'redis')
        ->set('connectionLabel', 'Cache')
        ->set('valkeyClass', 'flex_1g')
        ->set('valkeySleep', 900)
        ->call('saveConnection')
        ->assertHasNoErrors();

    $site->refresh();
    $connection = collect(EdgeContainerConnections::for($site))->firstWhere('kind', 'redis');
    $url = (string) $site->edgeEnvVars()->where('key', 'REDIS_URL')->first()->value;
    $password = rawurldecode((string) parse_url($url, PHP_URL_PASS));

    expect($connection['target'])->toStartWith('valkey:')
        ->and($connection['plan'])->toBe('flex_1g')
        ->and($url)->toStartWith('rediss://default:')->toContain('.cache.dply.test:6380')
        ->and($site->edgeEnvVars()->where('key', 'REDIS_PASSWORD')->first()->value)->toBe($password)
        ->and(json_encode($site->edgeMeta()))->not->toContain($password)
        ->and($site->edgeMeta()['valkey_sleep'][$connection['target']])->toBe(900);

    Http::assertSent(fn ($request): bool => $request->method() === 'PUT' && $request['memory_mb'] === 1024 && $request['sleep_after'] === 900);
});

test('valkey settings change the size and sleep time', function () {
    config(['edge.valkey.api_url' => 'http://gateway.test', 'edge.valkey.token' => 'tok']);
    Http::fake(['gateway.test/*' => Http::response([])]);
    [$user, $server, $site] = containerSite();
    $host = EdgeContainerConnections::resourceHost($site, 'cache');
    $site->mergeEdgeMeta(['connections' => [['kind' => 'redis', 'name' => 'CACHE', 'host' => $host, 'target' => 'valkey:app-cache', 'plan' => 'flex_250m']]]);
    $site->save();
    (new EdgeSiteEnvVar([
        'site_id' => $site->id,
        'key' => 'REDIS_URL',
        'value' => 'rediss://default:s3cret@app-cache.cache.dply.test:6380',
        'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
        'created_by_user_id' => $user->id,
    ]))->save();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('saveValkey', $host, 'pro_5g', 300)
        ->assertHasNoErrors();

    expect(collect(EdgeContainerConnections::for($site->fresh()))->firstWhere('kind', 'redis')['plan'])->toBe('pro_5g');
    Http::assertSent(fn ($request): bool => $request->url() === 'http://gateway.test/tenants/app-cache'
        && $request['password'] === 's3cret' && $request['memory_mb'] === 5120 && $request['persistent'] === true && $request['sleep_after'] === 0);
});

test('the valkey modal shows its tabs, reveals the password on request, and explains a test with no password', function () {
    config(['edge.valkey.domain' => 'cache.dply.test']);
    [$user, $server, $site] = containerSite();
    $host = EdgeContainerConnections::resourceHost($site, 'cache');
    $site->mergeEdgeMeta(['connections' => [['kind' => 'redis', 'name' => 'CACHE', 'host' => $host, 'target' => 'valkey:app-cache', 'plan' => 'flex_1g']]]);
    $site->save();

    $component = Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->set('valkeyHost', $host)
        ->assertSee('app-cache.cache.dply.test:6380')
        ->assertSee(['Overview', 'Connect', 'Test', 'Costs'])
        ->assertDontSee('s3cret');

    // No REDIS_URL yet: the test explains instead of trying to connect.
    $component->call('testValkey')->assertSet('valkeyTest.ok', false)->assertSee('No password on this app yet');

    // Step names are Redis commands, not translation keys: __('AUTH') resolves
    // lang/en/auth.php on a case-insensitive disk and broke the view.
    $component->set('valkeyTest', ['ok' => true, 'error' => null, 'ping_median_ms' => 80.5, 'ping_max_ms' => 120.0, 'steps' => [
        ['step' => 'AUTH', 'ms' => 200.1, 'result' => 'OK'],
        ['step' => 'GET', 'ms' => 81.0, 'result' => 'value'],
    ]])->assertSee(['AUTH', 'Working. Every command answered.']);

    $component->set('valkeyStatus', ['awake' => false, 'has_snapshot' => true])
        ->set('valkeyStats', ['keys' => 42, 'used_memory' => 5242880, 'max_memory' => 262144000, 'hit_rate' => 97.5, 'hits' => 390, 'misses' => 10, 'commands' => 1234, 'ops_per_sec' => 3, 'clients' => 2, 'expired_keys' => 1, 'evicted_keys' => 0, 'uptime_seconds' => 60, 'version' => '8.1.10'])
        ->assertSee(['Asleep', 'A snapshot is stored', '97.5%', '5.0 MB · 2%']);

    (new EdgeSiteEnvVar([
        'site_id' => $site->id,
        'key' => 'REDIS_URL',
        'value' => 'rediss://default:s3cret@app-cache.cache.dply.test:6380',
        'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION,
        'created_by_user_id' => $user->id,
    ]))->save();
    expect($component->instance()->valkeyPassword($host))->toBe('s3cret');
});

test('an attached queue sets the driver env and an existing value wins', function () {
    [, , $site] = containerSite();
    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'queue',
        'name' => 'JOBS',
        'host' => 'jobs.internal',
        'target' => 'jobs',
    ]]]);
    $site->save();

    $driver = EdgeContainerConnections::queueDriverEnv($site->fresh());
    $merged = array_merge($driver, ['QUEUE_CONNECTION' => 'database', 'DPLY_QUEUE' => 'OTHER']);

    expect($driver)->toBe(['DPLY_QUEUE' => 'JOBS'])
        ->and($merged['DPLY_QUEUE'])->toBe('OTHER')
        ->and($merged['QUEUE_CONNECTION'])->toBe('database');
});

test('an attached bucket sets the storage env and an existing disk wins', function () {
    [, , $site] = containerSite();
    $site->mergeEdgeMeta(['connections' => [[
        'kind' => 'object_storage',
        'name' => 'UPLOADS',
        'host' => 'uploads.internal',
        'target' => 'uploads',
    ]]]);
    $site->save();

    $driver = EdgeContainerConnections::storageDriverEnv($site->fresh());
    $merged = array_merge($driver, ['FILESYSTEM_DISK' => 'local']);

    expect($driver['DPLY_STORAGE_HOST'])->toBe('uploads.internal')
        ->and($driver['DPLY_STORAGE_DISK'])->toBe('uploads')
        ->and($driver['DPLY_STORAGE_DISKS'])->toBe('uploads=uploads.internal')
        ->and($merged['FILESYSTEM_DISK'])->toBe('local');
});

test('scaling windows and an always-on jobs instance are saved and reach the worker', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->set('max_instances', 2)
        ->set('dedicated_jobs', true)
        ->set('jobs_always_on', true)
        ->call('addSchedule')
        ->set('schedules.0.timezone', 'America/Chicago')
        ->set('schedules.0.min', 3)
        ->set('schedules.0.max', 6)
        ->call('save')
        ->assertHasNoErrors();

    $settings = EdgeContainerSettings::for($site->fresh());
    $dir = sys_get_temp_dir().'/dply-container-settings-'.bin2hex(random_bytes(4));
    (new EdgeContainerDeployer)->scaffold($dir, $site->fresh(), '/x/Dockerfile', 8080, []);
    $config = json_decode(File::get($dir.'/wrangler.jsonc'), true);
    $worker = File::get($dir.'/src/index.js');
    File::deleteDirectory($dir);

    expect($settings['schedules'])->toBe([['days' => 'weekdays', 'start' => '09:00', 'end' => '17:00', 'timezone' => 'America/Chicago', 'min' => 3, 'max' => 6]])
        ->and(EdgeContainerDeployer::keepsInstancesAwake($settings))->toBeTrue()
        // The busiest window (6) plus the jobs instance, not the default max of 2.
        ->and($config['containers'][0]['max_instances'])->toBeGreaterThanOrEqual(7)
        ->and($worker)->toContain('"timezone":"America/Chicago"')
        ->and($worker)->toContain('const JOBS_ALWAYS_ON = true');
});

test('a window that ends before it starts is rejected', function () {
    [$user, $server, $site] = containerSite();

    Livewire::actingAs($user)
        ->test(Container::class, ['server' => $server, 'site' => $site])
        ->call('addSchedule')
        ->set('schedules.0.start', '22:00')
        ->set('schedules.0.end', '06:00')
        ->call('save')
        ->assertHasErrors(['schedules.0.end']);
});

test('warm-containers knocks only on live sites that keep instances awake', function () {
    Http::fake();
    [, , $awake] = containerSite();
    $awake->mergeEdgeMeta(['live_url' => 'https://awake.example.test', 'container' => ['min_instances' => 1]]);
    $awake->save();
    [, , $asleep] = containerSite();
    $asleep->mergeEdgeMeta(['live_url' => 'https://asleep.example.test']);
    $asleep->save();

    $this->artisan('dply:edge:warm-containers')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->url() === 'https://awake.example.test/_dply/warm'
        && $request->header('x-dply-queue-token')[0] === EdgeContainerDeployer::queueToken($awake));
});

test('an app that keeps its data with dply runs next to it unless it picks a region', function () {
    config(['edge.valkey.data_region' => 'ENAM']);
    $app = fn (array $edge): Site => Site::factory()->create(['meta' => ['edge' => array_replace_recursive(['runtime_mode' => 'container'], $edge)]]);

    $postgres = $app(['database' => ['engine' => 'postgres', 'provider' => 'dply']]);
    $valkey = $app(['connections' => [['kind' => 'redis', 'name' => 'REDIS', 'host' => 'redis.internal', 'target' => 'valkey:x']]]);
    $chosen = $app(['database' => ['engine' => 'postgres', 'provider' => 'dply'], 'container' => ['regions' => ['WEUR']]]);
    $eu = $app(['database' => ['engine' => 'postgres', 'provider' => 'dply'], 'container' => ['jurisdiction' => 'eu']]);
    $sqlite = $app(['database' => ['engine' => 'sql']]);

    expect(EdgeContainerSettings::constraints($postgres))->toBe(['regions' => ['ENAM']])
        ->and(EdgeContainerSettings::constraints($valkey))->toBe(['regions' => ['ENAM']])
        ->and(EdgeContainerSettings::constraints($chosen))->toBe(['regions' => ['WEUR']])
        ->and(EdgeContainerSettings::constraints($eu))->toBe(['jurisdiction' => 'eu'])
        ->and(EdgeContainerSettings::constraints($sqlite))->toBeNull();

    config(['edge.valkey.data_region' => '']);
    expect(EdgeContainerSettings::constraints($postgres))->toBeNull();
});
