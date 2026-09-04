<?php

declare(strict_types=1);

namespace Tests\Feature\CreateEdgeSiteTest;

use App\Modules\Edge\Actions\CreateEdgeSite;
use App\Enums\SiteType;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Support\EdgeOrgCredentialConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('creates org cloudflare edge site when credential bootstrapped', function () {
    Queue::fake();
    [$user, $org] = scaffold();

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf-token'],
    ]);

    EdgeOrgCredentialConfig::merge($credential, [
        'account_id' => 'acct-org',
        'kv_namespace_id' => 'kv-org',
        'r2_bucket' => 'dply-edge-org',
        'r2_access_key' => 'access',
        'r2_secret' => 'secret',
        'r2_endpoint' => 'https://acct-org.r2.cloudflarestorage.com',
        'worker_zone_name' => 'example.com',
    ]);

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'BYO Marketing',
        'repo' => 'acme/marketing',
        'edge_backend' => 'org_cloudflare',
        'edge_provider_credential_id' => $credential->id,
    ]);

    expect($site->edge_backend)->toBe('org_cloudflare')
        ->and($site->edge_provider_credential_id)->toBe($credential->id)
        ->and($site->meta['edge']['routing']['hostname'] ?? '')->toMatch('/^byo-marketing-[a-z0-9]{6}\.example\.com$/');
});

test('creates edge server site deployment and dispatches build', function () {
    Queue::fake();
    config(['edge.testing_domains' => ['on-dply.site']]);
    [$user, $org] = scaffold();

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'Marketing Site',
        'repo' => 'acme/marketing',
        'branch' => 'main',
        'build_command' => 'npm ci && npm run build',
        'output_dir' => 'dist',
        'spa_fallback' => true,
        'deploy_on_push' => true,
        'framework' => 'vite',
    ]);

    expect($site->type)->toBe(SiteType::Static);
    expect($site->edge_backend)->toBe('dply_edge');
    expect($site->status)->toBe(Site::STATUS_EDGE_PROVISIONING);
    expect($site->meta['runtime_profile'] ?? null)->toBe('edge_web');
    expect($site->meta['edge']['source']['repo'] ?? null)->toBe('acme/marketing');
    expect($site->meta['edge']['build']['output_dir'] ?? null)->toBe('dist');
    expect($site->meta['edge']['routing']['hostname'] ?? '')->toMatch('/^marketing-site-[a-z0-9]{6}\.on-dply\.site$/');
    expect($site->server)->not->toBeNull();
    expect($site->server->hostKind())->toBe(Server::HOST_KIND_DPLY_EDGE);

    expect(EdgeDeployment::query()->where('site_id', $site->id)->count())->toBe(1);

    Queue::assertPushed(BuildEdgeSiteJob::class);
});

test('persists monorepo repo root on the edge source spec', function () {
    Queue::fake();
    [$user, $org] = scaffold();

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'Web App',
        'repo' => 'acme/platform',
        'repo_root' => 'apps/web',
    ]);

    expect($site->edgeRepoRoot())->toBe('apps/web');
    expect($site->meta['edge']['source']['repo_root'] ?? null)->toBe('apps/web');
});

test('normalizes github url to owner slash repo', function () {
    Queue::fake();
    [$user, $org] = scaffold();

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'From URL',
        'repo' => 'https://github.com/acme/site.git',
        'branch' => 'main',
    ]);

    expect($site->meta['edge']['source']['repo'] ?? null)->toBe('acme/site');
});

test('throws when repo missing', function () {
    [$user, $org] = scaffold();

    (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'No Repo',
        'repo' => '',
    ]);
})->throws(\InvalidArgumentException::class);

/*
 | Worker SSR is a real delivery mode now (it carries its own platform fee);
 | it is refused only when the Edge platform can't run it — no Cloudflare
 | credentials and Fake Edge off.
 */
test('throws when ssr is requested and the platform cannot run it', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.cloudflare.account_id' => '',
        'edge.cloudflare.api_token' => '',
    ]);
    [$user, $org] = scaffold();

    (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'SSR',
        'repo' => 'acme/next',
        'runtime_mode' => 'ssr',
    ]);
})->throws(\RuntimeException::class);

test('creates hybrid edge site with origin config', function () {
    Queue::fake();
    [$user, $org] = scaffold();

    $cloudOrigin = Site::factory()->create([
        'organization_id' => $org->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'container_backend' => 'digitalocean_app_platform',
    ]);

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'Hybrid Next',
        'repo' => 'acme/next',
        'runtime_mode' => 'hybrid',
        'origin_url' => 'https://cloud-app.example.test',
        'cloud_site_id' => (string) $cloudOrigin->id,
        'origin_managed' => true,
    ]);

    expect($site->meta['edge']['runtime_mode'] ?? null)->toBe('hybrid');
    expect($site->meta['edge']['origin']['url'] ?? null)->toBe('https://cloud-app.example.test');
    expect($site->meta['edge']['origin']['cloud_site_id'] ?? null)->toBe((string) $cloudOrigin->id);
    expect($site->meta['edge']['origin']['managed'] ?? false)->toBeTrue();
    expect($site->meta['edge']['origin']['routes'] ?? null)->toBeArray();
});

test('rejects cloud site id from another organization', function () {
    [$user, $org] = scaffold();
    $otherOrg = Organization::factory()->create();
    $foreignCloud = Site::factory()->create([
        'organization_id' => $otherOrg->id,
        'user_id' => $user->id,
        'type' => SiteType::Container,
        'container_backend' => 'digitalocean_app_platform',
    ]);

    (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'Hybrid Next',
        'repo' => 'acme/next',
        'runtime_mode' => 'hybrid',
        'origin_url' => 'https://cloud-app.example.test',
        'cloud_site_id' => (string) $foreignCloud->id,
    ]);
})->throws(\RuntimeException::class);

/**
 * @return array{0: User, 1: Organization}
 */
function scaffold(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    return [$user, $org];
}
