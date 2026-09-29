<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\ConsoleActionBanner;
use App\Livewire\Sites\Edge\Workspace\Build;
use App\Livewire\Sites\Edge\Workspace\Cache;
use App\Livewire\Sites\Edge\Workspace\Delivery;
use App\Livewire\Sites\Edge\Workspace\Deploys;
use App\Livewire\Sites\Edge\Workspace\Overview;
use App\Livewire\Sites\Edge\Workspace\OverviewObservability;
use App\Livewire\Sites\Edge\Workspace\Traffic;
use App\Livewire\Sites\EdgeSettings;
use App\Models\AuditLog;
use App\Models\ConsoleAction;
use App\Models\EdgeDeployment;
use App\Models\EdgeUsageSnapshot;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Services\EdgeSiteAccessAnalytics;
use App\Modules\Edge\Livewire\BuildJourney;
use App\Modules\Edge\Services\EdgeCachePurger;
use App\Support\Sites\SiteWorkspaceBreadcrumbs;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('edge site workspace route renders full app layout shell', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'deploys']))
        ->assertOk()
        ->assertSee('Deploy a specific commit, branch or tag', false);
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
        // Domains live under Routing (with redirects, rewrites and headers).
        ->assertSee('Routing')
        ->assertSee('Billing & usage')
        ->assertSee('Traffic & analytics')
        ->assertSee('Build & deploy logs')
        ->assertSee('Back to projects')
        ->assertDontSee('System user')
        ->assertDontSee('Certificates');
});

test('edge overview shows live url redeploy and no nginx references', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'general'])
        ->assertSee('Edge App')
        ->assertSee('https://edge-app.dply.host')
        ->assertSee('Open')
        ->assertSee('acme/web')
        ->assertDontSee('nginx')
        ->assertDontSee('Webserver')
        ->assertDontSee('PHP-FPM');
});

test('edge breadcrumbs skip the edge product crumb', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $labels = array_column(
        SiteWorkspaceBreadcrumbs::items($server, $site, __('Overview')),
        'label'
    );

    expect($labels)
        ->toBe([__('Dashboard'), __('Projects'), $site->name, __('Overview')])
        ->not->toContain(__('Edge'))
        ->not->toContain(__('Infrastructure'))
        ->not->toContain(__('Servers'));

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']))
        ->assertOk()
        ->assertSee(__('Dashboard'));
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
        ->assertSee('History')
        ->assertSee('earlier build is ready to roll back to')
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
        ->assertSee('Delete '.$site->name)
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
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'billing'])
        ->assertSee('Billing & usage')
        ->assertSee('Usage this period')
        ->assertSee('Delivery')
        ->assertDontSee('Site fee')
        ->assertSee('42,000')
        ->assertSee('Open org billing');

    $this->actingAs($user)
        ->get(route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'billing']))
        ->assertOk()
        ->assertSee(route('billing.show', $site->organization_id), false);
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
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'traffic'])
        ->assertSee('Traffic & analytics');

    // The stats live in the lazily loaded Traffic child (wire:init).
    Livewire::actingAs($user)
        ->test(Traffic::class, ['server' => $server, 'site' => $site])
        ->call('loadTraffic')
        ->assertSee('Your app answered')
        ->assertSee('12,500 requests')
        ->assertSee('Requests per day')
        ->assertSee('Response time')
        ->assertSee('Core Web Vitals')
        ->assertSee('Live requests');
});

test('edge logs section clarifies build logs vs visitor traffic', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(EdgeSettings::class, ['server' => $server, 'site' => $site, 'section' => 'logs'])
        ->assertSee('Build & deploy logs')
        ->assertSee('not visitor HTTP logs')
        ->assertSee('Recent deploys')
        ->assertSee('are under Traffic')
        ->assertDontSee('Streaming access logs in real time.');
});

