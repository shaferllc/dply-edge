<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeResourceGatesTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Jobs;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeDatabase;
use App\Models\EdgeDeployment;
use App\Models\EdgeQueue;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Edge\Services\Containers\EdgeContainerDeployer;
use App\Modules\Edge\Services\EdgeBindingsAutoResolver;
use App\Modules\Edge\Services\EdgeDashboardBindingProvisioner;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

/*
 * Every organization shares dply's one Cloudflare account (security audit C1,
 * C2, M4): a site may only bind its own organization's resources, creation
 * goes through the prefix and the plan's limits, and the unmetered kinds
 * (AI, Browser, Images, vectors) need a paid plan past its trial.
 */

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
});

/** @return array{0: Organization, 1: Site, 2: User} */
function gatedApp(string $runtime = 'ssr', array $org = []): array
{
    $organization = Organization::factory()->create($org);
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'organization_id' => $organization->id, 'server_id' => $server->id, 'user_id' => $user->id, 'type' => SiteType::Static,
        'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => ['runtime_mode' => $runtime]],
    ]);

    return [$organization, $site->fresh(), $user];
}

function paid(): array
{
    return ['comped_until' => now()->addYear()];
}

/**
 * Cloudflare with one foreign KV namespace and bucket, and creates that echo
 * the name back.
 */
function fakeAccount(): void
{
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            $request->method() === 'GET' && str_ends_with($url, '/storage/kv/namespaces/'.str_repeat('a', 32)) => Http::response(['success' => true, 'result' => ['id' => str_repeat('a', 32), 'title' => 'platform-host-map']]),
            $request->method() === 'GET' && str_contains($url, '/storage/kv/namespaces') => Http::response(['success' => true, 'result' => [['id' => str_repeat('a', 32), 'title' => 'cache']]]),
            $request->method() === 'POST' && str_contains($url, '/storage/kv/namespaces') => Http::response(['success' => true, 'result' => ['id' => str_repeat('b', 32), 'title' => $request['title']]]),
            $request->method() === 'GET' && str_ends_with($url, '/r2/buckets') => Http::response(['success' => true, 'result' => ['buckets' => [['name' => 'artifacts']]]]),
            $request->method() === 'POST' && str_ends_with($url, '/r2/buckets') => Http::response(['success' => true, 'result' => ['name' => $request['name']]]),
            $request->method() === 'GET' && str_ends_with($url, '/d1/database') => Http::response(['success' => true, 'result' => []]),
            $request->method() === 'POST' && str_ends_with($url, '/d1/database') => Http::response(['success' => true, 'result' => ['uuid' => 'd1-new', 'name' => $request['name']]]),
            $request->method() === 'GET' && str_ends_with($url, '/queues') => Http::response(['success' => true, 'result' => []]),
            $request->method() === 'POST' && str_ends_with($url, '/queues') => Http::response(['success' => true, 'result' => ['queue_id' => 'q-new', 'queue_name' => $request['queue_name']]]),
            default => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404),
        };
    });
}

function repoDeployment(Site $site, array $bindings): EdgeDeployment
{
    $deployment = new EdgeDeployment;
    $deployment->forceFill(['repo_config' => ['bindings' => $bindings]]);
    $deployment->setRelation('site', $site);

    return $deployment;
}

test('attach refuses another organization\'s bucket, queue, database and namespace', function () {
    [$org, $site] = gatedApp();
    [$other] = gatedApp();
    fakeAccount();
    EdgeDatabase::query()->create(['organization_id' => $other->id, 'name' => 'theirs', 'cloudflare_id' => 'their-uuid']);
    $prefix = EdgeContainerConnections::ownedPrefix($org);

    expect(EdgeContainerConnections::attach($site, 'object_storage', 'UPLOADS', 'artifacts'))->toContain('does not belong')
        ->and(EdgeContainerConnections::attach($site, 'object_storage', 'THEIRS', EdgeContainerConnections::ownedPrefix($other).'uploads'))->toContain('does not belong')
        ->and(EdgeContainerConnections::attach($site, 'queue', 'JOBS', 'shared-jobs'))->toContain('does not belong')
        ->and(EdgeContainerConnections::attach($site, 'sql', 'DB', 'their-uuid'))->toContain('does not belong')
        ->and(EdgeContainerConnections::attach($site, 'key_value', 'HOSTS', str_repeat('a', 32)))->toContain('does not belong')
        ->and(EdgeContainerConnections::for($site->fresh()))->toBe([])
        // Our own, and kinds that are not in the account, still attach.
        ->and(EdgeContainerConnections::attach($site, 'object_storage', 'UPLOADS', $prefix.'uploads'))->toBeNull()
        ->and(EdgeContainerConnections::attach($site, 'durable_object', 'STATE', ''))->toBeNull();
});

