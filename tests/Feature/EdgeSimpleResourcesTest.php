<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgePlatformUsage;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
});

/** @param  list<array<string, mixed>>  $connections */
function simpleResourceApp(array $connections, string $runtime = 'container'): array
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
    // Hosts are dply.{app}.{resource}.internal; mount rewrites bare ones.
    $site->mergeEdgeMeta(['connections' => array_map(static fn (array $c): array => ['host' => EdgeContainerConnections::resourceHost($site, strtolower($c['name']))] + $c, $connections)]);
    $site->save();

    return [$user, $server, $site];
}

test('state opens its sheet with container snippets and a delete that explains the keys stay', function () {
    [$user, $server, $site] = simpleResourceApp([['kind' => 'durable_object', 'name' => 'COUNTERS', 'target' => '']]);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'counters'))
        ->assertDispatched('open-modal', 'resources-durable-object')
        ->assertSee('curl -X POST http://'.EdgeContainerConnections::resourceHost($site, 'counters').'/incr/visits', false)
        ->assertSee('This page cannot read or write it')
        ->call('askDeleteConnection', EdgeContainerConnections::resourceHost($site, 'counters'))
        ->assertSee('The keys are not wiped');
});

test('state on a worker app shows env usage', function () {
    [$user, $server, $site] = simpleResourceApp([['kind' => 'durable_object', 'name' => 'COUNTERS', 'target' => '']], 'ssr');

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'counters'))
        ->assertSee("env.COUNTERS.fetch('https://state/incr/visits'");
});

test('the ai demo runs an example model through the account api', function () {
    Http::fake(['api.cloudflare.com/*' => Http::response(['success' => true, 'result' => ['response' => 'Hello there, friend, hi!']])]);
    [$user, $server, $site] = simpleResourceApp([['kind' => 'ai', 'name' => 'AI', 'target' => '']]);
    // AI is paid-only, the dashboard demo included; a comped org is on a paid plan.
    $site->organization->forceFill(['comped_until' => now()->addYear()])->save();

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'ai'))
        ->assertSee('Billed per neuron')
        ->set('aiModel', '@cf/meta/llama-3.2-3b-instruct')
        ->set('aiPrompt', 'Say hi')
        ->call('runAiDemo')
        ->assertHasNoErrors()
        ->assertSet('aiDemoResult', 'Hello there, friend, hi!');

    Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/accounts/acct/ai/run/@cf/meta/llama-3.2-3b-instruct')
        && $request['prompt'] === 'Say hi');
    // The demo runs on the platform account, so it is metered like the app's calls.
    expect(EdgePlatformUsage::query()->where('resource', 'meter:'.$site->id)->value('ai_neurons'))->toBeGreaterThan(0);
});

test('the ai demo refuses a model that is not on the example list', function () {
    Http::fake();
    [$user, $server, $site] = simpleResourceApp([['kind' => 'ai', 'name' => 'AI', 'target' => '']]);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'ai'))
        ->set('aiModel', '@cf/some/expensive-model')
        ->call('runAiDemo')
        ->assertSet('aiDemoResult', '');

    Http::assertNothingSent();
});

test('a leftover row of a removed kind is dropped, not shown', function () {
    [$user, $server, $site] = simpleResourceApp([
        ['kind' => 'http_delivery', 'name' => 'HOOKS', 'target' => 'old'],
        ['kind' => 'ai', 'name' => 'AI', 'target' => ''],
    ]);

    expect(array_column(EdgeContainerConnections::for($site), 'kind'))->toBe(['ai']);

    Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->assertOk()
        ->assertDontSee('HOOKS');
});

test('a pasted redis shows a masked address, replaces it, and refuses to probe a private host', function () {
    [$user, $server, $site] = simpleResourceApp([['kind' => 'redis', 'name' => 'CACHE', 'target' => '']]);
    (new EdgeSiteEnvVar(['site_id' => $site->id, 'key' => 'REDIS_URL', 'value' => 'rediss://default:s3cret@cache.example:6380', 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]))->save();

    $component = Livewire::actingAs($user)
        ->test(Resources::class, ['server' => $server, 'site' => $site])
        ->assertSee('cache.example')
        ->call('openResource', EdgeContainerConnections::resourceHost($site, 'cache'))
        ->assertDispatched('open-modal', 'resources-redis-external')
        ->assertSee('rediss://default:••••@cache.example:6380')
        ->assertDontSee('s3cret')
        ->set('externalRedisUrl', 'not a url')
        ->call('replaceExternalRedisUrl')
        ->assertHasErrors('externalRedis')
        ->set('externalRedisUrl', 'redis://app:n3w@127.0.0.1:6379')
        ->call('replaceExternalRedisUrl')
        ->assertHasNoErrors();

    expect($site->edgeEnvVars()->where('key', 'REDIS_URL')->first()->value)->toBe('redis://app:n3w@127.0.0.1:6379')
        ->and($site->edgeEnvVars()->where('key', 'REDIS_PASSWORD')->first()->value)->toBe('n3w');

    $component->call('testExternalRedis')
        ->assertSet('externalRedisTest.ok', false)
        ->assertSet('externalRedisTest.message', 'Host resolves to a private address.');
});
