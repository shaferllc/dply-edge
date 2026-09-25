<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeQueueConsumersTest;

use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Edge\Services\EdgeQueueConsumers;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Providers\Cloudflare\EdgeCloudflareClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->org = Organization::factory()->create();
});

function siteOnQueue(Organization $org, User $user, string $runtime, string $createdAt): Site
{
    $server = Server::factory()->create(['organization_id' => $org->id, 'user_id' => $user->id]);
    $site = Site::factory()->create(['organization_id' => $org->id, 'server_id' => $server->id, 'user_id' => $user->id, 'edge_backend' => 'dply_edge', 'created_at' => $createdAt]);
    $site->mergeEdgeMeta(['runtime_mode' => $runtime]);
    $site->save();
    EdgeContainerConnections::attach($site, 'queue', 'JOBS', 'shared-jobs');

    return $site->fresh();
}

test('the earliest production app on a queue runs its jobs and the rest only send', function () {
    $first = siteOnQueue($this->org, $this->user, 'container', '2026-01-01 00:00:00');
    $second = siteOnQueue($this->org, $this->user, 'ssr', '2026-02-01 00:00:00');

    expect(EdgeQueueConsumers::owner($this->org, 'shared-jobs')?->id)->toBe($first->id)
        ->and(EdgeQueueConsumers::owns($first, 'shared-jobs'))->toBeTrue()
        ->and(EdgeQueueConsumers::owns($second, 'shared-jobs'))->toBeFalse();
});

test('an asleep queue does not make its app the owner', function () {
    $first = siteOnQueue($this->org, $this->user, 'container', '2026-01-01 00:00:00');
    $second = siteOnQueue($this->org, $this->user, 'ssr', '2026-02-01 00:00:00');
    $rows = EdgeContainerConnections::for($first);
    $rows[0]['asleep'] = true;
    $first->mergeEdgeMeta(['connections' => $rows]);
    $first->save();

    expect(EdgeQueueConsumers::owner($this->org, 'shared-jobs')?->id)->toBe($second->id);
});

test('queue speed comes from the tier', function (string $tier, ?int $concurrency, int $waitMs) {
    config(['subscription.standard.tiers.free.queue_concurrency' => config("subscription.standard.tiers.{$tier}.queue_concurrency")]);
    config(['subscription.standard.tiers.free.queue_batch_wait_seconds' => config("subscription.standard.tiers.{$tier}.queue_batch_wait_seconds")]);

    $settings = EdgeQueueConsumers::settings($this->org);

    expect($settings['max_concurrency'] ?? null)->toBe($concurrency)
        ->and($settings['max_wait_time_ms'])->toBe($waitMs)
        ->and($settings['batch_size'])->toBe(10);
})->with([
    'free' => ['free', 1, 5000],
    'pro' => ['pro', 10, 2000],
    'team' => ['team', 50, 1000],
    'enterprise' => ['enterprise', null, 0],
]);

test('putQueueConsumer replaces another consumer and registers the platform worker', function () {
    config(['edge.cloudflare.account_id' => 'acct', 'edge.cloudflare.api_token' => 'tok']);
    Http::fake([
        'api.cloudflare.com/client/v4/accounts/acct/queues/q-1/consumers' => Http::sequence()
            ->push(['success' => true, 'result' => [['consumer_id' => 'c-old', 'script' => 'dply-ctr-other']]])
            ->push(['success' => true, 'result' => ['consumer_id' => 'c-new']]),
        'api.cloudflare.com/client/v4/accounts/acct/queues/q-1/consumers/c-old' => Http::response(['success' => true, 'result' => null]),
    ]);

    EdgeCloudflareClient::fromConfig()->putQueueConsumer('q-1', 'dply-edge', ['batch_size' => 10, 'max_retries' => 5, 'max_wait_time_ms' => 2000, 'max_concurrency' => 10]);

    Http::assertSent(fn ($request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/consumers/c-old'));
    Http::assertSent(fn ($request): bool => $request->method() === 'POST' && $request['script_name'] === 'dply-edge' && $request['settings']['max_concurrency'] === 10);
});