test('a repo binding name resolves inside the organization prefix and is created there', function () {
    [$org, $site] = gatedApp(org: paid());
    fakeAccount();
    $prefix = EdgeContainerConnections::ownedPrefix($org);

    $resolved = app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, [
        'kv' => ['CACHE' => 'cache'],
        'r2' => ['FILES' => 'artifacts'],
        'queues' => ['JOBS' => 'jobs'],
    ]));

    // "cache" and "artifacts" exist unprefixed in the account; neither is adopted.
    expect($resolved)->toBe([
        'kv' => ['CACHE' => str_repeat('b', 32)],
        'r2' => ['FILES' => $prefix.'artifacts'],
        'queues' => ['JOBS' => $prefix.'jobs'],
    ]);
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/storage/kv/namespaces') && $r['title'] === $prefix.'cache');
    expect(EdgeQueue::query()->where('organization_id', $org->id)->where('cloudflare_name', $prefix.'jobs')->exists())->toBeTrue();
});

test('the next deploy finds what the last one created, past the first page of the account', function () {
    [$org, $site] = gatedApp(org: paid());
    $prefix = EdgeContainerConnections::ownedPrefix($org);
    EdgeDatabase::query()->create(['organization_id' => $org->id, 'name' => 'main', 'cloudflare_id' => 'd1-main']);
    Http::fake(function (Request $request) use ($prefix) {
        $url = $request->url();
        if ($request->method() === 'POST') {
            return Http::response(['success' => false, 'errors' => [['message' => 'A resource with that name already exists.']]], 400);
        }
        if (str_contains($url, '/storage/kv/namespaces')) {
            // Other organizations fill page one; ours is on page two.
            return str_contains($url, 'page=2')
                ? Http::response(['success' => true, 'result' => [['id' => str_repeat('c', 32), 'title' => $prefix.'cache']]])
                : Http::response(['success' => true, 'result' => array_map(fn (int $i): array => ['id' => sprintf('%032x', $i), 'title' => 'other-'.$i], range(1, 100))]);
        }

        return Http::response(['success' => true, 'result' => str_ends_with($url, '/r2/buckets') ? ['buckets' => []] : []]);
    });

    // "Main" as the repo spells it; the row stores it lowercase.
    expect(app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, [
        'kv' => ['CACHE' => 'cache'],
        'd1' => ['DB' => 'Main'],
        'r2' => ['FILES' => 'files'],
    ])))->toBe([
        'kv' => ['CACHE' => str_repeat('c', 32)],
        'r2' => ['FILES' => $prefix.'files'],
        'd1' => ['DB' => 'd1-main'],
    ]);
});

test('a repo binding to a foreign id fails the deploy naming the binding', function () {
    [, $site] = gatedApp(org: paid());
    fakeAccount();

    expect(fn () => app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, ['kv' => ['HOSTS' => str_repeat('a', 32)]])))
        ->toThrow(\RuntimeException::class, 'wrangler.toml binding HOSTS');
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

test('auto_create false means the target must already be ours', function () {
    [$org, $site] = gatedApp();
    fakeAccount();
    $prefix = EdgeContainerConnections::ownedPrefix($org);

    expect(fn () => app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, ['auto_create' => false, 'r2' => ['FILES' => 'artifacts']])))
        ->toThrow(\RuntimeException::class, 'wrangler.toml binding FILES');
    expect(app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, ['auto_create' => false, 'r2' => ['FILES' => $prefix.'files']])))
        ->toBe(['r2' => ['FILES' => $prefix.'files']]);
});

