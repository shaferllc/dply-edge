<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeDeployment;
use App\Models\EdgeRealtimeApp;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeArtifactPublisher;
use App\Modules\Edge\Services\EdgeBuildRunner;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeApps;
use App\Modules\Edge\Services\RuntimeDetection\PhpRuntimeDetector;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'edge.cloudflare.account_id' => 'acct',
        'edge.cloudflare.api_token' => 'tok',
        'edge.realtime.host' => 'realtime-apps.test',
        'edge.realtime.kv_namespace_id' => 'rt-ns',
        'edge.realtime.max_message_bytes' => 10240,
    ]);
});

/** @return array{0: Organization, 1: Site, 2: User} */
function realtimeSite(string $runtime = 'container', array $edge = [], bool $paid = true): array
{
    $org = Organization::factory()->create($paid ? ['comped_until' => now()->addYear()] : []);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'type' => SiteType::Static,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => array_replace_recursive(['runtime_mode' => $runtime], $edge)],
    ]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $site->forceFill(['user_id' => $user->id])->save();
    $site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    return [$org, $site, $user];
}

function attachRealtime(Site $site, EdgeRealtimeApp $app): void
{
    EdgeContainerConnections::attach($site, 'realtime', 'REALTIME', $app->id);
    $site->refresh();
}

function fakeKv(): void
{
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => null])]);
}

test('provision writes the contract record under id: and key:', function () {
    [$org, $site] = realtimeSite();
    fakeKv();

    $app = app(EdgeRealtimeApps::class)->provision($site, 'Chat', ['max_connections' => 500, 'allowed_origins' => ['https://example.com']]);

    expect($app->app_key)->toMatch('/^rtk_[A-Za-z0-9]{24}$/')
        ->and($app->app_secret)->toMatch('/^rts_[A-Za-z0-9]{40}$/')
        ->and($app->organization_id)->toBe($org->id)
        ->and($app->site_id)->toBe($site->id);

    $expected = '{"id":"'.$app->id.'","key":"'.$app->app_key.'","secret":"'.$app->app_secret.'","enabled":true,"maxConnections":500,"allowedOrigins":["https://example.com"],"clientEvents":false,"maxMessageBytes":10240,"hostname":"'.$app->hostname.'","shards":1}';
    foreach (['id:'.$app->id, 'key:'.$app->app_key] as $key) {
        Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT'
            && $r->url() === 'https://api.cloudflare.com/client/v4/accounts/acct/storage/kv/namespaces/rt-ns/values/'.rawurlencode($key)
            && $r->body() === $expected);
    }
    // The secret is encrypted at rest.
    expect(DB::table('edge_realtime_apps')->value('app_secret'))->not->toBe($app->app_secret);
});

test('an empty origin list is written as a JSON array', function () {
    [, $site] = realtimeSite();
    fakeKv();

    $app = app(EdgeRealtimeApps::class)->provision($site, '', []);

    expect(EdgeRealtimeApps::record($app))->toContain('"allowedOrigins":[]')
        ->and($app->max_connections)->toBe(200)
        ->and($app->name)->toBe($site->name);
});

test('the record spreads an app over one hub per shard_size sockets', function () {
    [, $site] = realtimeSite();
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'Chat', ['max_connections' => 5000]);
    $shards = fn (?int $max): int => json_decode(EdgeRealtimeApps::record($app, $max), true)['shards'];

    expect($shards(null))->toBe(1)       // Pro/Team sizes stay one hub
        ->and($shards(10000))->toBe(1)
        ->and($shards(20000))->toBe(2)   // Enterprise
        ->and($shards(0))->toBe(1)       // plan without Realtime
        ->and($shards(PHP_INT_MAX))->toBe(32);

    config(['edge.realtime.shard_size' => 1000]);
    expect($shards(null))->toBe(5);
});