test('edge logs page sums up the latest deploys and opens a failed build in its dialog', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'status' => EdgeDeployment::STATUS_FAILED,
        'storage_prefix' => 'edge/test/failed',
        'git_commit' => '8bd04e2aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'failure_reason' => "npm run build exited with code 1\nerror TS2304",
    ]);

    $lw = Livewire::actingAs($user)
        ->test(\App\Livewire\Sites\Edge\Workspace\Logs::class, ['server' => $server, 'site' => $site])
        ->assertSee('8bd04e2 failed')
        ->assertSee('npm run build exited with code 1');

    $id = EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_FAILED)->value('id');
    $lw->call('openDeploy', $id)
        ->assertSet('openDeployment', $id)
        ->assertSee('Jump to error')
        ->assertSee('No build log stored for this deploy.');
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

test('turning deploy on push on connects the GitHub webhook', function () {
    Http::fake(['api.github.com/repos/acme/web/hooks' => Http::response(['id' => 991], 201)]);
    [$user, $server, $site] = makeEdgeSiteForSettings(withGithub: true);

    Livewire::actingAs($user)
        ->test(Build::class, ['server' => $server, 'site' => $site])
        ->set('buildForm.edge_deploy_on_push', true)
        ->call('saveEdgeBuildSettings')
        ->assertHasNoErrors();

    expect($site->fresh()->edgeMeta()['webhook']['hook_id'] ?? null)->toBe(991);
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

test('the overview is the project url: links drop /general and old /general, /overview urls redirect there', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'edge_backend' => 'dply_edge', 'status' => Site::STATUS_EDGE_ACTIVE]);

    expect(route('sites.show', ['site' => $site, 'section' => 'general'], false))->toBe('/projects/'.$site->id)
        ->and(route('sites.show', ['site' => $site, 'section' => 'logs'], false))->toBe('/projects/'.$site->id.'/logs');

    $this->actingAs($user)->get('/projects/'.$site->id.'/general?tab=x')->assertRedirect('/projects/'.$site->id.'?tab=x')->assertStatus(301);
    $this->actingAs($user)->get('/projects/'.$site->id.'/overview')->assertRedirect('/projects/'.$site->id);
});

test('edge deploys keeps the 20-row limit across a poll re-render', function () {
    // Livewire does not restore relations on hydrate; a mount()-time eager
    // load vanished on each wire:poll and lazy-loaded every deployment.
    [$user, $server, $site] = makeEdgeSiteForSettings();

    foreach (range(1, 25) as $i) {
        EdgeDeployment::query()->create([
            'site_id' => $site->id,
            'organization_id' => $site->organization_id,
            'status' => EdgeDeployment::STATUS_SUPERSEDED,
            'storage_prefix' => "edge/test/p{$i}",
        ]);
    }

    $lw = Livewire::actingAs($user)
        ->test(Deploys::class, ['server' => $server, 'site' => $site])
        ->call('$refresh');

    expect($lw->instance()->site->edgeDeployments)->toHaveCount(20);
});

test('edge cache tab lists KV once on init, not on every render', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $purger = \Mockery::mock(EdgeCachePurger::class);
    $purger->shouldReceive('listEntries')->once()
        ->andReturn(['ok' => true, 'entries' => [['path' => '/about', 'expires_at' => null]], 'message' => '']);
    app()->instance(EdgeCachePurger::class, $purger);

    Livewire::actingAs($user)
        ->test(Cache::class, ['server' => $server, 'site' => $site])
        ->assertSee('Loading stored copies')
        ->call('loadEntries')
        ->assertSee('/about')
        ->set('mode', 'standard')
        ->call('saveOptions')
        ->assertSee('/about');
});

test('edge traffic tab paints first, then loads access analytics once into the cache', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();
    $accessQueries = fn (): int => collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'edge_access_logs'))->count();

    DB::enableQueryLog();
    $lw = Livewire::actingAs($user)
        ->test(Traffic::class, ['server' => $server, 'site' => $site])
        ->assertSee('Loading traffic');
    expect($accessQueries())->toBe(0);

    $lw->call('loadTraffic')->assertDontSee('Loading traffic');
    $afterLoad = $accessQueries();
    expect($afterLoad)->toBeGreaterThan(0);

    // A fresh request (no per-request memo) is served from the 120s cache.
    request()->attributes->replace([]);
    app(EdgeSiteAccessAnalytics::class)->forSite($site->fresh());
    expect($accessQueries())->toBe($afterLoad);
});

