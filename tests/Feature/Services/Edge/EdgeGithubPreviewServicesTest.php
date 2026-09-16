<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Edge;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\Edge\Actions\CreateEdgePreviewSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('fake edge adhoc preview uses local testing apex not parent on-dply hostname', function () {
    Queue::fake();
    config([
        'edge.fake.enabled' => true,
        'edge.fake.allowed_environments' => ['testing', 'local'],
        'edge.testing_domains' => ['on-dply.site', 'edge.test'],
        'app.env' => 'testing',
        'app.url' => 'https://dply.test',
    ]);

    [$parent] = makeEdgeParentWithGithub();
    $parent->mergeEdgeMeta([
        'routing' => ['hostname' => 'sample-edge-app-b6adb77e.on-dply.site'],
        'live_url' => 'https://sample-edge-app-b6adb77e.on-dply.site',
    ]);
    $parent->save();

    $preview = (new CreateEdgePreviewSite)->handleAdhoc(
        $parent->fresh(),
        'main',
        'b704ccb0123456789abcdef0123456789abcdef0',
    );

    $hostname = (string) ($preview->edgeMeta()['routing']['hostname'] ?? '');

    expect($hostname)->toEndWith('.edge.test')
        ->and($hostname)->toContain('--b704ccb')
        ->and($hostname)->not->toContain('on-dply.site');
});

test('preview spawn posts github check run and pr comment when head sha present', function () {
    Queue::fake();
    Http::fake(function (Request $request) {
        if (str_contains($request->url(), '/check-runs')) {
            return Http::response(['id' => 9001], 201);
        }
        if (str_contains($request->url(), '/comments')) {
            return Http::response(['id' => 8001], 201);
        }

        return Http::response([], 404);
    });

    [$parent] = makeEdgeParentWithGithub();

    $preview = (new CreateEdgePreviewSite)->handle(
        $parent,
        'feature/preview',
        7,
        str_repeat('d', 40),
    );

    $meta = $preview->edgeMeta();
    expect($meta['github_check_run_id'] ?? null)->toBe(9001)
        ->and($meta['github_comment_id'] ?? null)->toBe(8001)
        ->and($meta['preview_head_sha'] ?? null)->toBe(str_repeat('d', 40));

    Http::assertSentCount(2);
});

/**
 * @return array{0: Site, 1: SocialAccount}
 */
function makeEdgeParentWithGithub(): array
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
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'webhook_secret' => 'whsec_edge_test',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/edge-app', 'branch' => 'main', 'deploy_on_push' => true],
                'routing' => ['hostname' => 'edge-app.dply.host'],
            ],
        ],
    ]);

    SocialAccount::query()->create([
        'user_id' => $user->id,
        'provider' => 'github',
        'provider_id' => 'gh-1',
        'access_token' => 'gho_edge_test',
    ]);

    $account = SocialAccount::query()->where('user_id', $user->id)->firstOrFail();

    $site->mergeEdgeMeta([
        'webhook' => [
            'provider' => 'github',
            'hook_id' => 123,
            'account_id' => (string) $account->getKey(),
            'status' => 'active',
        ],
    ]);
    $site->save();

    return [$site->fresh(), $account];
}
