<?php

declare(strict_types=1);

namespace Tests\Feature\SelfHostingTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\PublishEdgeDeploymentJob;
use App\Modules\Edge\Services\Config\EdgeRepoConfigLinter;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Services\SelfHosting\SelfDeployer;
use App\Modules\Edge\Services\SelfHosting\SelfDeployState;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Support\DplyRuntime;
use Dotenv\Dotenv;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;

uses(RefreshDatabase::class);

const OLD_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const NEW_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

beforeEach(function () {
    config([
        'edge.fake.enabled' => false,
        'edge.r2.bucket' => 'dply-edge-test',
        'edge.cloudflare.account_id' => 'acct-from-env',
        'edge.cloudflare.api_token' => 'cf-token-secret-value',
        'edge.cloudflare.dispatch_namespace_name' => 'ns-from-env',
        'edge.build.containers.deploy_api_token' => '',
        'edge.self.hostname' => 'edge.dply.io',
    ]);
});

function selfSite(): Site
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create(['organization_id' => $org->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'slug' => 'dply',
    ]);
    $site->update(['meta' => ['edge' => [
        'self_hosted' => true,
        'runtime_mode' => 'container',
        'live_url' => 'https://dply-abc123.dply.host',
        'container' => ['rollout_mode' => 'gradual'],
    ]]]);
    config(['edge.self.site_id' => $site->id]);

    return $site->refresh();
}

/** R2 state reads answer with $state; writes are captured. */
function fakeR2(array $state = []): void
{
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use ($state) {
        if (str_contains($request->url(), '/r2/buckets/') && $request->method() === 'GET') {
            return $state === [] ? Http::response(['errors' => [['message' => 'not found']]], 404) : Http::response(json_encode($state), 200);
        }
        if (str_contains($request->url(), '/r2/buckets/') && $request->method() === 'PUT') {
            return Http::response(['success' => true], 200);
        }

        return Http::response('unexpected', 500);
    });
}

test('dry run prints every step from the env config and runs nothing', function () {
    $site = selfSite();
    fakeR2();
    Process::fake();

    $this->artisan('dply:self:deploy', ['--dry-run' => true, '--ref' => 'origin/main'])
        ->expectsOutputToContain('Resolve origin/main to a commit')
        ->expectsOutputToContain('Check out')
        ->expectsOutputToContain('Generate the container image definition')
        ->expectsOutputToContain('Write the Worker project from the self site')
        ->expectsOutputToContain('Run migrations once, in the new image')
        ->expectsOutputToContain('php artisan migrate --force')
        ->expectsOutputToContain('account acct-from-env, namespace ns-from-env, script dply-ctr-'.strtolower((string) $site->id))
        ->expectsOutputToContain('CLOUDFLARE_API_TOKEN=***')
        ->expectsOutputToContain('Health check: GET https://dply-abc123.dply.host/up must answer 200')
        // Before cutover edge.dply.io is the old control plane: not checked.
        ->doesntExpectOutputToContain('GET https://edge.dply.io/up')
        ->doesntExpectOutputToContain('cf-token-secret-value')
        ->assertSuccessful();

    Process::assertNothingRan();
    Http::assertNotSent(fn (Request $request) => $request->method() !== 'GET');
    expect(EdgeDeployment::query()->count())->toBe(0);
});

