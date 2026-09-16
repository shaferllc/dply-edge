<?php

namespace Tests\Unit\EdgeEffectiveBindingsTest;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeBindingsAutoResolver;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Services\EdgeDplyResourceResolver;
use App\Modules\Edge\Services\EdgeRepoBindingTranslator;
use App\Modules\Edge\Services\EnsureDefaultEdgeBindings;
use App\Modules\Edge\Support\EdgeEffectiveBindings;

/**
 * The binding merge rules are all silent-failure modes: a wrong answer here does
 * not throw, it just ships (or drops) a binding nobody notices until a worker
 * reads `env.X` and finds undefined — or until Cloudflare rejects a script
 * upload because two bindings share a name and an otherwise-good deploy fails.
 */
function siteWithOverrides(array $overrides): Site
{
    $site = new Site;
    $site->forceFill(['meta' => ['edge' => ['bindings_overrides' => $overrides]]]);

    return $site;
}

function deploymentWithRepoBindings(array $bindings, ?Site $site = null): EdgeDeployment
{
    $deployment = new EdgeDeployment;
    $deployment->forceFill(['repo_config' => ['bindings' => $bindings]]);
    if ($site !== null) {
        $deployment->setRelation('site', $site);
    }

    return $deployment;
}

/** Translator with both Cloudflare-touching collaborators stubbed out. */
function translator(): EdgeRepoBindingTranslator
{
    $contexts = app(EdgeDeliveryContextResolver::class);

    $defaults = new class($contexts) extends EnsureDefaultEdgeBindings
    {
        public function ensure(Site $site, ?\Closure $log = null): array
        {
            return ['kv' => null];
        }
    };

    $resolver = new class($contexts) extends EdgeBindingsAutoResolver
    {
        public function resolve(Site $site, EdgeDeployment $deployment): array
        {
            // Pretend the repo values are already resolved CF identifiers.
            $config = is_array($deployment->repo_config) ? $deployment->repo_config : [];

            return is_array($config['bindings'] ?? null) ? $config['bindings'] : [];
        }
    };

    return new EdgeRepoBindingTranslator(
        $resolver,
        $defaults,
        app(EdgeDplyResourceResolver::class),
    );
}

test('dashboard overrides are normalized, deduped, and stripped of reserved names', function () {
    $site = siteWithOverrides([
        ['name' => 'SESSIONS', 'kind' => 'kv', 'value' => 'kv-1'],
        ['name' => 'HOST_MAP', 'kind' => 'kv', 'value' => 'evil'],   // reserved
        ['name' => 'SESSIONS', 'kind' => 'd1', 'value' => 'dup'],    // duplicate name
        ['name' => 'BAD', 'kind' => 'nonsense', 'value' => 'x'],     // unknown kind
        ['name' => '', 'kind' => 'kv', 'value' => 'y'],              // empty name
        ['name' => 'EMPTY', 'kind' => 'kv', 'value' => ''],          // empty value
    ]);

    $rows = EdgeEffectiveBindings::dashboardOverrides($site);

    expect(array_column($rows, 'name'))->toBe(['SESSIONS']);
    expect($rows[0]['value'])->toBe('kv-1');
    expect($rows[0]['source'])->toBe('dashboard');
});

test('every reserved platform name is rejected from dashboard overrides', function () {
    foreach (EdgeEffectiveBindings::RESERVED_NAMES as $reserved) {
        $site = siteWithOverrides([['name' => $reserved, 'kind' => 'kv', 'value' => 'x']]);

        expect(EdgeEffectiveBindings::dashboardOverrides($site))->toBe([]);
    }
});

test('repo bindings win a name collision against dashboard rows', function () {
    $site = siteWithOverrides([['name' => 'SESSIONS', 'kind' => 'kv', 'value' => 'dashboard-kv']]);
    $deployment = deploymentWithRepoBindings(['kv' => ['SESSIONS' => 'repo-kv']], $site);

    $effective = EdgeEffectiveBindings::for($site, $deployment);

    expect($effective)->toHaveCount(1);
    expect($effective[0]['value'])->toBe('repo-kv');
    expect($effective[0]['source'])->toBe('repo');
});

test('dashboard rows supplement repo bindings when names do not collide', function () {
    $site = siteWithOverrides([['name' => 'UPLOADS', 'kind' => 'r2', 'value' => 'bucket']]);
    $deployment = deploymentWithRepoBindings(['kv' => ['SESSIONS' => 'repo-kv']], $site);

    $effective = EdgeEffectiveBindings::for($site, $deployment);

    expect(array_column($effective, 'name'))->toBe(['SESSIONS', 'UPLOADS']);
    expect(array_column($effective, 'source'))->toBe(['repo', 'dashboard']);
});

test('the queues repo bucket is plural but the kind is singular', function () {
    // dply.yaml/wrangler declare `queues:`; the dashboard kind is `queue`.
    // Getting this mapping wrong silently hides every repo-declared queue.
    $site = siteWithOverrides([]);
    $deployment = deploymentWithRepoBindings(['queues' => ['JOBS' => 'my-queue']], $site);

    $effective = EdgeEffectiveBindings::for($site, $deployment);

    expect($effective)->toHaveCount(1);
    expect($effective[0])->toMatchArray(['name' => 'JOBS', 'kind' => 'queue', 'value' => 'my-queue']);
});

test('translator emits dashboard bindings so they actually reach the worker', function () {
    $site = siteWithOverrides([
        ['name' => 'SESSIONS', 'kind' => 'kv', 'value' => 'kv-1'],
        ['name' => 'UPLOADS', 'kind' => 'r2', 'value' => 'bucket-1'],
        ['name' => 'DB', 'kind' => 'd1', 'value' => 'uuid-1'],
        ['name' => 'JOBS', 'kind' => 'queue', 'value' => 'queue-1'],
    ]);
    $deployment = deploymentWithRepoBindings([], $site);

    $out = translator()->bindingsFor($deployment);

    expect(collect($out)->pluck('type', 'name')->all())->toBe([
        'SESSIONS' => 'kv_namespace',
        'UPLOADS' => 'r2_bucket',
        'DB' => 'd1',
        'JOBS' => 'queue',
    ]);
});

test('translator never emits a duplicate binding name on collision', function () {
    // Two bindings with the same name make Cloudflare reject the script upload,
    // which would fail an otherwise-successful deploy.
    $site = siteWithOverrides([['name' => 'SESSIONS', 'kind' => 'kv', 'value' => 'dashboard-kv']]);
    $deployment = deploymentWithRepoBindings(['kv' => ['SESSIONS' => 'repo-kv']], $site);

    $out = translator()->bindingsFor($deployment);
    $sessions = array_values(array_filter($out, fn (array $b): bool => $b['name'] === 'SESSIONS'));

    expect($sessions)->toHaveCount(1);
    expect($sessions[0]['namespace_id'])->toBe('repo-kv');
});

test('translator drops reserved names even if they reach meta', function () {
    $site = siteWithOverrides([['name' => 'ASSETS', 'kind' => 'r2', 'value' => 'hijack']]);
    $deployment = deploymentWithRepoBindings([], $site);

    expect(array_column(translator()->bindingsFor($deployment), 'name'))->not->toContain('ASSETS');
});
