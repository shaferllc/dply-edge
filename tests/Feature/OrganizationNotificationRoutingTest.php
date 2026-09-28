<?php

use App\Livewire\Organizations\NotificationChannels;
use App\Models\NotificationChannel;
use App\Models\NotificationSubscription;
use App\Models\Organization;
use App\Models\SlackInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function routingOrgWithOwner(string $role = 'owner'): array
{
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => $role]);

    return [$org, $user];
}

test('the matrix lists edge events and no vm-era keys', function () {
    $keys = array_column(NotificationChannels::routingEvents(), 'key');

    expect($keys)->toContain('edge.deploy.failed', 'site.uptime.down')
        ->and(collect($keys)->filter(fn ($k) => str_starts_with($k, 'server.') || str_starts_with($k, 'import.'))->all())->toBe([])
        ->and($keys)->not->toContain('account.git_token.unhealthy', 'site.deployments');
});

test('clicking a cell routes the event org-wide and clicking again removes it', function () {
    [$org, $user] = routingOrgWithOwner();
    $channel = $org->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_EMAIL,
        'label' => 'Ops inbox',
        'config' => ['email' => 'ops@example.com'],
    ]);
    $where = [
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Organization::class,
        'subscribable_id' => $org->id,
        'event_key' => 'edge.deploy.failed',
    ];

    $page = Livewire::actingAs($user)
        ->test(NotificationChannels::class, ['organization' => $org])
        ->assertSee('Ops inbox')
        ->assertSee('Not routed')
        ->call('toggleRoute', $channel->id, 'edge.deploy.failed');

    expect(NotificationSubscription::query()->where($where)->exists())->toBeTrue();
    $page->assertDontSee('Not routed');

    $page->call('toggleRoute', $channel->id, 'edge.deploy.failed');
    expect(NotificationSubscription::query()->where($where)->exists())->toBeFalse();
});

test('unknown event keys and other orgs\' channels are refused', function () {
    [$org, $user] = routingOrgWithOwner();
    $channel = $org->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_EMAIL,
        'label' => 'Mine',
        'config' => ['email' => 'a@example.com'],
    ]);
    $other = Organization::factory()->create()->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_EMAIL,
        'label' => 'Theirs',
        'config' => ['email' => 'b@example.com'],
    ]);

    Livewire::actingAs($user)
        ->test(NotificationChannels::class, ['organization' => $org])
        ->call('toggleRoute', $channel->id, 'server.ssh_login')
        ->assertStatus(404);

    Livewire::actingAs($user)
        ->test(NotificationChannels::class, ['organization' => $org])
        ->call('toggleRoute', $other->id, 'edge.deploy.failed')
        ->assertStatus(404);

    expect(NotificationSubscription::query()->count())->toBe(0);
});

test('empty org shows the add-destination empty state instead of a grid', function () {
    [$org, $user] = routingOrgWithOwner();

    Livewire::actingAs($user)
        ->test(NotificationChannels::class, ['organization' => $org])
        ->assertSee('Add a destination so alerts have somewhere to go')
        ->assertDontSee('DPLY_NOTIFICATION_CHANNEL_TYPES');
});

test('delete goes through the confirm modal', function () {
    [$org, $user] = routingOrgWithOwner();
    $channel = $org->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_EMAIL,
        'label' => 'Gone',
        'config' => ['email' => 'a@example.com'],
    ]);

    Livewire::actingAs($user)
        ->test(NotificationChannels::class, ['organization' => $org])
        ->call('openConfirmActionModal', 'deleteChannel', [$channel->id], 'Delete destination', 'Remove?', 'Delete', true)
        ->call('confirmActionModal');

    expect(NotificationChannel::query()->whereKey($channel->id)->exists())->toBeFalse();
});

test('connected slack workspaces list with a confirm-modal disconnect', function () {
    [$org, $user] = routingOrgWithOwner();
    $workspace = SlackInstallation::query()->create([
        'owner_type' => Organization::class,
        'owner_id' => (string) $org->id,
        'team_id' => 'T123',
        'team_name' => 'Acme Slack',
        'bot_user_id' => 'U9',
        'scopes' => 'chat:write',
        'credentials' => ['bot_token' => 'xoxb-test'],
    ]);

    Livewire::actingAs($user)
        ->test(NotificationChannels::class, ['organization' => $org])
        ->assertSee('Connected apps')
        ->assertSee('Acme Slack')
        ->assertSeeHtml("openConfirmActionModal('disconnectSlackWorkspace', ['{$workspace->id}']")
        ->assertDontSeeHtml('wire:confirm');
});