test('the Jobs page creates under the prefix and never adopts or attaches a foreign resource', function () {
    [$org, $site, $user] = gatedApp(org: paid());
    fakeAccount();
    $prefix = EdgeContainerConnections::ownedPrefix($org);

    // An unprefixed "cache" exists; creating "cache" makes ours instead.
    expect(app(EdgeDashboardBindingProvisioner::class)->create($site, 'kv', 'cache'))->toBe(str_repeat('b', 32));
    Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r['title'] === $prefix.'cache');

    Livewire::actingAs($user)->test(Jobs::class, ['server' => $site->server, 'site' => $site])
        ->set('new_name', 'FILES')->set('new_kind', 'r2')->set('new_value', 'artifacts')->set('create_resource', false)
        ->call('addBinding')
        ->assertHasErrors('new_value');

    expect(EdgeContainerConnections::for($site->fresh()))->toBe([]);
});

test('plan limits apply to every path that creates a database or queue', function () {
    config(['subscription.standard.tiers.team.databases' => 1, 'subscription.standard.tiers.team.queues' => 1]);
    [$org, $site, $user] = gatedApp(org: paid());
    fakeAccount();
    EdgeDatabase::query()->create(['organization_id' => $org->id, 'name' => 'main', 'cloudflare_id' => 'd1-main']);
    EdgeQueue::query()->create(['organization_id' => $org->id, 'name' => 'jobs', 'cloudflare_id' => 'q-1', 'cloudflare_name' => EdgeContainerConnections::ownedPrefix($org).'jobs']);

    expect(fn () => EdgeContainerConnections::provision('sql', 'more', $org))->toThrow(\RuntimeException::class, 'includes 1 databases')
        ->and(fn () => EdgeContainerConnections::provision('queue', 'more', $org))->toThrow(\RuntimeException::class, 'includes 1 queues')
        ->and(fn () => app(EdgeDashboardBindingProvisioner::class)->create($site, 'd1', 'more'))->toThrow(\RuntimeException::class, 'includes 1 databases')
        ->and(fn () => app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, ['queues' => ['MORE' => 'more']])))->toThrow(\RuntimeException::class, 'includes 1 queues');

    // An existing one is reused, not counted again.
    expect(app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, ['d1' => ['DB' => 'main'], 'queues' => ['JOBS' => 'jobs']])))
        ->toBe(['d1' => ['DB' => 'd1-main'], 'queues' => ['JOBS' => EdgeContainerConnections::ownedPrefix($org).'jobs']]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('chooseConnectionKind', 'sql')
        ->set('connectionLabel', 'more')
        ->call('saveConnection')
        ->assertHasErrors('connection');
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

test('a key-value store needs a card on every path', function () {
    [$org, $site] = gatedApp(); // card-less trial
    fakeAccount();

    expect(fn () => EdgeContainerConnections::provision('key_value', 'cache', $org))->toThrow(\RuntimeException::class, 'Add a card')
        ->and(fn () => app(EdgeDashboardBindingProvisioner::class)->create($site, 'kv', 'cache'))->toThrow(\RuntimeException::class, 'Add a card');
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST');
});

test('AI, Browser, Images and vectors are refused on a trial and allowed on a paid plan', function () {
    [$trial, $site, $user] = gatedApp('container');
    fakeAccount();
    $site->mergeEdgeMeta(['browser' => true, 'connections' => [
        ['kind' => 'ai', 'name' => 'AI', 'host' => 'ai.internal', 'target' => ''],
        ['kind' => 'images', 'name' => 'IMAGES', 'host' => 'images.internal', 'target' => ''],
    ]]);
    $site->save();

    expect(EdgeContainerConnections::attach($site, 'ai', 'AI2', ''))->toContain('paid plan')
        ->and(fn () => EdgeContainerConnections::provision('vectors', 'docs', $trial, ['dimensions' => 768, 'metric' => 'cosine']))->toThrow(\RuntimeException::class, 'paid plan')
        // Rows saved earlier do not reach the deploy.
        ->and(EdgeContainerConnections::browserEnabled($site))->toBeFalse()
        ->and(EdgeContainerConnections::mergeWrangler([], $site))->not->toHaveKeys(['ai', 'images', 'browser'])
        ->and(EdgeContainerConnections::workerBindings($site))->toBe([]);

    $site->mergeEdgeMeta(['browser' => null, 'connections' => []]);
    $site->save();
    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('chooseConnectionKind', 'ai')
        ->call('enableBrowser');
    expect(EdgeContainerConnections::for($site->fresh()))->toBe([])
        ->and($site->fresh()->edgeMeta()['browser'] ?? null)->toBeNull();

    $trial->forceFill(paid())->save();
    $site = $site->fresh();
    expect(EdgeContainerConnections::attach($site, 'ai', 'AI', ''))->toBeNull()
        ->and(EdgeContainerConnections::mergeWrangler([], $site->fresh()))->toHaveKey('ai');
});

test('a card trial on Stripe does not get the paid-only kinds', function () {
    config(['subscription.standard.stripe.tier_pro' => 'price_tier_pro']);
    [$org] = gatedApp(org: ['trial_ends_at' => null]);
    Subscription::factory()->withPrice('price_tier_pro')->create(['organization_id' => $org->id, 'stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(5)]);

    expect(EdgeContainerConnections::paidFeatures($org->fresh()))->toBeFalse()
        ->and(EdgeContainerConnections::cardOnFile($org->fresh()))->toBeTrue();
});

test('a container repo cannot bind another organization\'s queue', function () {
    [, $site] = gatedApp('container');
    $deployment = EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $site->organization_id, 'status' => EdgeDeployment::STATUS_LIVE, 'storage_prefix' => 'edge/t/1', 'repo_config' => ['bindings' => ['queues' => ['JOBS' => 'shared-jobs']]]]);

    $queueBindings = (new \ReflectionClass(EdgeContainerDeployer::class))->getMethod('queueBindings');

    expect(fn () => $queueBindings->invoke(app(EdgeContainerDeployer::class), $site, $deployment))
        ->toThrow(\RuntimeException::class, 'wrangler.toml binding JOBS');
});

test('an app that already deployed an unprefixed binding fails loudly instead of getting an empty one', function () {
    [$org, $site] = gatedApp(org: paid());
    fakeAccount();
    // Before resources were prefixed, this site deployed "cache" (which exists unprefixed).
    EdgeDeployment::query()->create(['site_id' => $site->id, 'organization_id' => $org->id, 'status' => EdgeDeployment::STATUS_SUPERSEDED, 'storage_prefix' => 'edge/old', 'repo_config' => ['bindings' => ['kv' => ['CACHE' => 'cache']]]]);

    expect(fn () => app(EdgeBindingsAutoResolver::class)->resolve($site, repoDeployment($site, ['kv' => ['CACHE' => 'cache']])))
        ->toThrow(\RuntimeException::class, 'Ask support to move it');
    Http::assertNotSent(fn (Request $r): bool => $r->method() === 'POST' && str_contains($r->url(), '/storage/kv/namespaces'));
});

test('a flagged resource kind can only be added once its feature flag is on for the organization', function () {
    [$org, $site, $user] = gatedApp('container');
    $org->forceFill(paid())->save();
    fakeAccount();
    $flag = EdgeContainerConnections::flag('images');
    // TestCase turns the flags on; the real default is off.
    Feature::define($flag, static fn (): bool => false);

    $page = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site->fresh()])
        ->call('openConnectionBuilder')
        ->assertDontSeeHtml("chooseConnectionKind('images')")
        ->call('chooseConnectionKind', 'images');
    expect(EdgeContainerConnections::for($site->fresh()))->toBe([]);

    Feature::for($org->fresh())->activate($flag);
    $page->call('openConnectionBuilder')
        ->assertSeeHtml("chooseConnectionKind('images')")
        ->call('chooseConnectionKind', 'images');
    expect(collect(EdgeContainerConnections::for($site->fresh()))->pluck('kind')->all())->toBe(['images']);
});
