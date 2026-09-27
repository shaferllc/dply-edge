<?php

namespace Tests\Feature\Api\NotificationApiTest;

use App\Models\ApiToken;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @return array{0: User, 1: Site, 2: NotificationChannel, 3: string}
 */
function notificationFixture(array $abilities = ['notifications.read', 'notifications.write']): array
{
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    $organization->users()->attach($user->id, ['role' => 'owner']);
    $user->update(['current_organization_id' => $organization->id]);

    $server = Server::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);
    $site = Site::factory()->create([
        'server_id' => $server->id,
        'organization_id' => $organization->id,
        'user_id' => $user->id,
    ]);

    $channel = NotificationChannel::factory()->forUser($user)->create(['label' => 'Ops Slack']);

    ['plaintext' => $plaintext] = ApiToken::createToken($user, $organization, 'test', null, $abilities);

    return [$user, $site, $channel, $plaintext];
}

it('lists the channels a token can route to', function () {
    [, , $channel, $token] = notificationFixture();

    $this->withToken($token)
        ->getJson('/api/v1/notifications/channels')
        ->assertOk()
        ->assertJsonPath('data.0.id', (string) $channel->id)
        ->assertJsonPath('data.0.label', 'Ops Slack')
        ->assertJsonStructure(['data' => [['id', 'label', 'type', 'destination', 'owner']]]);
});

it('scopes the event catalog to the subject', function () {
    [, , , $token] = notificationFixture();

    $siteGroups = collect($this->withToken($token)->getJson('/api/v1/notifications/events?subject=site')->json('data'));
    $serverGroups = collect($this->withToken($token)->getJson('/api/v1/notifications/events?subject=server')->json('data'));
    $all = collect($this->withToken($token)->getJson('/api/v1/notifications/events')->json('data'));

    $siteKeys = $siteGroups->flatMap(fn ($group) => collect($group['events'])->pluck('key'));
    $serverKeys = $serverGroups->flatMap(fn ($group) => collect($group['events'])->pluck('key'));

    expect($siteKeys)->toContain('site.uptime.down', 'site.errors.deploy_failed')
        ->and($siteKeys->every(fn ($key) => str_starts_with($key, 'site.')))->toBeTrue()
        ->and($serverKeys->every(fn ($key) => str_starts_with($key, 'server.') || str_starts_with($key, 'backup.')))->toBeTrue()
        // The full catalog is a superset of both.
        ->and($all->count())->toBeGreaterThan($siteGroups->count());
});

it('offers edge events only to edge apps', function () {
    [, $site, , $token] = notificationFixture();

    $keys = fn (Site $subject) => collect(
        $this->withToken($token)->getJson("/api/v1/sites/{$subject->slug}/notifications")->json('data.groups')
    )->flatMap(fn ($group) => collect($group['events'])->pluck('key'));

    $site->forceFill(['edge_backend' => null])->save();
    expect($keys($site->fresh()))->not->toContain('edge.deploy.failed');

    $site->forceFill(['edge_backend' => 'dply_edge'])->save();
    expect($keys($site->fresh()))->toContain('edge.deploy.failed')
        ->not->toContain('serverless.assets.over_budget');
});

it('routes an event to a channel and back off again', function () {
    [, $site, $channel, $token] = notificationFixture();

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/notifications", [
            'channel' => (string) $channel->id,
            'subscribe' => ['site.uptime.down'],
        ])
        ->assertOk()
        ->assertJsonPath('data.added', 1)
        ->assertJsonPath('data.events', ['site.uptime.down']);

    expect(NotificationSubscription::query()
        ->where('notification_channel_id', $channel->id)
        ->where('subscribable_id', $site->id)
        ->pluck('event_key')->all())->toBe(['site.uptime.down']);

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/notifications", [
            'channel' => (string) $channel->id,
            'unsubscribe' => ['site.uptime.down'],
        ])
        ->assertOk()
        ->assertJsonPath('data.removed', 1)
        ->assertJsonPath('data.events', []);

    expect(NotificationSubscription::query()->count())->toBe(0);
});

