<?php

declare(strict_types=1);

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Resources;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Support\EdgeContainerConnections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
});

/** A container app in its own organization, with an owner to act as. */
function vectorsPoolApp(array $edge = []): array
{
    // Vector search is paid-only; a comped org is on a paid plan.
    $org = Organization::factory()->create(['comped_until' => now()->addYear()]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => Server::factory()->create(['organization_id' => $org->id])->id,
        'edge_backend' => 'dply_edge',
        'type' => SiteType::Static,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['edge' => array_replace_recursive(['runtime_mode' => 'container', 'database' => ['engine' => 'sql']], $edge)],
    ]);
    $user = User::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $site->forceFill(['user_id' => $user->id])->save();
    $site->server->forceFill(['user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]])->save();

    return [$org, $site, $user];
}

test('vector indexes and pools are offered to attach only under the organization prefix', function () {
    [$org] = vectorsPoolApp();
    $mine = EdgeContainerConnections::ownedPrefix($org);
    Http::fake([
        '*/vectorize/v2/indexes' => Http::response(['success' => true, 'result' => [['name' => $mine.'docs'], ['name' => 'dply-someone-else-docs']]]),
        '*/hyperdrive/configs' => Http::response(['success' => true, 'result' => [['id' => str_repeat('a', 32), 'name' => $mine.'main'], ['id' => str_repeat('b', 32), 'name' => 'other']]]),
    ]);

    expect(EdgeContainerConnections::catalog('vectors', $org))->toBe([['id' => $mine.'docs', 'label' => 'docs']])
        ->and(EdgeContainerConnections::catalog('database_pool', $org))->toBe([['id' => str_repeat('a', 32), 'label' => 'main']]);
});

test('a vector index is created under the prefix with its size, and bad options are refused', function () {
    [$org] = vectorsPoolApp();
    $prefix = EdgeContainerConnections::ownedPrefix($org);
    Http::fake(['*/vectorize/v2/indexes' => Http::response(['success' => true, 'result' => ['name' => 'x']])]);

    expect(EdgeContainerConnections::provision('vectors', 'Docs', $org))->toBe($prefix.'docs');
    Http::assertSent(fn ($r): bool => ($r['name'] ?? null) === $prefix.'docs' && ($r['config'] ?? null) === ['dimensions' => 768, 'metric' => 'cosine']);

    expect(fn () => EdgeContainerConnections::provision('vectors', 'docs', $org, ['dimensions' => 7, 'metric' => 'cosine']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => EdgeContainerConnections::provision('vectors', 'docs', $org, ['metric' => 'manhattan']))->toThrow(InvalidArgumentException::class)
        ->and(fn () => EdgeContainerConnections::provision('vectors', str_repeat('a', 40), $org))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
});

test('only indexes and pools this organization created are deleted', function () {
    [$org] = vectorsPoolApp();
    $prefix = EdgeContainerConnections::ownedPrefix($org);
    $mine = str_repeat('c', 32);
    Http::fake(function ($request) use ($prefix, $mine) {
        return str_ends_with($request->url(), '/hyperdrive/configs/'.$mine) && $request->method() === 'GET'
            ? Http::response(['success' => true, 'result' => ['id' => $mine, 'name' => $prefix.'main']])
            : Http::response(['success' => true, 'result' => null]);
    });

    expect(EdgeContainerConnections::destroy('vectors', 'someone-elses-index', $org))->toBeFalse()
        ->and(EdgeContainerConnections::destroy('vectors', '../../d1/database/x', $org))->toBeFalse()
        ->and(EdgeContainerConnections::destroy('database_pool', '../x', $org))->toBeFalse();
    Http::assertNothingSent();

    expect(EdgeContainerConnections::destroy('vectors', $prefix.'docs', $org))->toBeTrue()
        ->and(EdgeContainerConnections::destroy('database_pool', $mine, $org))->toBeTrue();
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/vectorize/v2/indexes/'.$prefix.'docs'));
    Http::assertSent(fn ($r): bool => $r->method() === 'DELETE' && str_ends_with($r->url(), '/hyperdrive/configs/'.$mine));
});

test('the builder creates a vector index with the chosen size and hides workflows', function () {
    [$org, $site, $user] = vectorsPoolApp();
    $prefix = EdgeContainerConnections::ownedPrefix($org);
    Http::fake(['*/vectorize/v2/indexes' => Http::response(['success' => true, 'result' => ['name' => 'x']])]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openConnectionBuilder')
        ->assertDontSeeHtml("chooseConnectionKind('workflow')")
        ->assertSeeHtml("chooseConnectionKind('vectors')")
        ->call('chooseConnectionKind', 'vectors')
        ->assertSet('connectionMode', 'create')
        ->set('connectionLabel', 'Docs')
        ->set('vectorsDimensions', 1536)
        ->set('vectorsMetric', 'dot-product')
        ->call('saveConnection')
        ->assertHasNoErrors();

    Http::assertSent(fn ($r): bool => ($r['config'] ?? null) === ['dimensions' => 1536, 'metric' => 'dot-product']);
    expect(collect(EdgeContainerConnections::for($site->fresh()))->firstWhere('kind', 'vectors')['target'])->toBe($prefix.'docs');
});

test('a pool is created from this app\'s dply database or a pasted address', function () {
    [$org, $site, $user] = vectorsPoolApp(['database' => ['engine' => 'postgres', 'provider' => 'dply', 'remote_id' => 'pg-1', 'host' => 'pg-1.db.dply.io']]);
    (new EdgeSiteEnvVar(['site_id' => $site->id, 'key' => 'DB_PASSWORD', 'value' => 's3cret', 'scope' => EdgeSiteEnvVar::SCOPE_PRODUCTION]))->save();
    // GET is the attach list (loaded when the kind is picked); each POST creates the next pool.
    $ids = [str_repeat('d', 32), str_repeat('e', 32)];
    Http::fake(['*/hyperdrive/configs' => function ($r) use (&$ids) {
        return $r->method() === 'GET'
            ? Http::response(['success' => true, 'result' => []])
            : Http::response(['success' => true, 'result' => ['id' => array_shift($ids)]]);
    }]);

    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openConnectionBuilder')
        ->call('chooseConnectionKind', 'database_pool')
        ->set('connectionLabel', 'Pool')
        ->call('saveConnection')
        ->assertHasNoErrors();

    Http::assertSent(fn ($r): bool => ($r['origin'] ?? null) === ['host' => 'pg-1.db.dply.io', 'port' => 5432, 'database' => 'app', 'user' => 'app', 'password' => 's3cret', 'scheme' => 'postgres']);

    $component->call('openConnectionBuilder')
        ->call('chooseConnectionKind', 'database_pool')
        ->set('connectionLabel', 'Other')
        ->set('poolSource', 'url')
        ->set('poolOriginUrl', 'not a url')
        ->call('saveConnection')
        ->assertHasErrors('connection')
        ->set('poolOriginUrl', 'postgresql://bob:p%40ss@db.example.com/shop')
        ->call('saveConnection')
        ->assertHasNoErrors()
        ->assertSet('poolOriginUrl', '');

    Http::assertSent(fn ($r): bool => ($r['origin'] ?? null) === ['host' => 'db.example.com', 'port' => 5432, 'database' => 'shop', 'user' => 'bob', 'password' => 'p@ss', 'scheme' => 'postgres']);
    expect(collect(EdgeContainerConnections::for($site->fresh()))->where('kind', 'database_pool')->pluck('target')->all())
        ->toBe([str_repeat('d', 32), str_repeat('e', 32)]);
});

test('sheets never read an index or pool another organization owns', function () {
    [, $site, $user] = vectorsPoolApp(['connections' => [
        ['kind' => 'vectors', 'name' => 'SEARCH', 'host' => 'dply.app.search.internal', 'target' => 'someone-elses-index'],
        ['kind' => 'database_pool', 'name' => 'POOL', 'host' => 'dply.app.pool.internal', 'target' => 'typed-id'],
    ]]);
    Http::fake();

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openResource', 'dply.app.search.internal')
        ->assertDispatched('open-modal', 'resources-vectors')
        ->call('loadVectors')
        ->assertSet('vectorsInfo', null)
        ->assertNotSet('vectorsError', null)
        ->set('vectorsQuery', '[0.1, 0.2]')
        ->call('runVectorsQuery')
        ->assertSet('vectorsMatches', null)
        ->call('openResource', 'dply.app.pool.internal')
        ->assertDispatched('open-modal', 'resources-database-pool')
        ->call('loadPool')
        ->assertSet('poolInfo', null)
        ->assertNotSet('poolError', null);

    Http::assertNothingSent();
});

test('the vectors sheet shows the index and runs a query; the pool sheet masks the host', function () {
    [$org, $site, $user] = vectorsPoolApp();
    $prefix = EdgeContainerConnections::ownedPrefix($org);
    $poolId = str_repeat('f', 32);
    $site->mergeEdgeMeta(['connections' => [
        ['kind' => 'vectors', 'name' => 'SEARCH', 'host' => 'dply.app.search.internal', 'target' => $prefix.'docs'],
        ['kind' => 'database_pool', 'name' => 'POOL', 'host' => 'dply.app.pool.internal', 'target' => $poolId],
    ]]);
    $site->save();
    Http::fake([
        '*/indexes/'.$prefix.'docs/info' => Http::response(['success' => true, 'result' => ['dimensions' => 3, 'vectorCount' => 42]]),
        '*/indexes/'.$prefix.'docs/query' => Http::response(['success' => true, 'result' => ['count' => 1, 'matches' => [['id' => 'doc-1', 'score' => 0.91, 'metadata' => ['title' => 'Hi']]]]]),
        '*/indexes/'.$prefix.'docs' => Http::response(['success' => true, 'result' => ['name' => $prefix.'docs', 'config' => ['dimensions' => 3, 'metric' => 'cosine']]]),
        '*/hyperdrive/configs/'.$poolId => Http::response(['success' => true, 'result' => ['id' => $poolId, 'name' => $prefix.'main', 'origin' => ['host' => 'pg-1.db.dply.io', 'database' => 'app', 'user' => 'app', 'scheme' => 'postgres'], 'caching' => ['disabled' => true]]]),
    ]);

    Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openResource', 'dply.app.search.internal')
        ->call('loadVectors')
        ->assertSet('vectorsInfo', ['dimensions' => 3, 'metric' => 'cosine', 'count' => 42])
        ->set('vectorsQuery', '[0.1, 0.2]')
        ->call('runVectorsQuery')
        ->assertHasErrors('vectorsQuery')
        ->set('vectorsQuery', '[0.1, 0.2, 0.3]')
        ->set('vectorsTopK', 500)
        ->call('runVectorsQuery')
        ->assertHasNoErrors()
        ->assertSet('vectorsTopK', 50)
        ->assertSet('vectorsMatches', [['id' => 'doc-1', 'score' => 0.91, 'metadata' => '{"title":"Hi"}']])
        ->assertSee('doc-1')
        ->call('openResource', 'dply.app.pool.internal')
        ->call('loadPool')
        ->assertSet('poolInfo.host', '••••.db.dply.io')
        ->assertSet('poolInfo.caching', false)
        ->assertDontSee('pg-1.db.dply.io');
});

test('attach cannot be pointed at an id the catalog did not offer', function () {
    [, $site, $user] = vectorsPoolApp();
    Http::fake(['*/hyperdrive/configs' => Http::response(['success' => true, 'result' => []])]);

    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site])
        ->call('openConnectionBuilder')
        ->call('chooseConnectionKind', 'database_pool')
        ->call('setConnectionMode', 'attach');

    expect(fn () => $component->set('connectionOptions', [['id' => str_repeat('9', 32), 'label' => 'theirs']]))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    $component->set('connectionPick', str_repeat('9', 32))->call('saveConnection')->assertHasErrors('connection');

    expect(EdgeContainerConnections::for($site->fresh()))->toBe([]);
});

test('a pool whose config is already gone is detached, not left stuck', function () {
    [$org] = vectorsPoolApp();
    Http::fake(['*' => Http::response(['success' => false, 'errors' => [['message' => 'not found']]], 404)]);

    expect(EdgeContainerConnections::destroy('database_pool', str_repeat('a', 32), $org))->toBeFalse();
    Http::assertNotSent(fn ($r): bool => $r->method() === 'DELETE');
});

test('a vector index gets a REST token, kept encrypted, injected on deploy and rotatable from the sheet', function () {
    [$org, $site, $user] = vectorsPoolApp(['connections' => [
        ['kind' => 'vectors', 'name' => 'DOCS', 'host' => 'docs.app.internal', 'target' => 'dply-x-docs'],
        ['kind' => 'vectors', 'name' => 'OLD', 'host' => 'old.app.internal', 'target' => 'dply-x-old', 'asleep' => true],
    ]]);

    $secrets = EdgeContainerConnections::vectorRestSecrets($site);
    $token = $secrets['DPLY_VECTOR_TOKEN_DOCS'];
    expect($token)->toStartWith('dvx_')
        ->and($secrets)->not->toHaveKey('DPLY_VECTOR_TOKEN_OLD')
        ->and($secrets['VECTOR_REST_TOKEN'])->toBe($token)
        ->and($secrets['VECTOR_REST_URL'])->toEndWith('/_vector/DOCS')
        // Stored as one encrypted blob (rotated by secrets:reencrypt), and the same token on the next deploy.
        ->and($site->fresh()->edgeMeta()['vector_rest_tokens'])->toBeString()->not->toContain($token)
        ->and(EdgeContainerConnections::vectorRestSecrets($site->fresh())['DPLY_VECTOR_TOKEN_DOCS'])->toBe($token);

    $component = Livewire::actingAs($user)->test(Resources::class, ['server' => $site->server, 'site' => $site->fresh()])->set('resourceHost', 'docs.app.internal');
    expect($component->instance()->vectorRestToken())->toBe($token);
    $rotated = $component->instance()->rotateVectorRestToken();
    expect($rotated)->toStartWith('dvx_')->not->toBe($token)
        ->and(EdgeContainerConnections::vectorRestToken($site->fresh(), 'DOCS', false))->toBe($rotated);
});
