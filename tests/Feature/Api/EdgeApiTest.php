<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiToken;
use App\Models\EdgeDatabase;
use App\Models\EdgeDeployment;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.fake.enabled' => true]);
});

test('edge sites api lists org edge sites only', function () {
    [$headers, $site] = edgeApiContext(['edge.read']);

    $otherOrg = Organization::factory()->create();
    Site::factory()->create([
        'organization_id' => $otherOrg->id,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web'],
    ]);

    $this->getJson('/api/v1/edge/sites', $headers)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', (string) $site->id)
        ->assertJsonPath('data.0.live_url', 'https://edge-app.dply.host');
});

test('edge sites api show includes dashboard url', function () {
    [$headers, $site] = edgeApiContext(['edge.read']);

    $this->getJson('/api/v1/edge/sites/'.$site->id, $headers)
        ->assertOk()
        ->assertJsonPath('data.id', (string) $site->id)
        ->assertJsonPath('data.dashboard_url', route('sites.show', [
            'server' => $site->server_id,
            'site' => $site->id,
        ], absolute: true));
});

test('edge deployments api queues redeploy', function () {
    Queue::fake();
    [$headers, $site] = edgeApiContext(['edge.deploy']);

    $this->postJson('/api/v1/edge/sites/'.$site->id.'/deployments', [], $headers)
        ->assertStatus(202)
        ->assertJsonPath('data.status', EdgeDeployment::STATUS_BUILDING);

    Queue::assertPushed(BuildEdgeSiteJob::class);
});

test('edge lint api validates config content', function () {
    [$headers] = edgeApiContext(['edge.read']);

    $this->postJson('/api/v1/edge/lint', [
        'path' => 'dply.yaml',
        'content' => "build:\n  command: npm run build\n",
    ], $headers)
        ->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.summary.build_keys.0', 'command');
});

test('edge lint api returns 422 for parse errors', function () {
    [$headers] = edgeApiContext(['edge.read']);

    $this->postJson('/api/v1/edge/lint', [
        'path' => 'dply.yaml',
        'content' => "build:\n  command: [\n",
    ], $headers)
        ->assertStatus(422)
        ->assertJsonPath('data.ok', false)
        ->assertJson(fn ($json) => $json->whereType('data.errors', 'array')->etc());
});

/**
 * @param  list<string>  $abilities
 * @return array{0: array<string, string>, 1: Site}
 */
function edgeApiContext(array $abilities): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    ['plaintext' => $plain] = ApiToken::createToken($user, $org, 'edge-api-test', null, $abilities);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'live_url' => 'https://edge-app.dply.host',
                'source' => [
                    'repo' => 'https://github.com/acme/site.git',
                    'branch' => 'main',
                ],
            ],
        ],
    ]);

    return [
        [
            'Authorization' => 'Bearer '.$plain,
            'Accept' => 'application/json',
        ],
        $site,
    ];
}

test('databases and queues api lists, queries and sends for the token org only', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    [$headers, $site] = edgeApiContext(['edge.read', 'edge.write']);
    EdgeDatabase::query()->create(['organization_id' => $site->organization_id, 'name' => 'app', 'cloudflare_id' => 'db-1']);
    EdgeDatabase::query()->create(['organization_id' => Organization::factory()->create()->id, 'name' => 'theirs', 'cloudflare_id' => 'db-9']);
    EdgeQueue::query()->create(['organization_id' => $site->organization_id, 'name' => 'jobs', 'cloudflare_id' => 'q-1', 'cloudflare_name' => 'dply-x-jobs']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/d1/database/db-1/query' => Http::response(['success' => true, 'result' => [['results' => [['n' => 1]], 'success' => true]]]),
        'api.cloudflare.com/client/v4/accounts/acct/queues/q-1/messages' => Http::response(['success' => true, 'result' => null]),
    ]);

    $this->getJson('/api/v1/edge/databases', $headers)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'app');
    $this->postJson('/api/v1/edge/databases/app/query', ['sql' => 'select 1 as n'], $headers)->assertOk()->assertJsonPath('data.0.results.0.n', 1);
    $this->postJson('/api/v1/edge/databases/theirs/query', ['sql' => 'select 1'], $headers)->assertNotFound();
    $this->getJson('/api/v1/edge/queues', $headers)->assertOk()->assertJsonPath('data.0.name', 'jobs');
    $this->postJson('/api/v1/edge/queues/jobs/messages', ['body' => ['hello' => 'world']], $headers)->assertStatus(202);
});

test('running sql needs edge.write', function () {
    [$headers, $site] = edgeApiContext(['edge.read']);
    EdgeDatabase::query()->create(['organization_id' => $site->organization_id, 'name' => 'app', 'cloudflare_id' => 'db-1']);

    $this->postJson('/api/v1/edge/databases/app/query', ['sql' => 'drop table users'], $headers)->assertForbidden();
});
