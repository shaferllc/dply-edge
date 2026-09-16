<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Http\Middleware\ResolveEdgeCustomDomain;
use App\Modules\Edge\Support\FakeEdgeProvision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

test('fake edge middleware serves artifacts for site hostname', function () {
    config([
        'edge.fake.enabled' => true,
        'app.env' => 'testing',
        'app.url' => 'https://dplyi.test',
        'edge.testing_domains' => ['edge.test'],
    ]);

    $site = makeFakeEdgeSiteWithArtifact('edge-app', 'edge-app.edge.test', 'edge-local hello');

    ResolveEdgeCustomDomain::invalidateHostMap();

    $response = $this->get('http://edge-app.edge.test/');
    $response->assertOk()
        ->assertHeader('X-Dply-Deployment-Id', $site->edgeDeployments()->first()->id);

    $file = $response->baseResponse->getFile();
    expect($file)->not->toBeNull();
    expect(file_get_contents($file->getPathname()))->toBe('edge-local hello');
});

test('fake edge middleware passes unknown hostnames through', function () {
    config([
        'edge.fake.enabled' => true,
        'app.env' => 'testing',
        'app.url' => 'https://dplyi.test',
    ]);

    ResolveEdgeCustomDomain::invalidateHostMap();

    $this->get('http://not-an-edge-site.edge.test/')
        ->assertHeaderMissing('X-Dply-Deployment-Id');
});

function makeFakeEdgeSiteWithArtifact(string $slug, string $hostname, string $body): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    $site = Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Edge App',
        'slug' => $slug,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'routing' => ['hostname' => $hostname, 'spa_fallback' => true],
                'live_url' => 'https://'.$hostname,
            ],
        ],
    ]);

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_LIVE,
        'storage_prefix' => 'edge/test/'.$site->id,
        'published_at' => now(),
    ]);

    $meta = $site->edgeMeta();
    $meta['active_deployment_id'] = $deployment->id;
    $site->update(['meta' => array_merge(is_array($site->meta) ? $site->meta : [], ['edge' => $meta])]);

    $artifactDir = rtrim(FakeEdgeProvision::storageRoot(), '/').'/'.trim($deployment->storage_prefix, '/');
    File::ensureDirectoryExists($artifactDir);
    File::put($artifactDir.'/index.html', $body);

    Cache::put('edge:fake:host-map', [
        $hostname => [
            'storage_prefix' => $deployment->storage_prefix,
            'deployment_id' => $deployment->id,
            'spa_fallback' => true,
            'headers' => [],
        ],
    ], now()->addDay());

    return $site->fresh();
}