test('an env file is applied by re-running in a child with the file as its env', function () {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "APP_KEY=base64:abc\nDPLY_EDGE_R2_BUCKET=prod-bucket\nDPLY_EDGE_CF_ACCOUNT_ID=prod-acct\nDPLY_EDGE_CF_API_TOKEN=t\nDPLY_EDGE_CF_DISPATCH_NAMESPACE=ns\nCACHE_STORE=redis\n");
    $localEnv = tempnam(sys_get_temp_dir(), 'localenv');
    file_put_contents($localEnv, "APP_KEY=base64:local\nLOCAL_ONLY_SETTING=dev-value\n");
    app()->loadEnvironmentFrom(basename($localEnv));
    app()->useEnvironmentPath(dirname($localEnv));
    Process::fake();

    $this->artisan('dply:self:deploy', ['--env-file' => $file, '--dry-run' => true])->assertSuccessful();

    Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'dply:self:deploy')
        && str_contains(implode(' ', (array) $process->command), '--dry-run')
        && ($process->environment['DPLY_EDGE_R2_BUCKET'] ?? null) === 'prod-bucket'
        && ($process->environment['DPLY_FAKE_EDGE'] ?? null) === 'false'
        // this process only: the file's CACHE_STORE does not win here
        && ($process->environment['CACHE_STORE'] ?? null) === 'array'
        // a key only this machine's .env has is unset, not inherited
        && array_key_exists('LOCAL_ONLY_SETTING', $process->environment) && $process->environment['LOCAL_ONLY_SETTING'] === false
        && ($process->environment['APP_KEY'] ?? null) === 'base64:abc');
    @unlink($file);
    @unlink($localEnv);
});

test('an env file missing the Cloudflare account is refused', function () {
    $file = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($file, "APP_KEY=base64:abc\nDPLY_EDGE_R2_BUCKET=prod-bucket\n");
    Process::fake();

    $this->artisan('dply:self:deploy', ['--env-file' => $file, '--dry-run' => true])
        ->expectsOutputToContain('has no DPLY_EDGE_CF_ACCOUNT_ID')
        ->assertFailed();
    Process::assertNothingRan();
    @unlink($file);
});

test('rollback picks the newest successful commit that is not the live one', function () {
    $history = [
        ['sha' => NEW_SHA, 'status' => 'failed', 'at' => '2026-09-27T03:00:00+00:00'],
        ['sha' => OLD_SHA, 'status' => 'live', 'at' => '2026-09-27T02:00:00+00:00'],
        ['sha' => 'cccccccccccccccccccccccccccccccccccccccc', 'status' => 'live', 'at' => '2026-09-27T01:00:00+00:00'],
    ];

    // --rollback: the live one is OLD, so the one before it.
    expect(SelfDeployState::rollbackTarget($history)['sha'])->toBe('cccccccccccccccccccccccccccccccccccccccc');
    // Automatic rollback after NEW failed: back to OLD.
    expect(SelfDeployState::rollbackTarget($history, NEW_SHA)['sha'])->toBe(OLD_SHA);
    expect(SelfDeployState::rollbackTarget([['sha' => OLD_SHA, 'status' => 'live', 'at' => 'x']]))->toBeNull();
});

test('rollback history merges database rows with the R2 state', function () {
    $site = selfSite();
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_SUPERSEDED, 'git_commit' => OLD_SHA, 'created_at' => now()->subHour()]);
    fakeR2(['site_id' => $site->id, 'deploys' => [['sha' => NEW_SHA, 'ref' => 'origin/main', 'status' => 'live', 'at' => now()->toIso8601String()]]]);
    Process::fake();

    $this->artisan('dply:self:deploy', ['--rollback' => true, '--dry-run' => true])
        ->expectsOutputToContain('Rolling back to '.OLD_SHA)
        ->expectsOutputToContain('Migrations: skipped')
        ->assertSuccessful();

    // With the database down, R2 alone decides.
    expect(SelfDeployState::history([['sha' => NEW_SHA, 'status' => 'live', 'at' => 'b']], (string) $site->id, false))->toHaveCount(1);
});

