<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Build;
use App\Livewire\Sites\Edge\Workspace\Delivery;
use App\Livewire\Sites\Edge\Workspace\Deploys;
use App\Livewire\Sites\Edge\Workspace\OverviewObservability;
use App\Livewire\Sites\EdgeSettings;
use App\Models\AuditLog;
use App\Models\EdgeDeployment;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Support\Sites\SiteWorkspaceBreadcrumbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('edge site workspace route renders full app layout shell', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-deploys']))
        ->assertOk()
        ->assertSee('Deploy history', false)
        ->assertSee('Infrastructure control for teams that ship', false);
});

test('edge site settings sidebar shows edge sections not byo runtime', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Overview')
        ->assertSee('Deploys')
        ->assertSee('Build')
        ->assertSee('Environment')
        ->assertSee('Deploy triggers')
        ->assertSee('Delivery')
        ->assertSee('Domains')
        ->assertSee('Billing & usage')
        ->assertSee('Traffic & analytics')
        ->assertSee('Build & deploy logs')
        ->assertSee('Back to Edge sites')
        ->assertDontSee('System user')
        ->assertDontSee('Certificates');
});

test('edge overview shows live url redeploy and no nginx references', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Edge App')
        ->assertSee('https://edge-app.dply.host')
        ->assertSee('Redeploy')
        ->assertSee('Open live site')
        ->assertSee('acme/web')
        ->assertSee('Deploy history')
        ->assertDontSee('nginx')
        ->assertDontSee('Webserver')
        ->assertDontSee('PHP-FPM');
});

test('edge breadcrumbs use edge not servers or infrastructure path', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $labels = array_column(
        SiteWorkspaceBreadcrumbs::items($server, $site, __('Overview')),
        'label'
    );

    expect($labels)
        ->toContain(__('Edge'))
        ->not->toContain(__('Infrastructure'))
        ->not->toContain(__('Servers'));

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']))
        ->assertOk()
        ->assertSee('Edge')
        ->assertSee('Edge site workspace');
});

test('edge deploys section renders deploy history table', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'status' => EdgeDeployment::STATUS_SUPERSEDED,
        'storage_prefix' => 'edge/test/older-prefix',
        'published_at' => now()->subHour(),
    ]);

    Livewire::actingAs($user)
        ->test(Deploys::class, ['server' => $server, 'site' => $site])
        ->assertSee('Deploy history')
        ->assertSee('Roll back');
});

test('edge deploys section refreshes after a git provider is linked', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings(withGithub: false);

    Livewire::actingAs($user)
        ->test(Deploys::class, ['server' => $server, 'site' => $site])
        ->assertSee('Connect GitHub to deploy a specific commit, branch tip, or tag.', false)
        ->assertDontSee('id="edge_deploy_commit_sha"', false);

    $user->socialAccounts()->create([
        'provider' => 'github',
        'provider_id' => '54321',
        'nickname' => 'edge-dev',
        'access_token' => 'gh-test-token',
    ]);

    Livewire::actingAs($user)
        ->test(Deploys::class, ['server' => $server, 'site' => $site])
        ->dispatch('source-control-linked')
        ->assertDontSee('Connect GitHub to deploy a specific commit, branch tip, or tag.', false)
        ->assertSee('id="edge_deploy_commit_sha"', false);
});

test('edge danger section shows delete edge site not nginx teardown', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'danger'])
        ->assertSee('Delete Edge site')
        ->assertDontSee('Nginx vhost')
        ->assertDontSee('Suspend public site');
});

test('edge billing section shows usage stats and org analytics link', function () {
    config(['dply.edge.usage_billing.enabled' => true]);

    [$user, $server, $site] = makeEdgeSiteForSettings();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => 42_000,
        'bytes_egress' => 512 * 1024 * 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-billing'])
        ->assertSee('Billing & usage')
        ->assertSee('Platform fee')
        ->assertSee('42,000')
        ->assertSee('Open billing analytics');

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'edge-billing']))
        ->assertOk()
        ->assertSee(route('billing.analytics', $site->organization_id), false);
});

