<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Edge\EdgeHostMapPublisherTest;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\ProviderCredential;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Support\EdgeDeliveryContext;
use App\Modules\Edge\Support\EdgeOrgCredentialConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('writes kv using org delivery context', function () {
    config(['edge.fake.enabled' => false]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []], 200),
    ]);

    [$site, $deployment, $context] = scaffoldOrgSite();

    app(EdgeHostMapPublisher::class)->publishHostname($site, $deployment, 'app.example.com', $context);

    Http::assertSent(function ($request) use ($context) {
        return $request->method() === 'PUT'
            && str_contains($request->url(), '/accounts/'.$context->accountId.'/storage/kv/namespaces/'.$context->kvNamespaceId.'/values/app.example.com');
    });
});

test('publishes the deploy footer flag when the site enables it', function () {
    config(['edge.fake.enabled' => false]);

    Http::fake([
        'api.cloudflare.com/*' => Http::response(['success' => true, 'result' => []], 200),
    ]);

    [$site, $deployment, $context] = scaffoldOrgSite();
    $site->mergeEdgeMeta(['deploy_footer' => ['enabled' => true]]);
    $site->save();

    app(EdgeHostMapPublisher::class)->publishHostname($site->fresh(), $deployment, 'app.example.com', $context);

    Http::assertSent(function ($request) use ($deployment) {
        $body = json_decode($request->body(), true);

        return is_array($body)
            && ($body['deploy_footer'] ?? null) === true
            && ($body['deployment_id'] ?? null) === $deployment->id;
    });
});

/**
 * @return array{0: Site, 1: EdgeDeployment, 2: EdgeDeliveryContext}
 */
function scaffoldOrgSite(): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $credential = ProviderCredential::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'cf-token'],
    ]);

    EdgeOrgCredentialConfig::merge($credential, [
        'account_id' => 'acct-by',
        'kv_namespace_id' => 'kv-by',
        'r2_bucket' => 'bucket-by',
        'r2_access_key' => 'key',
        'r2_secret' => 'secret',
        'r2_endpoint' => 'https://acct-by.r2.cloudflarestorage.com',
    ]);

    $context = EdgeDeliveryContext::fromProviderCredential($credential->fresh());

    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'edge_backend' => 'org_cloudflare',
        'edge_provider_credential_id' => $credential->id,
        'meta' => ['edge' => ['routing' => ['hostname' => 'app.example.com', 'spa_fallback' => true]]],
    ]);

    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'storage_prefix' => 'edge/org/site/deploy',
        'git_branch' => 'main',
    ]);

    return [$site, $deployment, $context];
}
