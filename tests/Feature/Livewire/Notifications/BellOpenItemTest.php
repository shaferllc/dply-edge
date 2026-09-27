<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Notifications\BellOpenItemTest;

use App\Livewire\Notifications\Bell;
use App\Livewire\Notifications\Index;
use App\Models\NotificationEvent;
use App\Models\NotificationInboxItem;
use App\Models\User;
use App\Support\NotificationTablesReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    NotificationTablesReady::flush();
});

function inboxItemFor(User $user, string $url = '/servers'): NotificationInboxItem
{
    $event = NotificationEvent::query()->create([
        'event_key' => 'server.ready',
        'title' => 'Server ready',
        'body' => 'Setup finished',
        'url' => $url,
        'severity' => 'info',
        'category' => 'server',
        'supports_in_app' => true,
        'supports_email' => false,
        'supports_webhook' => false,
        'occurred_at' => now(),
    ]);

    return NotificationInboxItem::query()->create([
        'notification_event_id' => $event->id,
        'user_id' => $user->id,
        'title' => 'Server ready',
        'body' => 'Setup finished',
        'url' => $url,
    ]);
}

test('bell openItem marks the row read and redirects without a type error', function () {
    $user = User::factory()->create();
    $item = inboxItemFor($user);

    Livewire::actingAs($user)
        ->test(Bell::class)
        ->call('openItem', $item->id)
        ->assertRedirect('/servers');

    expect($item->fresh()?->read_at)->not->toBeNull();
});

test('inbox openItem marks the row read and redirects without a type error', function () {
    $user = User::factory()->create();
    $item = inboxItemFor($user);

    Livewire::actingAs($user)
        ->test(Index::class)
        ->call('openItem', $item->id)
        ->assertRedirect('/servers');

    expect($item->fresh()?->read_at)->not->toBeNull();
});

test('inbox renders one page, loads more on demand, and counts in one pass', function () {
    $user = User::factory()->create();
    foreach (range(1, 27) as $i) {
        inboxItemFor($user);
    }
    inboxItemFor($user)->event->forceFill(['severity' => 'warning'])->save();
    $saved = inboxItemFor($user);
    $saved->forceFill(['saved_at' => now(), 'read_at' => now()])->save();

    $component = Livewire::actingAs($user)->test(Index::class)
        ->set('filter', 'all')
        ->assertViewHas('items', fn ($items) => $items->count() === 25)
        ->assertViewHas('hasMore', true)
        ->assertViewHas('totalCount', 29)
        ->assertViewHas('unreadCount', 28)
        ->assertViewHas('savedCount', 1)
        ->assertViewHas('attentionCount', 1)
        ->assertSee('Load more');

    $component->call('loadMore')
        ->assertViewHas('items', fn ($items) => $items->count() === 29)
        ->assertViewHas('hasMore', false)
        ->assertDontSee('Load more');

    // Changing the tab starts from the first page again.
    $component->set('filter', 'unread')->assertSet('perPage', 25);
});