test('edge traffic section shows request and bandwidth stats', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    EdgeUsageSnapshot::query()->create([
        'organization_id' => $site->organization_id,
        'site_id' => $site->id,
        'period_start' => now()->toDateString(),
        'period_end' => now()->toDateString(),
        'requests' => 12_500,
        'bytes_egress' => 256 * 1024 * 1024,
        'r2_storage_bytes' => 0,
        'r2_class_a_ops' => 0,
        'r2_class_b_ops' => 0,
        'source' => 'manual',
    ]);

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-traffic'])
        ->assertSee('Traffic & analytics')
        ->assertSee('Requests MTD')
        ->assertSee('Requests 7d')
        ->assertSee('12,500')
        ->assertSee('Performance')
        ->assertSee('Core Web Vitals')
        ->assertSee('Live requests');
});

test('edge logs section clarifies build logs vs visitor traffic', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'edge-logs'])
        ->assertSee('Build & deploy logs')
        ->assertSee('not visitor HTTP logs')
        ->assertSee('Recent deploys')
        ->assertSee('are under Traffic')
        ->assertDontSee('Streaming access logs in real time.');
});

test('edge build settings can be updated on build settings section', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(Build::class, ['server' => $server, 'site' => $site])
        ->set('buildForm.edge_build_command', 'pnpm install && pnpm build')
        ->set('buildForm.edge_output_dir', 'out')
        ->set('buildForm.edge_spa_fallback', false)
        ->set('buildForm.edge_deploy_on_push', false)
        ->call('saveEdgeBuildSettings')
        ->assertHasNoErrors();

    $site->refresh();
    $edge = $site->edgeMeta();

    expect($edge['build']['command'] ?? null)->toBe('pnpm install && pnpm build')
        ->and($edge['build']['output_dir'] ?? null)->toBe('out')
        ->and($edge['routing']['spa_fallback'] ?? null)->toBeFalse()
        ->and($edge['source']['deploy_on_push'] ?? null)->toBeFalse();
});

test('edge deploy ref picker loads branches tags and commits from git provider', function () {
    Http::fake([
        'api.github.com/repos/acme/web' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/web/branches*' => Http::response([
            ['name' => 'main', 'commit' => ['sha' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']],
            ['name' => 'develop', 'commit' => ['sha' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb']],
        ]),
        'api.github.com/repos/acme/web/tags*' => Http::response([
            ['name' => 'v1.0.0', 'commit' => ['sha' => 'cccccccccccccccccccccccccccccccccccccccc']],
        ]),
        'api.github.com/repos/acme/web/commits*' => Http::response([
            [
                'sha' => 'dddddddddddddddddddddddddddddddddddddddd',
                'commit' => [
                    'message' => 'Fix homepage hero',
                    'author' => ['name' => 'Dev', 'email' => 'dev@example.com'],
                    'committer' => ['date' => now()->toIso8601String()],
                ],
                'html_url' => 'https://github.com/acme/web/commit/dddddddd',
            ],
        ]),
    ]);

    [$user, $server, $site] = makeEdgeSiteForSettings(withGithub: true);

    Livewire::actingAs($user)
        ->test(Deploys::class, ['server' => $server, 'site' => $site])
        ->call('openEdgeDeployRefPicker')
        ->assertSet('edge_deploy_ref_picker_open', true)
        ->assertSee('Fix homepage hero')
        ->call('setEdgeDeployRefTab', 'branches')
        ->assertSee('develop')
        ->call('setEdgeDeployRefTab', 'tags')
        ->assertSee('v1.0.0')
        ->call('selectEdgeDeployRef', 'cccccccccccccccccccccccccccccccccccccccc')
        ->assertSet('edge_deploy_commit_sha', 'cccccccccccccccccccccccccccccccccccccccc')
        ->assertSet('edge_deploy_ref_picker_open', false);
});

test('hybrid origin url and routes can be edited from build settings', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings(hybrid: true);

    Livewire::actingAs($user)
        ->test(Delivery::class, ['server' => $server, 'site' => $site])
        ->assertSet('buildForm.edge_origin_url', 'https://origin.example.com')
        ->assertSet('buildForm.edge_origin_routes', "/api/*\n/_next/data/*")
        ->set('buildForm.edge_origin_url', 'https://new-origin.example.com')
        ->set('buildForm.edge_origin_routes', "/api/*\n/graphql\n/webhook/*")
        ->call('saveEdgeHybridOrigin')
        ->assertHasNoErrors();

    $site->refresh();
    $origin = $site->edgeMeta()['origin'] ?? [];

    expect($origin['url'] ?? null)->toBe('https://new-origin.example.com')
        ->and($origin['routes'] ?? null)->toBe(['/api/*', '/graphql', '/webhook/*'])
        ->and($origin['managed'] ?? null)->toBeTrue()
        ->and($origin['cloud_site_id'] ?? null)->toBe('cloud-origin-id');

    expect(AuditLog::query()->where('action', 'site.edge.origin.updated')->count())->toBe(1);
});

test('hybrid origin save rejects invalid routes and url', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings(hybrid: true);

    Livewire::actingAs($user)
        ->test(Delivery::class, ['server' => $server, 'site' => $site])
        ->set('buildForm.edge_origin_url', 'not-a-url')
        ->set('buildForm.edge_origin_routes', '/api/*')
        ->call('saveEdgeHybridOrigin')
        ->assertHasErrors(['buildForm.edge_origin_url']);

    Livewire::actingAs($user)
        ->test(Delivery::class, ['server' => $server, 'site' => $site])
        ->set('buildForm.edge_origin_url', 'https://origin.example.com')
        ->set('buildForm.edge_origin_routes', 'api/no-leading-slash')
        ->call('saveEdgeHybridOrigin')
        ->assertHasErrors(['buildForm.edge_origin_routes']);

    Livewire::actingAs($user)
        ->test(Delivery::class, ['server' => $server, 'site' => $site])
        ->set('buildForm.edge_origin_url', 'https://origin.example.com')
        ->set('buildForm.edge_origin_routes', '/api/with space')
        ->call('saveEdgeHybridOrigin')
        ->assertHasErrors(['buildForm.edge_origin_routes']);
});

