<?php

declare(strict_types=1);

use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function mcpCall(string $token, string $method, array $params = []): \Illuminate\Testing\TestResponse
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
