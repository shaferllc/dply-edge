<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeBuildFailureNotificationTest;

use App\Enums\SiteType;
use App\Models\EdgeDeployment;
use App\Models\NotificationEvent;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Modules\Edge\Jobs\BuildEdgeSiteJob;
use App\Modules\Notifications\Services\NotificationPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * `PublishEdgeDeploymentJob` has always notified on a publish-phase failure, but
 * `BuildEdgeSiteJob` did not — so the *most common* failure of all (clone,
 * dply.yaml lint, docker, install/build, artifact upload) was completely silent.
 * An operator watching Slack saw successes and nothing else.
 *
 * These pin the build-phase notification. `markFailed()` is private and is the
 * single convergence point for every build failure, so it is invoked directly
 * rather than driving a real Docker build.
 */
function scaffoldEdgeSite(): array
{
    $org = Organization::factory()->create();
    $server = Server::factory()->create([
        'organization_id' => $org->id,
        'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE],
    ]);
    $site = Site::factory()->create([
        'organization_id' => $org->id,
        'server_id' => $server->id,
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
    ]);
    $deployment = EdgeDeployment::query()->create([
        'site_id' => $site->id,
        'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING,
        'git_branch' => 'main',
        'git_commit' => 'abc1234',
        'storage_prefix' => 'edge/'.$org->id.'/'.$site->id.'/01BUILDFAIL',
    ]);

    return [$site, $deployment];
}

function markBuildFailed(Site $site, EdgeDeployment $deployment, string $message): void
{
    $job = new BuildEdgeSiteJob($deployment->id);
    $method = new \ReflectionMethod($job, 'markFailed');
    $method->setAccessible(true);
    $method->invoke($job, $site, $deployment, $message);
}

test('a build-phase failure publishes edge.deploy.failed', function () {
    [$site, $deployment] = scaffoldEdgeSite();

    expect(NotificationEvent::where('event_key', 'edge.deploy.failed')->count())->toBe(0);

    markBuildFailed($site, $deployment, 'npm ci exploded');

    $event = NotificationEvent::where('event_key', 'edge.deploy.failed')->latest('id')->first();

    expect($event)->not->toBeNull();
    expect($event->organization_id)->toBe($site->organization_id);
    expect($event->resource_type)->toBe(Site::class);
    expect($event->resource_id)->toBe((string) $site->id);
    expect($event->body)->toBe('npm ci exploded');
});

test('the event is tagged as the build phase so it is distinguishable from a publish failure', function () {
    [$site, $deployment] = scaffoldEdgeSite();

    markBuildFailed($site, $deployment, 'docker pull failed');

    $event = NotificationEvent::where('event_key', 'edge.deploy.failed')->latest('id')->first();

    expect($event->metadata['phase'])->toBe('build');
    expect($event->metadata['deployment_id'])->toBe((string) $deployment->id);
    expect($event->metadata['commit'])->toBe('abc1234');
    expect($event->metadata['branch'])->toBe('main');
    expect($event->metadata['failure_reason'])->toBe('docker pull failed');
});

test('the deployment and site are still marked failed', function () {
    [$site, $deployment] = scaffoldEdgeSite();

    markBuildFailed($site, $deployment, 'boom');

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED);
    expect($deployment->fresh()->failure_reason)->toBe('boom');
    expect($site->fresh()->status)->toBe(Site::STATUS_EDGE_FAILED);
});

test('a broken notification channel never masks the build error', function () {
    // The publish is best-effort: markFailed()'s job is to record the failure.
    // If the notification layer throws, the deployment must still be marked
    // failed rather than the exception escaping and hiding the real cause.
    [$site, $deployment] = scaffoldEdgeSite();

    app()->bind(NotificationPublisher::class, function () {
        throw new \RuntimeException('slack webhook is on fire');
    });

    markBuildFailed($site, $deployment, 'the real build error');

    expect($deployment->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED);
    expect($deployment->fresh()->failure_reason)->toBe('the real build error');
    expect(NotificationEvent::where('event_key', 'edge.deploy.failed')->count())->toBe(0);
});