test('hybrid origin save is rejected for static sites', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(Delivery::class, ['server' => $server, 'site' => $site])
        ->set('buildForm.edge_origin_url', 'https://new-origin.example.com')
        ->set('buildForm.edge_origin_routes', '/api/*')
        ->call('saveEdgeHybridOrigin');

    $site->refresh();
    expect($site->edgeMeta()['origin'] ?? null)->toBeNull();
});

test('edge billing card links to the site organization analytics page', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(OverviewObservability::class, ['server' => $server, 'site' => $site])
        ->call('loadObservabilityCards')
        ->assertSee('View stats');
});

/**
 * @return array{0: User, 1: Server, 2: Site}
 */
function makeEdgeSiteForSettings(bool $withGithub = false, bool $hybrid = false): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    if ($withGithub) {
        $user->socialAccounts()->create([
            'provider' => 'github',
            'provider_id' => '12345',
            'nickname' => 'edge-dev',
            'access_token' => 'gh-test-token',
        ]);
    }

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $edgeMeta = [
        'source' => ['repo' => 'acme/web', 'branch' => 'main'],
        'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
        'live_url' => 'https://edge-app.dply.host',
        'deploy_on_push' => true,
    ];

    if ($hybrid) {
        $edgeMeta['runtime_mode'] = 'hybrid';
        $edgeMeta['origin'] = [
            'url' => 'https://origin.example.com',
            'cloud_site_id' => 'cloud-origin-id',
            'managed' => true,
            'routes' => ['/api/*', '/_next/data/*'],
        ];
    }

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => $edgeMeta,
        ],
    ]);

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/test/prefix',
        'published_at' => now(),
    ]);

    $deployment = EdgeDeployment::query()->where('site_id', $site->id)->latest('id')->first();
    $site->update([
        'meta' => array_merge(is_array($site->meta) ? $site->meta : [], [
            'edge' => array_merge($edgeMeta, [
                'active_deployment_id' => $deployment?->id,
            ]),
        ]),
    ]);

    return [$user, $server, $site->fresh()];
}
