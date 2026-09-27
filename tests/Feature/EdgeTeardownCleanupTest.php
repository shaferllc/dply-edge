<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeTeardownCleanupTest;

use App\Enums\SiteType;
use App\Models\GitProviderToken;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('deleting an app removes its previews, custom hostnames, GitHub webhook and default KV', function () {
    config([
        'edge.fake.enabled' => false,
        'edge.custom_hostnames.enabled' => true,
        'edge.cloudflare.account_id' => 'acct_test',
        'edge.cloudflare.api_token' => 'token_test',
        'edge.cloudflare.worker_zone_name' => 'on-dply.site',
    ]);
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones?*' => Http::response(['success' => true, 'result' => [['id' => 'zone_123', 'name' => 'on-dply.site']]]),
        '*' => Http::response(['success' => true, 'result' => []]),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $pat = GitProviderToken::query()->create(['user_id' => $user->id, 'provider' => 'github', 'access_token' => 'ghp_test']);

    $parent = teardownSite($user, $org, 'app', [
        'source' => ['repo' => 'acme/web', 'branch' => 'main', 'deploy_on_push' => true],
        'routing' => [
            'hostname' => 'app.on-dply.site',
            'custom_domains' => ['www.example.com' => [
                'hostname' => 'www.example.com',
                'dns_status' => 'pending',
                'cf_custom_hostname_id' => 'ch_del',
            ]],
        ],
        'webhook' => ['provider' => 'github', 'hook_id' => 55, 'account_id' => (string) $pat->getKey()],
        'default_bindings' => ['kv' => 'kv_default'],
    ]);
    $preview = teardownSite($user, $org, 'app-pr-1', [
        'preview_parent_site_id' => $parent->id,
        'routing' => ['hostname' => 'app-pr-1.on-dply.site'],
        'default_bindings' => ['kv' => 'kv_preview'],
    ]);

    (new TeardownEdgeSiteJob($parent->id))->handle();

    expect(Site::query()->find($parent->id))->toBeNull()
        ->and(Site::query()->find($preview->id))->toBeNull();

    $deleted = fn (string $path) => Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), $path));
    $deleted('/repos/acme/web/hooks/55');
    $deleted('/custom_hostnames/ch_del');
    $deleted('/storage/kv/namespaces/kv_default');
    $deleted('/storage/kv/namespaces/kv_preview');

    // Idempotent: a retried job on a gone site is a no-op.
    (new TeardownEdgeSiteJob($parent->id))->handle();
});

test('a failing cleanup step does not stop the teardown', function () {
    config(['edge.fake.enabled' => false, 'edge.cloudflare.account_id' => 'acct_test', 'edge.cloudflare.api_token' => 'token_test']);
    Http::fake([
        '*/storage/kv/namespaces/kv_default' => Http::response(['success' => false, 'errors' => [['message' => 'boom']]], 500),
        '*' => Http::response(['success' => true, 'result' => []]),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $site = teardownSite($user, $org, 'app', [
        'routing' => ['hostname' => 'app.on-dply.site'],
        'default_bindings' => ['kv' => 'kv_default'],
    ]);

    (new TeardownEdgeSiteJob($site->id))->handle();

    expect(Site::query()->find($site->id))->toBeNull();
});

/**
 * @param  array<string, mixed>  $edge
 */
function teardownSite(User $user, Organization $org, string $slug, array $edge): Site
{
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => $slug,
        'slug' => $slug,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => ['runtime_profile' => 'edge_web', 'edge' => $edge],
    ]);
}