test('console action banner polls itself and nudges the shell once the run finishes', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();
    $run = ConsoleAction::query()->create([
        'subject_type' => $site->getMorphClass(),
        'subject_id' => $site->id,
        'kind' => 'env_sync',
        'status' => 'running',
        'started_at' => now(),
        'label' => 'Syncing env …',
    ]);

    $banner = Livewire::actingAs($user)
        ->test(ConsoleActionBanner::class, ['site' => $site, 'kinds' => ['env_sync']])
        ->assertSee('Syncing env')
        ->assertSeeHtml('wire:poll.4s')
        ->assertNotDispatched('console-action-finished');

    $run->forceFill(['status' => 'completed', 'finished_at' => now()])->save();

    $banner->call('$refresh')
        ->assertSee('Syncing env — done.')
        ->assertDispatched('console-action-finished');
});

test('build journey ships only new log lines per tick, routed to their step', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();
    $log = tempnam(sys_get_temp_dir(), 'edge-log');
    file_put_contents($log, "=== header ===\n[dply:step] clone\nxyzzy-clone-line\n");
    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'storage_prefix' => 'edge/test/journey',
        'meta' => ['local_build_log_path' => $log],
    ]);

    $lw = Livewire::actingAs($user)
        ->test(BuildJourney::class, ['deploymentId' => $deployment->id])
        ->assertSeeHtml('xyzzy-clone-line') // mount seeds the browser store via x-init
        ->assertSet('logSteps', ['clone']);
    expect($lw->instance())->not->toHaveProperty('buffer');

    // A marker split across two ticks: the partial line waits for its newline.
    file_put_contents($log, "npm i\n[dply:st", FILE_APPEND);
    $lw->call('tail')
        ->assertDispatched('edge-build-log', fn (string $name, array $p): bool => $p['chunks'] === ['clone' => "npm i\n"]);

    file_put_contents($log, "ep] deploy\nvite built\n", FILE_APPEND);
    $lw->call('tail')
        ->assertDispatched('edge-build-log', fn (string $name, array $p): bool => $p['chunks'] === ['build' => "vite built\n"])
        ->assertSet('logSteps', ['clone', 'build'])
        ->assertDontSeeHtml('xyzzy-clone-line'); // re-renders carry no log text

    @unlink($log);
});

test('overview deploy poll skips the render until the watched deploy settles', function () {
    // The journey card polls itself; the Overview's own 2s tick re-renders
    // (hero, service map) only once the deploy it watches stops running.
    [$user, $server, $site] = makeEdgeSiteForSettings();
    // Newer than the live deploy the helper seeds.
    $deploy = fn (string $status, int $inSeconds) => EdgeDeployment::query()->forceCreate([
        'site_id' => $site->id,
        'organization_id' => $site->organization_id,
        'status' => $status,
        'storage_prefix' => 'edge/test/poll-'.$inSeconds,
        'created_at' => now()->addSeconds($inSeconds),
    ]);
    $mapQueries = fn (): int => collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains($q['query'], 'edge_usage_snapshots'))->count();
    $building = $deploy(EdgeDeployment::STATUS_BUILDING, 60);

    $lw = Livewire::actingAs($user)
        ->test(Overview::class, ['server' => $server, 'site' => $site])
        ->assertSeeHtml('wire:poll.2s="checkDeploy(')
        ->assertSeeLivewire(BuildJourney::class);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $lw->call('checkDeploy', $building->id);
    expect($mapQueries())->toBe(0);

    // A cancel/restart queued a newer deploy: re-render so the card swaps to it.
    $restarted = $deploy(EdgeDeployment::STATUS_BUILDING, 120);
    $lw->call('checkDeploy', $building->id)
        ->assertSeeHtml("checkDeploy('{$restarted->id}')");
    expect($mapQueries())->toBeGreaterThan(0);

    // Settled: full render, the card and the poll are gone.
    $restarted->forceFill(['status' => EdgeDeployment::STATUS_LIVE])->save();
    $lw->call('checkDeploy', $restarted->id)
        ->assertDontSeeHtml('wire:poll')
        ->assertDontSeeLivewire(BuildJourney::class);
});

