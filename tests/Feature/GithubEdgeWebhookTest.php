<?php

declare(strict_types=1);

namespace Tests\Feature\GithubEdgeWebhookTest;

use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Edge\Jobs\TeardownEdgeSiteJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('pull request opened spawns preview', function () {
    Queue::fake();
    $site = makeSourceSite();

    $body = json_encode([
        'action' => 'opened',
        'pull_request' => [
            'number' => 42,
            'head' => ['ref' => 'feature/login', 'sha' => str_repeat('a', 40)],
        ],
    ], JSON_UNESCAPED_SLASHES);

    $response = postWebhook($site, 'pull_request', $body);

    $response->assertOk()->assertJsonPath('queued', 'preview');
    expect(Site::query()
        ->whereJsonContains('meta->edge->preview_parent_site_id', $site->id)
        ->count())->toBe(1);
    Queue::assertPushed(BuildEdgeSiteJob::class);
});

test('pull request synchronize redeploys existing preview', function () {
    Queue::fake();
    $site = makeSourceSite();

    $body = json_encode([
        'action' => 'opened',
        'pull_request' => [
            'number' => 9,
            'head' => ['ref' => 'feature/x', 'sha' => str_repeat('b', 40)],
        ],
    ]);
    postWebhook($site, 'pull_request', $body)->assertOk();

    $body2 = json_encode([
        'action' => 'synchronize',
        'pull_request' => [
            'number' => 9,
            'head' => ['ref' => 'feature/x', 'sha' => str_repeat('c', 40)],
        ],
    ]);
    postWebhook($site, 'pull_request', $body2)->assertOk();

    expect(Site::query()
        ->whereJsonContains('meta->edge->preview_parent_site_id', $site->id)
        ->count())->toBe(1);
    Queue::assertPushed(BuildEdgeSiteJob::class, 2);
});

test('pull request closed tears down preview', function () {
    Queue::fake();
    $site = makeSourceSite();

    postWebhook($site, 'pull_request', json_encode([
        'action' => 'opened',
        'pull_request' => ['number' => 5, 'head' => ['ref' => 'feature/x']],
    ]))->assertOk();

    $response = postWebhook($site, 'pull_request', json_encode([
        'action' => 'closed',
        'pull_request' => ['number' => 5, 'head' => ['ref' => 'feature/x']],
    ]));

    $response->assertOk()->assertJsonPath('queued', 'teardown');
    Queue::assertPushed(TeardownEdgeSiteJob::class);
});

test('push to source branch queues redeploy', function () {
    Queue::fake();
    $site = makeSourceSite();

    $response = postWebhook($site, 'push', json_encode([
        'ref' => 'refs/heads/main',
        'before' => 'aaaa',
        'after' => 'bbbb',
    ]));

    $response->assertOk()->assertJsonPath('queued', 'redeploy');
    Queue::assertPushed(BuildEdgeSiteJob::class);
});

test('push to other branch is no op', function () {
    Queue::fake();
    $site = makeSourceSite();

    $response = postWebhook($site, 'push', json_encode([
        'ref' => 'refs/heads/feature/sidebar',
    ]));

    $response->assertOk()->assertJsonPath('queued', false);
    Queue::assertNotPushed(BuildEdgeSiteJob::class);
});

test('push outside repo root does not queue redeploy', function () {
    Queue::fake();
    $site = makeSourceSite(['repo_root' => 'apps/web']);

    $response = postWebhook($site, 'push', json_encode([
        'ref' => 'refs/heads/main',
        'commits' => [[
            'added' => [],
            'modified' => ['apps/api/server.ts'],
            'removed' => [],
        ]],
    ]));

    $response->assertOk()->assertJsonPath('reason', 'push_outside_repo_root');
    Queue::assertNotPushed(BuildEdgeSiteJob::class);
});

test('push touching repo root still queues redeploy', function () {
    Queue::fake();
    $site = makeSourceSite(['repo_root' => 'apps/web']);

    $response = postWebhook($site, 'push', json_encode([
        'ref' => 'refs/heads/main',
        'commits' => [[
            'added' => [],
            'modified' => ['apps/web/src/page.tsx'],
            'removed' => [],
        ]],
    ]));

    $response->assertOk()->assertJsonPath('queued', 'redeploy');
    Queue::assertPushed(BuildEdgeSiteJob::class);
});

test('invalid signature returns 403', function () {
    $site = makeSourceSite();
    $body = json_encode(['action' => 'opened', 'pull_request' => ['number' => 1, 'head' => ['ref' => 'feature/x']]]);

    $response = test()->call(
        'POST',
        route('hooks.edge.github', $site),
        json_decode($body, true),
        [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'pull_request',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
        ],
        $body,
    );

    $response->assertStatus(403);
});

test('non edge site returns 422', function () {
    $site = makeSourceSite();
    $site->update(['edge_backend' => null, 'meta' => []]);

    $body = json_encode(['ref' => 'refs/heads/main']);
    $response = postWebhook($site, 'push', $body);

    $response->assertStatus(422);
});

test('ping event returns ok', function () {
    $site = makeSourceSite();
    $response = postWebhook($site, 'ping', json_encode(['zen' => 'Speak like a human.']));

    $response->assertOk()->assertJsonPath('event', 'ping');
});

function postWebhook(Site $site, string $event, string $body)
{
    $signature = 'sha256='.hash_hmac('sha256', $body, (string) $site->webhook_secret);

    return test()->call(
        'POST',
        route('hooks.edge.github', $site),
        json_decode($body, true),
        [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ],
        $body,
    );
}

function makeSourceSite(array $sourceOverrides = []): Site
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);

    return Site::factory()->create([
        'server_id' => $server->id,
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'Marketing Site',
        'slug' => 'marketing-site',
        'type' => SiteType::Static,
        'runtime' => null,
        'document_root' => null,
        'repository_path' => null,
        'edge_backend' => 'dply_edge',
        'webhook_secret' => 'test-secret',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => array_merge(
                    ['repo' => 'acme/marketing', 'branch' => 'main', 'deploy_on_push' => true],
                    $sourceOverrides,
                ),
                'routing' => ['hostname' => 'marketing-site.dply.host'],
                'live_url' => 'https://marketing-site.dply.host',
            ],
        ],
    ]);
}