test('a failed health check rolls back once to the previous commit without migrating', function () {
    $site = selfSite();
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_LIVE, 'git_commit' => OLD_SHA, 'created_at' => now()->subHour()]);
    fakeR2();

    $deployer = Mockery::mock(SelfDeployer::class)->makePartial();
    $deployer->shouldReceive('dbReachable')->andReturnTrue();
    $deployer->shouldReceive('resolveSha')->andReturn(NEW_SHA);
    $deployer->shouldReceive('release')->once()->withArgs(fn ($s, $db, $repo, $sha, $env, $migrate) => $sha === NEW_SHA && $migrate === true)
        ->andReturnUsing(function () use ($deployer) {
            $deployer->rolledOut = true;

            return ['files' => ['wrangler.jsonc' => '{}']];
        });
    $deployer->shouldReceive('release')->once()->withArgs(fn ($s, $db, $repo, $sha, $env, $migrate) => $sha === OLD_SHA && $migrate === false)
        ->andReturn(['files' => ['wrangler.jsonc' => '{}']]);
    $deployer->shouldReceive('awaitRollout')->andReturn(['ok' => true, 'settled' => true, 'reason' => null]);
    $deployer->shouldReceive('healthFailure')->twice()->andReturn('https://edge.dply.io/up answered HTTP 500', null);
    app()->instance(SelfDeployer::class, $deployer);

    $this->artisan('dply:self:deploy', ['--ref' => 'origin/main'])
        ->expectsOutputToContain('Deploy failed: https://edge.dply.io/up answered HTTP 500')
        ->expectsOutputToContain('Rolled back: '.OLD_SHA.' is live again')
        ->assertFailed();

    expect(EdgeDeployment::query()->where('git_commit', NEW_SHA)->value('status'))->toBe(EdgeDeployment::STATUS_FAILED)
        ->and(EdgeDeployment::query()->where('git_commit', OLD_SHA)->where('status', EdgeDeployment::STATUS_LIVE)->count())->toBe(1);
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->body(), NEW_SHA) && str_contains($r->body(), '"status": "failed"'));
});

test('a failure before anything reached Cloudflare does not roll back', function () {
    $site = selfSite();
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_LIVE, 'git_commit' => OLD_SHA]);
    fakeR2();

    $deployer = Mockery::mock(SelfDeployer::class)->makePartial();
    $deployer->shouldReceive('dbReachable')->andReturnTrue();
    $deployer->shouldReceive('resolveSha')->andReturn(NEW_SHA);
    $deployer->shouldReceive('release')->once()->andThrow(new \RuntimeException('migrate failed'));
    app()->instance(SelfDeployer::class, $deployer);

    $this->artisan('dply:self:deploy')
        ->expectsOutputToContain('production is unchanged')
        ->assertFailed();
});

test('register is idempotent and never queues a build', function () {
    $org = Organization::factory()->create();
    $user = User::factory()->create(['email' => 'owner@dply.test']);
    fakeR2();
    config(['edge.self.site_id' => null]);

    foreach ([1, 2] as $_) {
        $this->artisan('dply:self:register', ['--organization' => $org->id, '--owner' => 'owner@dply.test', '--skip-resources' => true])
            ->assertSuccessful();
    }

    $sites = Site::query()->where('organization_id', $org->id)->get();
    expect($sites)->toHaveCount(1)
        ->and(Server::query()->where('organization_id', $org->id)->count())->toBe(1)
        ->and(EdgeDeployment::query()->count())->toBe(0);
    $meta = $sites->first()->edgeMeta();
    expect($meta['self_hosted'])->toBeTrue()
        ->and($meta['runtime_mode'])->toBe('container')
        ->and($meta['source']['repo'])->toBe('shaferllc/dply-edge')
        ->and($meta['container']['migrate_on_boot'])->toBeFalse()
        ->and($meta['container']['scheduler'])->toBeTrue()
        ->and($meta['container']['min_instances'])->toBeGreaterThanOrEqual(1)
        ->and($meta['container']['workers']['enabled'])->toBeTrue()
        ->and($meta['container']['workers']['queues'])->not->toContain('dply-provision');
    expect(EdgeSiteEnvVar::query()->where('site_id', $sites->first()->id)->where('key', 'DPLY_RUNTIME')->count())->toBe(1)
        ->and($org->refresh()->comped_until->isAfter(now()->addYears(50)))->toBeTrue();
    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && str_contains($r->body(), (string) $sites->first()->id));
});

test('the repo dply.yaml passes the build lint', function () {
    $lint = app(EdgeRepoConfigLinter::class)->lintDirectory(base_path());

    expect($lint['ok'])->toBeTrue()->and($lint['errors'])->toBe([]);
});