test('overview observability cards skip the deployments context', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    DB::enableQueryLog();
    Livewire::actingAs($user)
        ->test(OverviewObservability::class, ['server' => $server, 'site' => $site])
        ->call('loadObservabilityCards');

    expect(collect(DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], '"edge_deployments"."site_id"'))->count())->toBe(0);
});

test('edge cache page sums up the setup and edits one setting in a dialog', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $purger = \Mockery::mock(EdgeCachePurger::class);
    $purger->shouldReceive('listEntries')
        ->andReturn(['ok' => true, 'entries' => [['path' => '/app.css', 'expires_at' => null], ['path' => '/app.js', 'expires_at' => null]], 'message' => '']);
    app()->instance(EdgeCachePurger::class, $purger);

    $lw = Livewire::actingAs($user)
        ->test(Cache::class, ['server' => $server, 'site' => $site])
        ->call('loadEntries')
        ->assertSee('static assets (scripts, styles, images, fonts)')
        ->assertSee('2 copies are')
        ->call('editSetting', 'edge')
        ->assertSet('editing', 'edge')
        ->set('edgeTtl', '3600')
        ->call('closeSetting')
        ->assertSet('edgeTtl', '86400')
        ->call('editSetting', 'edge')
        ->set('edgeTtl', '3600')
        ->call('saveOptions')
        ->assertSet('editing', '')
        ->assertSee('The edge keeps a copy for 1 hour');

    expect($site->fresh()->edgeMeta()['cache']['edge_ttl_seconds'])->toBe(3600);
});

test('deploy triggers sum up in a sentence, and a new hook shows its URL once in a dialog', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    $lw = Livewire::actingAs($user)
        ->test(\App\Livewire\Sites\Edge\Workspace\DeployTriggers::class, ['server' => $server, 'site' => $site])
        ->assertSee('Pushes don’t deploy yet.', false)
        ->assertSee('Create a deploy hook')
        ->call('openNewHook')
        ->set('edge_new_deploy_hook_name', 'Sanity publish')
        ->call('mintEdgeDeployHook')
        ->assertSee('Copy the URL now. It won’t be shown again.', false)
        ->assertSee('curl -X POST')
        ->call('dismissEdgeDeployHookUrl')
        ->assertSee('1 deploy hook')
        ->assertSee('“Sanity publish” hasn’t fired yet', false);

    $hook = \App\Models\EdgeDeployHook::query()->where('site_id', $site->id)->firstOrFail();
    $lw->call('openHook', (string) $hook->id)
        ->call('revokeOpenHook')
        ->assertDontSee('“Sanity publish” hasn’t fired yet', false);
    expect(\App\Models\EdgeDeployHook::query()->where('site_id', $site->id)->exists())->toBeFalse();
});

test('build page reads as a sentence, and a setting saves from its dialog', function () {
    [$user, $server, $site] = makeEdgeSiteForSettings();

    Livewire::actingAs($user)
        ->test(Build::class, ['server' => $server, 'site' => $site])
        ->assertSee('and publishes')
        ->assertSee('How it builds')
        ->call('openSetting', 'command')
        ->assertSet('editing', 'command')
        ->set('buildForm.edge_build_command', 'pnpm build')
        ->call('closeSetting')
        ->assertSet('buildForm.edge_build_command', $site->fresh()->edgeMeta()['build']['command'] ?? 'npm ci && npm run build')
        ->call('openSetting', 'command')
        ->set('buildForm.edge_build_command', 'pnpm build')
        ->call('saveSetting')
        ->assertHasNoErrors()
        ->assertSet('editing', '')
        ->assertSee('The build runs pnpm build');
});
