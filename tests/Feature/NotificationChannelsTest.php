<?php

namespace Tests\Feature\NotificationChannelsTest;

use App\Livewire\Organizations\NotificationChannels as OrgNotificationChannels;
use App\Livewire\Settings\BulkNotificationAssignments;
use App\Livewire\Settings\NotificationChannels as ProfileNotificationChannels;
use App\Livewire\Teams\NotificationChannels as TeamNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('user can view profile notification channels', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.notification-channels'))
        ->assertOk();
});

test('profile notification channels page shows current org and team channels', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create(['name' => 'Acme']);
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $org->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Org alerts',
        'config' => ['webhook_url' => 'https://hooks.slack.com/services/T/B/ORG'],
    ]);

    $team = Team::query()->create([
        'organization_id' => $org->id,
        'name' => 'Engineering',
        'slug' => 'engineering',
    ]);
    $team->users()->attach($user->id, ['role' => 'member']);
    $team->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_EMAIL,
        'label' => 'Team inbox',
        'config' => ['email' => 'team@example.com'],
    ]);

    $this->actingAs($user)
        ->withSession(['current_organization_id' => $org->id])
        ->get(route('profile.notification-channels'))
        ->assertOk()
        ->assertSee('Available beyond your personal channels')
        ->assertSee('Acme')
        ->assertSee('Org alerts')
        ->assertSee('Engineering')
        ->assertSee('Team inbox');
});

test('user can create a personal notification channel', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_SLACK)
        ->set('new_label', 'Alerts')
        ->set('new_slack_webhook_url', 'https://hooks.slack.com/services/T000/B000/XXXXXXXX')
        ->call('createChannel')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notification_channels', [
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Alerts',
    ]);
});

test('organization admin can view org notification channels', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    $this->actingAs($user)
        ->get(route('organizations.notification-channels', $org))
        ->assertOk();
});

test('organization member without admin cannot view org notification channels', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user)
        ->get(route('organizations.notification-channels', $org))
        ->assertForbidden();
});

test('team member can view team notification channels', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'member']);
    $team = Team::query()->create([
        'organization_id' => $org->id,
        'name' => 'Engineering',
        'slug' => 'engineering',
    ]);
    $team->users()->attach($user->id, ['role' => 'member']);

    $this->actingAs($user)
        ->get(route('teams.notification-channels', [$org, $team]))
        ->assertOk();
});

test('team org admin can send slack test notification', function () {
    Http::fake([
        'hooks.slack.com/*' => Http::response('ok', 200),
    ]);

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $team = Team::query()->create([
        'organization_id' => $org->id,
        'name' => 'Engineering',
        'slug' => 'engineering',
    ]);
    $team->users()->attach($user->id, ['role' => 'member']);

    $channel = $team->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'T',
        'config' => [
            'webhook_url' => 'https://hooks.slack.com/services/x/y/z',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(TeamNotificationChannels::class, ['organization' => $org, 'team' => $team])
        ->call('sendTest', $channel->id)
        ->assertDispatched('notify', message: __('Test message sent.'), type: 'success');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'hooks.slack.com'));
});

test('org admin can create org notification channel', function () {
    Http::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->test(OrgNotificationChannels::class, ['organization' => $org])
        ->set('new_type', NotificationChannel::TYPE_WEBHOOK)
        ->set('new_label', 'Ops')
        ->set('new_webhook_url', 'https://example.test/hook')
        ->call('createChannel')
        ->assertHasNoErrors();

    expect($org->fresh()->notificationChannels()->count())->toBe(1);
});

test('user can create email channel and send test', function () {
    Mail::fake();

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->set('new_type', NotificationChannel::TYPE_EMAIL)
        ->set('new_label', 'Ops inbox')
        ->set('new_email_address', 'ops@example.com')
        ->call('createChannel')
        ->assertHasNoErrors();

    $channel = NotificationChannel::query()->where('owner_type', User::class)->where('owner_id', $user->id)->firstOrFail();

    Livewire::actingAs($user)
        ->test(ProfileNotificationChannels::class)
        ->call('sendTest', $channel->id)
        ->assertDispatched('notify', message: __('Test email queued — it will arrive shortly.'), type: 'success');
});

test('bulk assign notifications creates subscription', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
    ]);

    $site = Site::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'server_id' => $server->id,
    ]);

    $channel = NotificationChannel::factory()->forUser($user)->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Alerts',
        'config' => [
            'webhook_url' => 'https://hooks.slack.com/services/T/B/X',
        ],
    ]);

    Livewire::actingAs($user)
        ->test(BulkNotificationAssignments::class)
        ->set('selected_channel_ids', [(string) $channel->id])
        ->set('selected_event_keys', ['server.ssh_login', 'site.deployments'])
        ->set('selected_server_ids', [(string) $server->id])
        ->set('selected_site_ids', [(string) $site->id])
        ->call('assign')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notification_subscriptions', [
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Server::class,
        'subscribable_id' => $server->id,
        'event_key' => 'server.ssh_login',
    ]);

    $this->assertDatabaseHas('notification_subscriptions', [
        'notification_channel_id' => $channel->id,
        'subscribable_type' => Site::class,
        'subscribable_id' => $site->id,
        'event_key' => 'site.deployments',
    ]);
});

test('bulk assign page renders when authenticated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.notification-channels.bulk-assign'))
        ->assertOk()
        ->assertSee('Bulk assign notifications', false);
});

test('bulk assign page can preselect server from query string', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    $server = Server::factory()->create([
        'user_id' => $user->id,
        'organization_id' => $org->id,
        'name' => 'web-1',
    ]);

    $this->actingAs($user)
        ->get(route('profile.notification-channels.bulk-assign', ['server' => $server->id]))
        ->assertOk()
        ->assertSee('Assigning notifications for server:')
        ->assertSee('web-1');
});

test('bulk assign page can quick add notification channel', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    session(['current_organization_id' => $org->id]);

    Livewire::actingAs($user)
        ->test(BulkNotificationAssignments::class)
        ->set('quick_new_owner_scope', 'personal')
        ->set('quick_new_type', NotificationChannel::TYPE_SLACK)
        ->set('quick_new_label', 'Ops alerts')
        ->set('quick_new_slack_webhook_url', 'https://hooks.slack.com/services/T/B/X')
        ->call('createQuickNotificationChannel')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('notification_channels', [
        'owner_type' => User::class,
        'owner_id' => $user->id,
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Ops alerts',
    ]);
});

test('webhook channels refuse internal targets at save, on test and on delivery', function () {
    Http::fake();

    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);

    Livewire::actingAs($user)
        ->test(OrgNotificationChannels::class, ['organization' => $org])
        ->set('new_type', NotificationChannel::TYPE_WEBHOOK)
        ->set('new_label', 'Metadata')
        ->set('new_webhook_url', 'http://169.254.169.254/latest/meta-data/')
        ->call('createChannel')
        ->assertHasErrors('new_webhook_url');

    // A row saved before the rule existed (or written another way).
    $channel = $org->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_WEBHOOK,
        'label' => 'Internal',
        'config' => ['url' => 'http://127.0.0.1:6379/', 'headers' => ['X-Test' => '1']],
    ]);

    $result = $channel->sendTest($user);
    expect($result['ok'])->toBeFalse()
        ->and($result['message'])->toContain('not allowed');

    $channel->sendOperationalMessage('Subject', 'Body');

    $slack = $org->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_SLACK,
        'label' => 'Slack',
        'config' => ['webhook_url' => 'http://localhost/hook'],
    ]);
    $slack->sendOperationalMessage('Subject', 'Body');

    Http::assertNothingSent();
});