test('a pool builder drains its own lane and pins publish to it', function () {
    putenv('DPLY_EDGE_BUILD_HOST_QUEUE=dply-provision-builder-abc');
    try {
        expect(DplyRuntime::queuesFor('builder'))->toContain('dply-provision-builder-abc')
            ->and(DplyRuntime::queuesFor('container'))->not->toContain('dply-provision-builder-abc');

        Queue::fake();
        PublishEdgeDeploymentJob::dispatch('01TEST', '/tmp/x')->onQueue((string) DplyRuntime::hostQueue());
        Queue::assertPushedOn('dply-provision-builder-abc', PublishEdgeDeploymentJob::class);
    } finally {
        putenv('DPLY_EDGE_BUILD_HOST_QUEUE');
    }
    expect(DplyRuntime::queuesFor('builder'))->toEqualCanonicalizing(['dply-provision', 'dply-builder']);
});

test('the public hostname is health-checked once it is attached to the self site', function () {
    $site = selfSite();
    $deployer = app(SelfDeployer::class);
    expect($deployer->healthUrls($site))->toBe(['https://dply-abc123.dply.host/up']);

    $site->mergeEdgeMeta(['routing' => ['custom_domains' => ['edge.dply.io' => ['status' => 'active']]]]);
    expect($deployer->healthUrls($site))->toBe(['https://dply-abc123.dply.host/up', 'https://edge.dply.io/up']);
});

test('a draining builder waits for its builds and publishes on its own lane', function () {
    $site = selfSite();
    putenv('DPLY_EDGE_BUILD_HOST_QUEUE=dply-provision-builder-abc');
    try {
        $deployment = EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_PUBLISHING, 'meta' => ['build_queue' => 'dply-provision-builder-abc']]);
        EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_BUILDING, 'meta' => ['build_queue' => 'dply-provision-builder-other']]);

        $this->artisan('dply:builder:drain', ['--max-wait' => 0, '--poll' => 1])
            ->expectsOutputToContain('Still busy on dply-provision-builder-abc')
            ->assertFailed();

        $deployment->update(['status' => EdgeDeployment::STATUS_LIVE]);
        // The other pod's build does not hold this one.
        $this->artisan('dply:builder:drain', ['--max-wait' => 5, '--poll' => 1])
            ->expectsOutputToContain('Drained')
            ->assertSuccessful();
    } finally {
        putenv('DPLY_EDGE_BUILD_HOST_QUEUE');
    }
});

test('bootstrap resources: creates the database and Valkey once, named after the chosen site id', function () {
    config([
        'edge.self.site_id' => null,
        'edge.valkey.regions' => [['key' => 'nyc3', 'label' => 'NY', 'cloudflare' => 'ENAM', 'api_url' => 'https://gw.test', 'token' => 't', 'domain' => 'cache.dply.io', 'db_domain' => 'db.dply.io', 'port' => 6380]],
    ]);
    $saved = [];
    Http::preventStrayRequests();
    Http::fake(function (Request $request) use (&$saved) {
        if (str_contains($request->url(), '/r2/buckets/')) {
            if ($request->method() === 'PUT') {
                $saved = json_decode($request->body(), true);

                return Http::response(['success' => true]);
            }

            return $saved === [] ? Http::response([], 404) : Http::response(json_encode($saved));
        }
        if (str_starts_with($request->url(), 'https://gw.test/tenants/')) {
            return Http::response(['ok' => true]);
        }

        return Http::response('unexpected', 500);
    });
    $envFile = tempnam(sys_get_temp_dir(), 'env');
    file_put_contents($envFile, "APP_KEY=base64:abc\nDB_HOST=old-host\n");
    putenv('DPLY_SELF_CHILD=1');
    try {
        foreach ([1, 2] as $_) {
            $this->artisan('dply:self:bootstrap', ['--env-file' => $envFile, '--owner' => 'owner@dply.test', '--phase' => 'resources'])->assertSuccessful();
        }
    } finally {
        putenv('DPLY_SELF_CHILD');
    }

    $siteId = $saved['site_id'];
    expect($siteId)->toMatch('/^[0-9a-z]{26}$/')
        ->and($saved['bootstrap']['database']['remote_id'])->toBe('pg-'.$siteId)
        ->and($saved['bootstrap']['valkey']['target'])->toContain($siteId.'-cache')
        ->and(json_encode($saved))->not->toContain('password');
    Http::assertSentCount(2 + 3 + 1); // 2 tenants, once; R2: read + 2 writes, then one read
    $env = Dotenv::parse((string) file_get_contents($envFile));
    expect($env['DB_HOST'])->toStartWith('pg-'.$siteId.'.db.')
        ->and($env['DB_SSLMODE'])->toBe('require')
        ->and($env['REDIS_URL'])->toStartWith('rediss://default:')
        ->and($env['APP_KEY'])->toBe('base64:abc');
    @unlink($envFile);
});

