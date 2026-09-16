<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeOrgCloudflareBackendTest;

use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\User;
use App\Modules\Edge\Actions\CreateEdgeSite;
use App\Modules\Edge\Services\EdgeRouter;
use App\Modules\Edge\Services\OrgCloudflareEdgeBackend;
use App\Modules\Edge\Support\EdgeOrgCredentialConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('edge router resolves org cloudflare backend', function () {
    config(['edge.fake.enabled' => false]);

    [$user, $org, $credential] = scaffoldOrg();

    $site = (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'BYO Site',
        'repo' => 'acme/static',
        'branch' => 'main',
        'edge_backend' => 'org_cloudflare',
        'edge_provider_credential_id' => $credential->id,
    ]);

    expect($site->edge_backend)->toBe('org_cloudflare')
        ->and($site->edge_provider_credential_id)->toBe($credential->id);

    $backend = EdgeRouter::backendFor($site->fresh());
    expect($backend)->toBeInstanceOf(OrgCloudflareEdgeBackend::class);
});

test('create rejects unbootstrapped org credential', function () {
    [$user, $org, $credential] = scaffoldOrg(bootstrapped: false);

    (new CreateEdgeSite)->handle($user, $org, [
        'name' => 'BYO Site',
        'repo' => 'acme/static',
        'edge_backend' => 'org_cloudflare',
        'edge_provider_credential_id' => $credential->id,
    ]);
})->throws(\RuntimeException::class);

/**
 * @return array{0: User, 1: Organization, 2: ProviderCredential}
 */
function scaffoldOrg(bool $bootstrapped = true): array
{
    Queue::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf-token'],
    ]);

    if ($bootstrapped) {
        EdgeOrgCredentialConfig::merge($credential, [
            'account_id' => 'acct-org',
            'kv_namespace_id' => 'kv-org',
            'r2_bucket' => 'dply-edge-org',
            'r2_access_key' => 'access',
            'r2_secret' => 'secret',
            'r2_endpoint' => 'https://acct-org.r2.cloudflarestorage.com',
            'worker_zone_name' => 'example.com',
        ]);
        $credential = $credential->fresh();
    }

    return [$user, $org, $credential];
}
