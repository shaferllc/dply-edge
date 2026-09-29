<?php

declare(strict_types=1);

namespace Tests\Feature\EdgeAlertsNotificationsTest;

use App\Enums\SiteType;
use App\Livewire\Sites\Edge\Workspace\Alerts;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('edge alerts page saves channel subscriptions for edge events', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

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
        'slug' => 'edge-app',
        'type' => SiteType::Static,
        'edge_backend' => 'dply_edge',
        'status' => Site::STATUS_EDGE_ACTIVE,
        'meta' => [
            'runtime_profile' => 'edge_web',
            'edge' => [
                'source' => ['repo' => 'acme/web', 'branch' => 'main'],
                'build' => ['command' => 'npm run build', 'output_dir' => 'dist'],
                'routing' => ['hostname' => 'edge-app.on-dply.site'],
            ],
        ],
    ]);

    $channel = NotificationChannel::factory()->forUser($user)->create();

    Livewire::actingAs($user)
        ->test(Alerts::class, ['server' => $server, 'site' => $site])
        ->assertSee('No events are routed yet')
        ->call('editRule', 'rum')
        ->assertSet('editingRule', 'rum')
        ->set('channelEventSelections', [
            (string) $channel->id => ['edge.deploy.failed', 'edge.rum.breach'],
        ])
        ->set('lcp_enabled', true)
        ->set('lcp_threshold', 3000)
        ->call('saveRule')
        ->assertHasNoErrors()
        ->assertSet('editingRule', null)
        ->assertSee('2 of')
        ->assertSee('When LCP p75 goes over 3,000 ms');

    expect($site->fresh()->edgeMeta()['alerts']['lcp_p75_ms'])->toBe(['enabled' => true, 'threshold' => 3000]);

    $this->assertDatabaseHas('notification_subscriptions', [
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Site::class,
        'subscribable_id' => $site->id,
        'event_key' => 'edge.deploy.failed',
    ]);
    $this->assertDatabaseHas('notification_subscriptions', [
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Site::class,
        'subscribable_id' => $site->id,
        'event_key' => 'edge.rum.breach',
    ]);
});
