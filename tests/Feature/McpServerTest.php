<?php

declare(strict_types=1);

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function mcpCall(string $token, string $method, array $params = []): TestResponse
{
    return test()->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params], [
        'Authorization' => 'Bearer '.$token,
        'Accept' => 'application/json, text/event-stream',
    ]);
}

test('the MCP server lists its tools and runs list_sites for the token\'s organization', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ['plaintext' => $token] = ApiToken::createToken($user, $org, 'mcp-test', null, ['sites.read']);
    $server = Server::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    Site::factory()->create(['server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'mcp-visible-site']);
    Site::factory()->create(['name' => 'someone-elses-site']);

    mcpCall($token, 'tools/list')->assertOk()->assertSee('list_sites')->assertSee('get_site');

    mcpCall($token, 'tools/call', ['name' => 'list_sites', 'arguments' => (object) []])
        ->assertOk()
        ->assertSee('mcp-visible-site')
        ->assertDontSee('someone-elses-site');
});

test('the MCP server refuses a request without a token', function () {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
});

test('the MCP server offers only the edge read tools and returns edge fields', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ['plaintext' => $token] = ApiToken::createToken($user, $org, 'mcp-test', null, ['sites.read']);
    $server = Server::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'shop', 'edge_backend' => 'dply_edge',
        'meta' => ['edge' => [
            'live_url' => 'https://shop-abc.on-dply.live',
            'source' => ['repo' => 'acme/shop', 'branch' => 'main'],
            'routing' => ['custom_domains' => ['shop.example.com' => ['dns_status' => 'ready', 'ssl_status' => 'active']]],
        ]],
    ]);
    Site::factory()->create([
        'server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'shop-preview-42',
        'meta' => ['edge' => ['preview_parent_site_id' => $site->id]],
    ]);

    mcpCall($token, 'tools/list')->assertOk()
        ->assertDontSee('list_servers')->assertDontSee('get_operation_status')->assertDontSee('deploy_site');
    mcpCall($token, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 't', 'version' => '1']])
        ->assertOk()->assertSee('get_site_health');

    mcpCall($token, 'tools/call', ['name' => 'list_sites', 'arguments' => (object) []])
        ->assertOk()->assertSee('shop-abc.on-dply.live')->assertDontSee('shop-preview-42');
    mcpCall($token, 'tools/call', ['name' => 'get_site', 'arguments' => ['site_id' => $site->id]])
        ->assertOk()->assertSee('acme\/shop', false)->assertSee('shop.example.com');
});

test('MCP resources enforce the sites.read ability', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ['plaintext' => $token] = ApiToken::createToken($user, $org, 'mcp-test', null, ['account.read']);

    mcpCall($token, 'resources/read', ['uri' => 'dply://sites'])->assertSee('sites.read');
});

function diagnosticsSetup(array $abilities = ['edge.read']): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    ['plaintext' => $token] = ApiToken::createToken($user, $org, 'mcp-diag', null, $abilities);
    $server = Server::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);
    $site = Site::factory()->create([
        'server_id' => $server->id, 'organization_id' => $org->id, 'name' => 'shop', 'edge_backend' => 'dply_edge',
        'meta' => ['edge' => [
            'live_url' => 'https://shop-abc.on-dply.live',
            'routing' => ['custom_domains' => ['shop.example.com' => ['dns_status' => 'ready']]],
        ]],
    ]);

    return [$token, $site, $org];
}

test('diagnostic tools need edge.read and stay inside the token\'s organization', function () {
    [$token, $site] = diagnosticsSetup(['sites.read']);
    mcpCall($token, 'tools/call', ['name' => 'get_site_health', 'arguments' => ['site_id' => $site->id]])->assertSee('edge.read');

    [$token] = diagnosticsSetup();
    $other = Site::factory()->create(['name' => 'not-mine']);
    mcpCall($token, 'tools/call', ['name' => 'list_deployments', 'arguments' => ['site_id' => $other->id]])->assertSee('was not found');
});

