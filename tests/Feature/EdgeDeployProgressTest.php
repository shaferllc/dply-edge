<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeDeployProgressTest;

use App\Enums\SiteType;
use App\Events\Edge\EdgeDeploymentProgressed;
use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\SocialAccount;
use App\Models\User;
use App\Modules\Edge\Support\EdgeDeployProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('the pill step follows the build log', function (string $chunk, ?string $label) {
    expect(EdgeDeployProgress::labelFromLog($chunk))->toBe($label);
})->with([
    ['[dply:step] clone'."\n", 'Cloning'],
    ["[dply:step] build\nnpm install\n", 'Building'],
    ["#21 pushing layers 2.1s done\n", 'Pushing image'],
    ["[dply:step] publish\nImage pushed. Waiting for Dply Edge to roll the container out.\n", 'Rolling out'],
    ["Rollout in progress — 50%, 1 instance(s) starting…\n", 'Rolling out 50%'],
    ["Checking https://app.on-dply.live answers.\n", 'Checking the app answers'],
    ["npm WARN deprecated\n", null],
]);

test('a new step is saved once and broadcast to the organization', function () {
    Event::fake([EdgeDeploymentProgressed::class]);
    [, $deployment] = deployment();
    Event::fake([EdgeDeploymentProgressed::class]);

    EdgeDeployProgress::record($deployment->id, "Rollout in progress — 50%, 1 instance(s) starting…\n");
    EdgeDeployProgress::record($deployment->id, "Rollout in progress — 50%, 0 instance(s) starting…\n");

    expect($deployment->refresh()->meta['progress'])->toBe('Rolling out 50%')
        ->and($deployment->meta['keep'])->toBe('me');
    Event::assertDispatchedTimes(EdgeDeploymentProgressed::class, 1);
    Event::assertDispatched(EdgeDeploymentProgressed::class, fn ($e) => $e->deployment['step'] === 'Rolling out 50%' && $e->broadcastOn()[0]->name === 'private-organization.'.$deployment->organization_id);
});

test('a deploy remembers who started it and broadcasts each status change', function () {
    Event::fake([EdgeDeploymentProgressed::class]);
    [$user, $deployment] = deployment(actingAs: true);

    expect($deployment->meta['triggered_by'])->toBe((string) $user->id);

    $deployment->update(['status' => EdgeDeployment::STATUS_LIVE]);
    $deployment->update(['git_commit' => 'abc1234']);

    Event::assertDispatchedTimes(EdgeDeploymentProgressed::class, 2); // created + live
    Event::assertDispatched(EdgeDeploymentProgressed::class, fn ($e) => $e->deployment['step'] === 'Live');
});

test('the jobs’ guarded status update broadcasts too, and a static publish reads Publishing', function () {
    Event::fake([EdgeDeploymentProgressed::class]);
    [, $deployment] = deployment();
    EdgeDeployProgress::record($deployment->id, "[dply:step] build\n");

    $deployment->trySetStatusUnlessCancelled(EdgeDeployment::STATUS_PUBLISHING);

    Event::assertDispatched(EdgeDeploymentProgressed::class, fn ($e) => $e->deployment['status'] === 'publishing' && $e->deployment['step'] === 'Publishing');
});

test('the org broadcast leaves out the failure text', function () {
    $event = new EdgeDeploymentProgressed('org', ['id' => 'd', 'status' => 'failed', 'failure' => 'SECRET build output']);

    expect($event->broadcastWith())->not->toHaveKey('failure')->toHaveKey('status');
});

test('in-flight deploys are listed only for members who can view the app', function () {
    [$user, $deployment] = deployment();
    $outsider = User::factory()->create();

    expect(collect(EdgeDeployProgress::inFlightFor($user))->pluck('id')->all())->toBe([$deployment->id])
        ->and(EdgeDeployProgress::inFlightFor($outsider))->toBe([]);
});

test('a git push is credited to the dply user with that GitHub account', function () {
    $user = User::factory()->create();
    SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'github', 'provider_id' => '4242']);

    expect(EdgeDeployProgress::githubPusher(['sender' => ['id' => 4242]]))->toBe((string) $user->id)
        ->and(EdgeDeployProgress::githubPusher(['sender' => ['id' => 1]]))->toBeNull()
        ->and(EdgeDeployProgress::githubPusher([]))->toBeNull();
});

