<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeContainerInstancesTest;

use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** A deployed Laravel container app on a dply Postgres, with a user in the given role. */
function appFor(string $role): array
{
    config(['edge.cloudflare.account_id' => 'acc', 'edge.cloudflare.api_token' => 'tok']);
    $org = Organization::factory()->create();
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'meta' => ['edge' => [
            'runtime_mode' => 'container',
            'live_url' => 'https://shop.on-dply.live',
            'build' => ['framework' => 'laravel'],
            'database' => ['engine' => 'postgres', 'provider' => 'dply'],
            'container' => ['instance_type' => 'basic', 'max_instances' => 2],
            'placement' => ['location' => 'ord', 'region' => 'ENAM', 'rtt_ms' => 1.2, 'at' => 1],
        ]],
    ]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);

    return [$site, $user];
}

test('the app card and sheet show live instances, Cloudflare health and the size', function () {
    [$site, $user] = appFor('owner');
    $script = EdgeContainerDeployer::scriptName($site);
    Http::fake([
        'shop.on-dply.live/_dply/instances' => Http::response([
            ['name' => 'instance-0', 'status' => 'healthy', 'lastChange' => now()->subMinutes(3)->getTimestampMs()],
            ['name' => 'instance-1', 'status' => 'stopped', 'lastChange' => now()->subHour()->getTimestampMs()],
        ]),
        'api.cloudflare.com/client/v4/accounts/acc/containers/applications/app-1' => Http::response(['success' => true, 'result' => [
            'version' => 7, 'health' => ['instances' => ['active' => 1, 'healthy' => 1, 'failed' => 0]],
        ]]),
        'api.cloudflare.com/client/v4/accounts/acc/containers/applications' => Http::response(['success' => true, 'result' => [
            ['id' => 'app-1', 'name' => $script.'-app'], ['id' => 'other', 'name' => 'someone-else'],
        ]]),
        '*' => Http::response([], 500),
    ]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->assertSee('Loading instances')
        ->call('loadAppInstances')
        ->assertSet('appInstances.running', 1)
        ->assertSee('of 2 running')
        ->assertSee('ord')
        ->assertSee('instance-0')
        ->assertSee('Healthy')
        ->assertSee('instance-1')
        ->assertSee('1 active · 1 healthy')
        ->assertSee('· v7', false)
        ->assertSee('0.25 vCPU · 1 GiB');

    Http::assertSent(fn (Request $r): bool => $r->url() === 'https://shop.on-dply.live/_dply/instances' && $r->header('x-dply-queue-token') === [EdgeContainerDeployer::queueToken($site)]);
    Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'applications/other'));
});

test('an app that does not answer shows a friendly note, not an error', function () {
    [$site, $user] = appFor('owner');
    Http::fake(['*' => Http::response([], 500)]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('loadAppInstances')
        ->assertOk()
        ->assertSet('appInstances.instances', null)
        ->assertSee('The app did not answer, so live instance state is not available.');
});

test('check now measures the database round trip for editors only', function () {
    [$site, $user] = appFor('owner');
    Http::fake([
        'shop.on-dply.live/_dply/command' => Http::response(['ok' => true, 'location' => 'IAD', 'region' => 'ENAM', 'rtt_median_ms' => 0.8]),
        '*' => Http::response([], 500),
    ]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('checkAppPlacement')
        ->assertOk();
    expect($site->fresh()->edgeMeta()['placement'])->toMatchArray(['location' => 'iad', 'rtt_ms' => 0.8]);

    [$other, $viewer] = appFor('viewer');
    Http::fake(['*' => Http::response(['ok' => true, 'location' => 'x'])]);
    Livewire::actingAs($viewer)->test(Resources::class, ['server' => $other->server, 'site' => $other])
        ->call('checkAppPlacement')
        ->assertForbidden();
    Http::assertNotSent(fn (Request $r): bool => $r->url() === 'https://shop.on-dply.live/_dply/command' && $r->header('x-dply-queue-token') === [EdgeContainerDeployer::queueToken($other)]);
    expect($other->fresh()->edgeMeta()['placement']['location'])->toBe('ord');
});