test('provision refuses when the relay namespace is not configured', function () {
    [, $site] = realtimeSite();
    config(['edge.realtime.kv_namespace_id' => null]);
    Http::fake();

    expect(fn () => app(EdgeRealtimeApps::class)->provision($site, 'x'))->toThrow(RuntimeException::class, 'EDGE_REALTIME_KV_NAMESPACE_ID');
    expect(EdgeRealtimeApp::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('rotating the secret rewrites both keys', function () {
    [, $site] = realtimeSite();
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');
    $old = $app->app_secret;

    app(EdgeRealtimeApps::class)->rotateSecret($app);

    expect($app->fresh()->app_secret)->not->toBe($old)->toStartWith('rts_');
    Http::assertSent(fn (Request $r): bool => $r->method() === 'PUT' && str_contains($r->body(), $app->fresh()->app_secret) && str_contains($r->url(), rawurlencode('key:'.$app->app_key)));
});

test('deleting the connection deletes both KV keys and the row', function () {
    [$org, $site] = realtimeSite();
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');

    expect(EdgeContainerConnections::destroy('realtime', $app->id, $org))->toBeTrue();

    foreach (['id:'.$app->id, 'key:'.$app->app_key] as $key) {
        Http::assertSent(fn (Request $r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/namespaces/rt-ns/values/'.rawurlencode($key)));
    }
    expect(EdgeRealtimeApp::query()->count())->toBe(0);
});

test('another organization cannot own, list or delete the app', function () {
    [$org, $site] = realtimeSite();
    [$other] = realtimeSite();
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'Chat');

    expect(EdgeContainerConnections::owns('realtime', $app->id, $org))->toBeTrue()
        ->and(EdgeContainerConnections::owns('realtime', $app->id, $other))->toBeFalse()
        ->and(EdgeContainerConnections::catalog('realtime', $org))->toBe([['id' => $app->id, 'label' => 'Chat']])
        ->and(EdgeContainerConnections::catalog('realtime', $other))->toBe([])
        ->and(EdgeContainerConnections::destroy('realtime', $app->id, $other))->toBeFalse();
    expect(EdgeRealtimeApp::query()->count())->toBe(1);
});

test('a Laravel container app gets Reverb and Pusher env, and keeps it asleep', function () {
    [, $site] = realtimeSite('container', ['build' => ['framework' => 'laravel']]);
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');
    attachRealtime($site, $app);

    $env = EdgeContainerConnections::realtimeDriverEnv($site);

    expect($env)->toMatchArray([
        'BROADCAST_CONNECTION' => 'reverb',
        'REVERB_APP_ID' => $app->id,
        'REVERB_APP_KEY' => $app->app_key,
        'REVERB_APP_SECRET' => $app->app_secret,
        'REVERB_HOST' => 'realtime-apps.test',
        'REVERB_PORT' => '443',
        'REVERB_SCHEME' => 'https',
        'PUSHER_APP_ID' => $app->id,
        'PUSHER_APP_KEY' => $app->app_key,
        'PUSHER_APP_SECRET' => $app->app_secret,
        'PUSHER_HOST' => 'realtime-apps.test',
        'PUSHER_PORT' => '443',
        'PUSHER_SCHEME' => 'https',
        'PUSHER_APP_CLUSTER' => 'mt1',
        'VITE_REVERB_APP_KEY' => $app->app_key,
        'VITE_REVERB_HOST' => 'realtime-apps.test',
        'VITE_REVERB_PORT' => '443',
        'VITE_REVERB_SCHEME' => 'https',
        'VITE_PUSHER_APP_KEY' => $app->app_key,
        'VITE_PUSHER_APP_CLUSTER' => 'mt1',
    ])
        ->and(array_keys(EdgeContainerConnections::realtimeBuildEnv($site)))->each->toStartWith('VITE_')
        ->and(EdgeContainerConnections::realtimeBuildEnv($site))->not->toHaveKey('VITE_REVERB_APP_SECRET');

    $rows = EdgeContainerConnections::for($site);
    $rows[0]['asleep'] = true;
    $site->mergeEdgeMeta(['connections' => $rows]);
    $site->save();

    // Sleep is enforced by the relay, so waking needs no redeploy.
    expect(EdgeContainerConnections::realtimeDriverEnv($site->fresh()))->toBe($env);
});

test('a non-Laravel app gets credentials but no BROADCAST_CONNECTION', function () {
    [, $site] = realtimeSite('ssr');
    fakeKv();
    attachRealtime($site, app(EdgeRealtimeApps::class)->provision($site, 'x'));

    expect(EdgeContainerConnections::realtimeDriverEnv($site))->not->toHaveKey('BROADCAST_CONNECTION')->toHaveKey('REVERB_APP_KEY');
});

test('a Worker app gets the env as bindings, secrets as secret_text, its own names left out', function () {
    [, $site] = realtimeSite('ssr');
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');
    attachRealtime($site, $app);

    $bindings = collect(EdgeContainerConnections::resourceWorkerBindings($site, ['REVERB_HOST']))->keyBy('name');

    expect($bindings->get('REVERB_APP_KEY'))->toBe(['name' => 'REVERB_APP_KEY', 'type' => 'plain_text', 'text' => $app->app_key])
        ->and($bindings->get('REVERB_APP_SECRET')['type'])->toBe('secret_text')
        ->and($bindings->get('PUSHER_APP_SECRET')['type'])->toBe('secret_text')
        ->and($bindings->has('REVERB_HOST'))->toBeFalse();
});

test('the asset build receives the realtime VITE vars; a saved value wins', function () {
    Storage::fake('edge_r2');
    config(['edge.disk.name' => 'edge_r2', 'edge.fake.enabled' => false]);
    [$org, $site] = realtimeSite('ssr', [
        'source' => ['repo' => 'acme/app', 'branch' => 'main'],
        'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
    ]);
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');
    attachRealtime($site, $app);
    $site->edgeEnvVars()->create(['key' => 'VITE_REVERB_HOST', 'value' => 'ws.example.com', 'scope' => 'production']);
    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'git_branch' => 'main',
        'storage_prefix' => 'edge/'.$org->id.'/'.$site->id.'/01RT',
    ]);

    $captured = null;
    $runner = Mockery::mock(EdgeBuildRunner::class);
    $runner->shouldReceive('build')->once()->andReturnUsing(function (...$args) use (&$captured) {
        $captured = $args[5];
        throw new RuntimeException('stop after build args');
    });
    app()->instance(EdgeBuildRunner::class, $runner);
    $publisher = Mockery::mock(EdgeArtifactPublisher::class)->makePartial();
    $publisher->shouldReceive('uploadFile')->andReturnNull();
    app()->instance(EdgeArtifactPublisher::class, $publisher);

    try {
        app()->call([new BuildEdgeSiteJob($deployment->id), 'handle']);
    } catch (RuntimeException) {
    }

    expect($captured)->toBeArray()
        ->and($captured['VITE_REVERB_APP_KEY'] ?? null)->toBe($app->app_key)
        ->and($captured['VITE_PUSHER_APP_KEY'] ?? null)->toBe($app->app_key)
        ->and($captured['VITE_REVERB_HOST'] ?? null)->toBe('ws.example.com')
        ->and($captured)->not->toHaveKey('REVERB_APP_SECRET');
});

test('a container build gets VITE vars as .env.production.local in the checkout', function () {
    $checkout = sys_get_temp_dir().'/dply-rt-'.uniqid();
    File::ensureDirectoryExists($checkout);

    expect(EdgeContainerDeployer::writeViteBuildEnv($checkout, ['APP_KEY' => 'secret', 'VITE_REVERB_APP_KEY' => 'rtk_abc', 'VITE_REVERB_HOST' => 'h.test']))->toBeTrue()
        ->and(File::get($checkout.'/.env.production.local'))->toBe("VITE_REVERB_APP_KEY=\"rtk_abc\"\nVITE_REVERB_HOST=\"h.test\"\n")
        ->and(EdgeContainerDeployer::writeViteBuildEnv($checkout.'/none', ['APP_KEY' => 'x']))->toBeFalse();

    File::deleteDirectory($checkout);
});

test('stats() reads the relay counters with the app credentials', function () {
    [, $site] = realtimeSite();
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');
    Http::fake(['realtime-apps.test/*' => Http::response([
        'connections' => 12, 'peak_connections' => 40, 'connection_seconds' => 123456,
        'messages_in' => 900, 'messages_out' => 15000, 'updated_at' => 1790460000,
    ])]);

    expect(app(EdgeRealtimeApps::class)->stats($app))->toBe([
        'connections' => 12, 'peak_connections' => 40, 'connection_seconds' => 123456,
        'messages_in' => 900, 'messages_out' => 15000, 'updated_at' => 1790460000,
    ]);
    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://realtime-apps.test/apps/'.$app->id.'/stats'
        && $r->header('X-Dply-Key') === [$app->app_key] && $r->header('X-Dply-Secret') === [$app->app_secret]);
});

test('stats() throws when the relay fails', function () {
    [, $site] = realtimeSite();
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');
    Http::fake(['realtime-apps.test/*' => Http::response('nope', 500)]);

    expect(fn () => app(EdgeRealtimeApps::class)->stats($app))->toThrow(RuntimeException::class);
});

test('publish signing matches the Pusher documentation vector', function () {
    $body = '{"name":"foo","channels":["project-3"],"data":"{\"some\":\"data\"}"}';
    $query = EdgeRealtimeApps::signedQuery('278d425bdf160c739803', '7ad3773142a6692b25b8', '/apps/3/events', $body, '1353088179');

    expect($query)->toBe([
        'auth_key' => '278d425bdf160c739803',
        'auth_timestamp' => '1353088179',
        'auth_version' => '1.0',
        'body_md5' => 'ec365a775a4cd0599faeb73354201b6f',
        'auth_signature' => 'da454824c97ba181a32ccc17a72625ba02771f50b50e1e7430e47a1f3f457e6c',
    ]);
});

test('publish posts a signed event whose body_md5 matches the bytes sent', function () {
    [, $site] = realtimeSite();
    fakeKv();
    $app = app(EdgeRealtimeApps::class)->provision($site, 'x');
    Http::fake(['realtime-apps.test/*' => Http::response('{}')]);

    app(EdgeRealtimeApps::class)->publish($app, 'orders', 'OrderShipped', ['id' => 1]);

    Http::assertSent(function (Request $r) use ($app): bool {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
        $expected = EdgeRealtimeApps::signedQuery($app->app_key, $app->app_secret, '/apps/'.$app->id.'/events', $r->body(), (string) $q['auth_timestamp']);

        return str_starts_with($r->url(), 'https://realtime-apps.test/apps/'.$app->id.'/events?')
            && $r->body() === '{"name":"OrderShipped","channels":["orders"],"data":"{\"id\":1}"}'
            && $q === $expected;
    });
});

test('the builder creates one Realtime app per site', function () {
    [$org, $site, $user] = realtimeSite('container', ['build' => ['framework' => 'laravel']]);
    fakeKv();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openConnectionBuilder')
        ->call('chooseConnectionKind', 'realtime')
        ->assertSet('connectionMode', 'create')
        ->assertSee('Allowed origins')
        ->set('realtimeName', 'Chat')
        ->call('$set', 'realtimeMaxConnections', 1000)
        ->set('realtimeAllowedOrigins', "https://Example.com/\nhttps://app.example.com")
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->assertOk();

    $app = EdgeRealtimeApp::query()->sole();
    $connection = collect(EdgeContainerConnections::for($site->fresh()))->firstWhere('kind', 'realtime');
    expect($app->organization_id)->toBe($org->id)
        ->and($app->max_connections)->toBe(1000)
        ->and($app->allowed_origins)->toBe(['https://example.com', 'https://app.example.com'])
        ->and($connection['name'])->toBe('REALTIME')
        ->and($connection['target'])->toBe($app->id)
        ->and($connection['host'])->toBe(EdgeContainerConnections::resourceHost($site, 'realtime'));

    // A realtime row renders, and a second one is refused.
    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site->fresh()])
        ->assertOk()
        ->call('chooseConnectionKind', 'realtime')
        ->call('saveConnection')
        ->assertHasErrors('connection');
    expect(EdgeRealtimeApp::query()->count())->toBe(1);
});