test('list_deployments and get_deployment_log show a failed deploy and its log', function () {
    [$token, $site] = diagnosticsSetup();
    $log = tempnam(sys_get_temp_dir(), 'dply-log');
    file_put_contents($log, "Building \xff\xfe\nRunning migrations (php artisan migrate --force)\nmigrations failed: HTTP 404\n");
    $deployment = \App\Models\EdgeDeployment::query()->create([
        'site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => 'failed',
        'git_commit' => 'abc1234', 'failure_reason' => 'Container deploy failed: migrations failed',
        'meta' => ['local_build_log_path' => $log],
    ]);

    mcpCall($token, 'tools/call', ['name' => 'list_deployments', 'arguments' => ['site_id' => $site->id]])
        ->assertOk()->assertSee($deployment->id)->assertSee('migrations failed');
    mcpCall($token, 'tools/call', ['name' => 'get_deployment_log', 'arguments' => ['site_id' => $site->id, 'tail_lines' => 2]])
        ->assertOk()->assertSee('migrations failed: HTTP 404')->assertDontSee('Building');
    // Invalid UTF-8 in the log (Docker output) must not break the JSON reply.
    mcpCall($token, 'tools/call', ['name' => 'get_deployment_log', 'arguments' => ['site_id' => $site->id]])
        ->assertOk()->assertSee('Building')->assertDontSee('Malformed');
});

test('probe_url requests only the app\'s own hostnames, without following redirects', function () {
    \Illuminate\Support\Facades\Http::fake(['https://shop.example.com/*' => \Illuminate\Support\Facades\Http::response('<h1>hi</h1>', 404, ['x-dply-ref' => 'r1'])]);
    [$token, $site] = diagnosticsSetup();

    mcpCall($token, 'tools/call', ['name' => 'probe_url', 'arguments' => ['site_id' => $site->id, 'host' => 'shop.example.com', 'path' => '/nope']])
        ->assertOk()->assertSee('404')->assertSee('x-dply-ref')->assertSee('hi');
    mcpCall($token, 'tools/call', ['name' => 'probe_url', 'arguments' => ['site_id' => $site->id, 'host' => 'evil.example.net']])
        ->assertSee('host must be one of');
    \Illuminate\Support\Facades\Http::assertSentCount(1);

    // A custom domain the owner pointed at a private address is refused (SSRF guard).
    app()->instance('dply.outbound-dns', fn (string $host): array => ['10.0.0.5']);
    mcpCall($token, 'tools/call', ['name' => 'probe_url', 'arguments' => ['site_id' => $site->id, 'host' => 'shop.example.com']])
        ->assertSee('private address');
    \Illuminate\Support\Facades\Http::assertSentCount(1);
});

test('get_site_health and get_recent_requests report a static app', function () {
    [$token, $site] = diagnosticsSetup();
    config(['edge.cloudflare.analytics_dataset' => 'dply_edge_requests']);
    $client = Mockery::mock(\App\Modules\Providers\Cloudflare\EdgeCloudflareClient::class);
    $client->shouldReceive('canQueryAnalyticsEngine')->andReturn(true);
    $client->shouldReceive('queryAnalyticsEngineSql')->andReturn([
        ['timestamp' => '2026-10-01 20:00:00', 'status' => 200, 'path' => '/ok', 'method' => 'GET'],
        ['timestamp' => '2026-10-01 20:00:01', 'status' => 404, 'path' => '/livewire-x/update', 'method' => 'POST'],
    ]);
    $this->instance(\App\Modules\Edge\Support\EdgeAnalyticsEngineTraffic::class, new \App\Modules\Edge\Support\EdgeAnalyticsEngineTraffic($client));

    mcpCall($token, 'tools/call', ['name' => 'get_site_health', 'arguments' => ['site_id' => $site->id]])
        ->assertOk()->assertSee('shop-abc.on-dply.live')->assertSee('organization_billing_paused');
    mcpCall($token, 'tools/call', ['name' => 'get_recent_requests', 'arguments' => ['site_id' => $site->id, 'min_status' => 400]])
        ->assertOk()->assertSee('livewire-x')->assertDontSee('\/ok', false);
    mcpCall($token, 'tools/call', ['name' => 'get_app_logs', 'arguments' => ['site_id' => $site->id]])
        ->assertSee('container apps only');
});
