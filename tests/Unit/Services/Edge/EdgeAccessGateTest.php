<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge;

use App\Models\EdgeDeployment;
use App\Models\EdgeSiteAccessRule;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeAccessGate;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('sync stores password verifier and publishes the access gate on the live hostname', function () {
    config(['edge.fake.enabled' => true]);

    [$site, $deployment] = scaffoldEdgeSiteWithLiveDeploy();

    $rule = app(EdgeAccessGate::class)->sync($site, EdgeSiteAccessRule::MODE_PASSWORD, 'secret-preview');

    expect($rule->mode)->toBe(EdgeSiteAccessRule::MODE_PASSWORD);
    expect($rule->password_salt)->not->toBeEmpty();
    expect($rule->password_verifier)->toBe(hash('sha256', $rule->password_salt.'secret-preview'));

    $map = Cache::get('edge:fake:host-map', []);
    $primary = $map[strtolower($site->edgeHostname())] ?? null;
    $alias = collect($map)->first(fn (array $entry, string $host): bool => ($entry['is_production'] ?? null) === false);

    expect($primary['is_production'] ?? null)->toBeTrue();
    expect($primary['access_gate']['mode'] ?? null)->toBe('password');
    expect($alias)->not->toBeNull();
    expect($alias['access_gate']['mode'] ?? null)->toBe('password');
    expect($alias['access_gate']['password_verifier'] ?? null)->toBe($rule->password_verifier);
});

test('sync off clears rule and removes access gate from kv', function () {
    config(['edge.fake.enabled' => true]);

    [$site] = scaffoldEdgeSiteWithLiveDeploy();
    app(EdgeAccessGate::class)->sync($site, EdgeSiteAccessRule::MODE_PASSWORD, 'secret-preview');

    app(EdgeAccessGate::class)->sync($site, EdgeSiteAccessRule::MODE_OFF);

    $rule = EdgeSiteAccessRule::query()->where('site_id', $site->id)->first();
    expect($rule)->not->toBeNull();
    expect($rule->mode)->toBe(EdgeSiteAccessRule::MODE_OFF);
    expect($rule->password_verifier)->toBeNull();

    $map = Cache::get('edge:fake:host-map', []);
    $alias = collect($map)->first(fn (array $entry): bool => ($entry['is_production'] ?? null) === false);
    expect($alias['access_gate'] ?? null)->toBeNull();
});

test('dply account mode includes allowed emails in kv payload', function () {
    config(['edge.fake.enabled' => true]);

    [$site] = scaffoldEdgeSiteWithLiveDeploy();

    app(EdgeAccessGate::class)->sync(
        $site,
        EdgeSiteAccessRule::MODE_DPLY_ACCOUNT,
        null,
        ['Reviewer@Example.com', 'pm@example.com'],
    );

    $map = Cache::get('edge:fake:host-map', []);
    $alias = collect($map)->first(fn (array $entry): bool => ($entry['is_production'] ?? null) === false);

    expect($alias['access_gate']['mode'] ?? null)->toBe('dply_account');
    expect($alias['access_gate']['allowed_emails'] ?? null)->toBe(['reviewer@example.com', 'pm@example.com']);
});

test('preview child sites inherit parent gate rule', function () {
    [$parent] = scaffoldEdgeSiteWithLiveDeploy();
    app(EdgeAccessGate::class)->sync($parent, EdgeSiteAccessRule::MODE_PASSWORD, 'inherit-me');

    $preview = Site::factory()->create([
        'organization_id' => $parent->organization_id,
        'server_id' => $parent->server_id,
        'edge_backend' => $parent->edge_backend,
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'preview_parent_site_id' => (string) $parent->id,
                'live_url' => 'https://preview-child.dply.host',
            ],
        ],
    ]);

    $rule = app(EdgeAccessGate::class)->ruleForSite($preview);
    expect($rule)->not->toBeNull();
    expect($rule->mode)->toBe(EdgeSiteAccessRule::MODE_PASSWORD);
});

test('cannot configure preview protection on preview child site', function () {
    [$parent] = scaffoldEdgeSiteWithLiveDeploy();
    $preview = Site::factory()->create([
        'organization_id' => $parent->organization_id,
        'server_id' => $parent->server_id,
        'edge_backend' => $parent->edge_backend,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => ['preview_parent_site_id' => (string) $parent->id],
        ],
    ]);

    app(EdgeAccessGate::class)->sync($preview, EdgeSiteAccessRule::MODE_PASSWORD, 'nope');
})->throws(ValidationException::class);

/**
 * @return array{0: Site, 1: EdgeDeployment}
 */
function scaffoldEdgeSiteWithLiveDeploy(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'live_url' => 'https://edge-app.dply.host',
                'routing' => ['hostname' => 'edge-app.dply.host'],
            ],
        ],
    ]);

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/site/deploy/',
        'git_branch' => 'main',
        'published_at' => now(),
        'aliases' => ['deploy-alias.dply.host'],
    ]);

    $site->mergeEdgeMeta(['active_deployment_id' => (string) $deployment->id]);
    $site->save();

    app(EdgeHostMapPublisher::class)->publish($site->fresh(), $deployment);

    return [$site->fresh(), $deployment];
}