test('register --bootstrap on a fresh database creates the owner, org and site with the chosen id and adopts the resources', function () {
    config(['edge.self.site_id' => null, 'database.connections.pgsql.password' => 'db-secret']);
    $siteId = strtolower((string) Str::ulid());
    fakeR2(['site_id' => $siteId, 'bootstrap' => [
        'database' => ['remote_id' => 'pg-'.$siteId, 'host' => 'pg-'.$siteId.'.db.dply.io', 'region' => 'nyc3', 'size' => '0.5', 'disk_gb' => 20],
        'valkey' => ['target' => 'valkey:'.$siteId.'-cache'],
    ]]);
    putenv('REDIS_URL=rediss://default:pw@'.$siteId.'-cache.cache.dply.io:6380');
    try {
        foreach ([1, 2] as $_) {
            $this->artisan('dply:self:register', ['--bootstrap' => true, '--owner' => 'boss@dply.test'])->assertSuccessful();
        }
    } finally {
        putenv('REDIS_URL');
    }

    $site = Site::query()->find($siteId);
    expect($site)->not->toBeNull()
        ->and(User::query()->where('email', 'boss@dply.test')->count())->toBe(1)
        ->and(Site::query()->count())->toBe(1)
        ->and($site->organization->comped_until->isFuture())->toBeTrue()
        ->and($site->edgeMeta()['database']['remote_id'])->toBe('pg-'.$siteId)
        ->and(collect(EdgeContainerConnections::for($site))->where('kind', 'redis')->count())->toBe(1)
        ->and(EdgeSiteEnvVar::query()->where('site_id', $siteId)->where('key', 'DB_PASSWORD')->first()?->value)->toBe('db-secret')
        ->and(EdgeSiteEnvVar::query()->where('site_id', $siteId)->where('key', 'REDIS_URL')->count())->toBe(1);
});

test('a deploy publishes the host map to the new container script before the health check, on one deployment row', function () {
    $site = selfSite();
    fakeR2();

    $deployer = Mockery::mock(SelfDeployer::class)->makePartial();
    $deployer->shouldReceive('dbReachable')->andReturnTrue();
    $deployer->shouldReceive('resolveSha')->andReturn(NEW_SHA);
    $deployer->shouldReceive('release')->once()->andReturnUsing(function () use ($deployer) {
        $deployer->rolledOut = true;

        return ['files' => ['wrangler.jsonc' => '{}']];
    });
    $deployer->shouldReceive('awaitRollout')->andReturn(['ok' => true, 'settled' => true, 'reason' => null]);
    $published = null;
    $deployer->shouldReceive('healthFailure')->once()->andReturnUsing(function () use (&$published) {
        expect($published)->not->toBeNull(); // routed before the check

        return null;
    });
    app()->instance(SelfDeployer::class, $deployer);
    $publisher = Mockery::mock(EdgeHostMapPublisher::class);
    $publisher->shouldReceive('publish')->once()->andReturnUsing(function ($s, EdgeDeployment $d) use (&$published) {
        $published = $d;

        return 1;
    });
    app()->instance(EdgeHostMapPublisher::class, $publisher);

    $this->artisan('dply:self:deploy', ['--ref' => 'origin/main'])->assertSuccessful();

    expect($published->meta['container']['script_name'])->toBe(EdgeContainerDeployer::scriptName($site))
        ->and(EdgeDeployment::query()->where('git_commit', NEW_SHA)->sole()->status)->toBe(EdgeDeployment::STATUS_LIVE);
});