test('the builder needs a card', function () {
    [, $site, $user] = realtimeSite('container', [], false);
    Http::fake();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openConnectionBuilder')
        ->call('chooseConnectionKind', 'realtime')
        ->assertSee('Add a card before starting Realtime')
        ->call('saveConnection')
        ->assertHasErrors('connection');

    expect(EdgeRealtimeApp::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('runtime detection mentions Reverb without suggesting a process', function () {
    $root = sys_get_temp_dir().'/dply-rt-detect-'.uniqid();
    File::ensureDirectoryExists($root);
    File::put($root.'/composer.json', json_encode(['require' => ['laravel/framework' => '^12.0', 'laravel/reverb' => '^1.0']]));
    File::put($root.'/artisan', '');

    $detection = (new PhpRuntimeDetector)->detect($root);

    expect(implode("\n", $detection->reasons))->toContain('laravel/reverb')
        ->and($detection->processes)->toBe([]);
    File::deleteDirectory($root);
});

test('an app never loses shards when its size shrinks', function () {
    config(['edge.realtime.shard_size' => 10000]);
    $app = new EdgeRealtimeApp(['max_connections' => 20000, 'allowed_origins' => [], 'client_events' => false]);
    $app->id = '01SHARDSHARDSHARDSHARDSHAR';

    expect(json_decode(EdgeRealtimeApps::record($app), true)['shards'])->toBe(2);

    $app->meta = ['shards' => 2];
    $app->max_connections = 5000;
    expect(json_decode(EdgeRealtimeApps::record($app), true)['shards'])->toBe(2);
});