test('the pill endpoint lists what the member may see, and resolves a watched deploy after it finishes', function () {
    [$user, $deployment] = deployment();

    $this->actingAs($user)->getJson(route('deploy-pill.index'))
        ->assertOk()
        ->assertJsonPath('deploys.0.id', $deployment->id)
        ->assertJsonPath('deploys.0.app_name', 'Edge App')
        ->assertJsonPath('deploys.0.can_deploy', true);

    // Missed the finish push (Reverb down): the watch list still resolves it.
    $deployment->update(['status' => EdgeDeployment::STATUS_FAILED, 'failure_reason' => 'npm exploded']);
    $this->actingAs($user)->getJson(route('deploy-pill.index', ['watch' => [$deployment->id]]))
        ->assertJsonPath('deploys.0.status', 'failed')
        ->assertJsonPath('deploys.0.failure', 'npm exploded');
});

test('someone outside the app can neither see nor tail nor cancel its deploy', function () {
    [, $deployment] = deployment();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)->getJson(route('deploy-pill.index', ['watch' => [$deployment->id]]))->assertJsonPath('deploys', []);
    $this->actingAs($outsider)->getJson(route('deploy-pill.tail', $deployment))->assertForbidden();
    $this->actingAs($outsider)->postJson(route('deploy-pill.cancel', $deployment))->assertForbidden();
    expect($deployment->refresh()->status)->toBe(EdgeDeployment::STATUS_BUILDING);
});

test('the pill’s Full log link opens that deploy’s log on Build & deploy logs', function () {
    [$user, $deployment] = deployment();

    \Livewire\Livewire::withQueryParams(['deployment' => $deployment->id])
        ->actingAs($user)
        ->test(\App\Livewire\Sites\Edge\Workspace\Logs::class, ['server' => $deployment->site->server, 'site' => $deployment->site])
        ->assertSet('openDeployment', $deployment->id)
        ->assertDispatched('open-modal', 'deploy-log');
});

/** @return array{0: User, 1: EdgeDeployment} */
function deployment(bool $actingAs = false): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);
    $server = Server::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id, 'meta' => ['host_kind' => Server::HOST_KIND_DPLY_EDGE]]);
    $site = Site::factory()->create([
        'server_id' => $server->id, 'user_id' => $user->id, 'organization_id' => $org->id,
        'name' => 'Edge App', 'type' => SiteType::Static, 'edge_backend' => 'dply_edge',
        'meta' => ['edge' => ['live_url' => 'https://edge-app.dply.host']],
    ]);
    if ($actingAs) {
        test()->actingAs($user);
    }

    return [$user, EdgeDeployment::query()->create([
        'site_id' => $site->id, 'organization_id' => $org->id,
        'status' => EdgeDeployment::STATUS_BUILDING, 'meta' => ['keep' => 'me'],
    ])];
}

test('a deploy whose worker process is gone fails within a minute, others are left alone', function () {
    Event::fake([EdgeDeploymentProgressed::class]);
    [, $orphan] = deployment();
    $host = gethostname();
    $old = now()->subMinutes(2)->getTimestamp();
    EdgeDeployProgress::setMeta($orphan->id, 'worker', "{$host}|999999|{$old}");            // gone
    [, $running] = deployment();
    EdgeDeployProgress::setMeta($running->id, 'worker', "{$host}|".getmypid()."|{$old}");   // this process: alive
    [, $elsewhere] = deployment();
    EdgeDeployProgress::setMeta($elsewhere->id, 'worker', "another-host|999999|{$old}");     // not ours to judge
    [, $justStarted] = deployment();
    EdgeDeployProgress::setMeta($justStarted->id, 'worker', "{$host}|999999|".now()->getTimestamp());

    expect(app(\App\Modules\Edge\Actions\CancelStuckEdgeDeployment::class)->reapOrphaned())->toBe(1)
        ->and($orphan->fresh()->status)->toBe(EdgeDeployment::STATUS_FAILED)
        ->and($orphan->fresh()->failure_reason)->toContain('worker running it restarted')
        ->and($running->fresh()->status)->toBe(EdgeDeployment::STATUS_BUILDING)
        ->and($elsewhere->fresh()->status)->toBe(EdgeDeployment::STATUS_BUILDING)
        ->and($justStarted->fresh()->status)->toBe(EdgeDeployment::STATUS_BUILDING);
    Event::assertDispatched(EdgeDeploymentProgressed::class, fn ($e) => $e->deployment['id'] === $orphan->id && $e->deployment['status'] === 'failed');
});