it('adds to a channel without dropping what it already had', function () {
    [, $site, $channel, $token] = notificationFixture();

    foreach (['site.uptime.down', 'site.ssl.expiring'] as $event) {
        $this->withToken($token)->postJson("/api/v1/sites/{$site->slug}/notifications", [
            'channel' => (string) $channel->id,
            'subscribe' => [$event],
        ])->assertOk();
    }

    // The second call must not have replaced the first — this is why the
    // endpoint takes subscribe/unsubscribe rather than a full selection.
    expect(NotificationSubscription::query()->pluck('event_key')->sort()->values()->all())
        ->toBe(['site.ssl.expiring', 'site.uptime.down']);
});

it('shows what is routed where', function () {
    [, $site, $channel, $token] = notificationFixture();

    $this->withToken($token)->postJson("/api/v1/sites/{$site->slug}/notifications", [
        'channel' => (string) $channel->id,
        'subscribe' => ['site.deployments'],
    ])->assertOk();

    $this->withToken($token)
        ->getJson("/api/v1/sites/{$site->slug}/notifications")
        ->assertOk()
        ->assertJsonPath('data.channels.0.id', (string) $channel->id)
        ->assertJsonPath('data.channels.0.events', ['site.deployments']);
});

it('refuses an event that does not belong to the subject', function () {
    [, $site, $channel, $token] = notificationFixture();

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/notifications", [
            'channel' => (string) $channel->id,
            // A server event, on a site.
            'subscribe' => ['server.health.degraded'],
        ])
        ->assertStatus(422);

    expect(NotificationSubscription::query()->count())->toBe(0);
});

it('refuses a channel the token cannot reach', function () {
    [, $site, , $token] = notificationFixture();
    $stranger = NotificationChannel::factory()->forUser(User::factory()->create())->create();

    $this->withToken($token)
        ->postJson("/api/v1/sites/{$site->slug}/notifications", [
            'channel' => (string) $stranger->id,
            'subscribe' => ['site.deployments'],
        ])
        ->assertNotFound();

    expect(NotificationSubscription::query()->count())->toBe(0);
});

it('needs notifications.write to change routing', function () {
    [, $site, $channel, $readOnly] = notificationFixture(['notifications.read']);

    $this->withToken($readOnly)
        ->getJson("/api/v1/sites/{$site->slug}/notifications")
        ->assertOk();

    $this->withToken($readOnly)
        ->postJson("/api/v1/sites/{$site->slug}/notifications", [
            'channel' => (string) $channel->id,
            'subscribe' => ['site.deployments'],
        ])
        ->assertForbidden();
});

it('keeps another organization out', function () {
    [, , , $token] = notificationFixture();
    [, $foreignSite] = notificationFixture();

    $this->withToken($token)
        ->getJson("/api/v1/sites/{$foreignSite->slug}/notifications")
        ->assertForbidden();
});

it('holds a token to its user: removed members are refused, site viewers cannot reroute', function () {
    [$owner, $site, , $ownerToken] = notificationFixture();
    $organization = $site->organization;

    $viewer = User::factory()->create();
    $organization->users()->attach($viewer->id, ['role' => 'member']);
    $workspace = Workspace::factory()->create(['organization_id' => $organization->id, 'user_id' => $owner->id]);
    $workspace->members()->create(['user_id' => $viewer->id, 'role' => WorkspaceMember::ROLE_VIEWER]);
    $site->update(['workspace_id' => $workspace->id]);
    $channel = NotificationChannel::factory()->forUser($viewer)->create();
    ['plaintext' => $viewerToken] = ApiToken::createToken($viewer, $organization, 'viewer', null, ['notifications.read', 'notifications.write']);

    $this->withToken($viewerToken)->getJson("/api/v1/sites/{$site->slug}/notifications")->assertOk();
    $this->withToken($viewerToken)->postJson("/api/v1/sites/{$site->slug}/notifications", [
        'channel' => (string) $channel->id,
        'subscribe' => ['site.uptime.down'],
    ])->assertForbidden();

    $organization->users()->detach($owner->id);
    Organization::flushMemberRoleCache(); // a new request in production
    $this->withToken($ownerToken)->getJson('/api/v1/notifications/channels')->assertForbidden();
});
