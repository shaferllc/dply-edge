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
        ->assertOk()->assertDontSee('list_deployments');

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
