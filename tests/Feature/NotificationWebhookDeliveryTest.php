<?php

declare(strict_types=1);

namespace Tests\Feature\NotificationWebhookDeliveryTest;

use App\Jobs\DeliverNotificationWebhookJob;
use App\Livewire\Organizations\NotificationChannels as OrgNotificationChannels;
use App\Models\NotificationChannel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function webhookChannel(): NotificationChannel
{
    return Organization::factory()->create()->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_WEBHOOK,
        'label' => 'Ops',
        'config' => ['url' => 'https://hooks.example.com/dply', 'headers' => ['X-Dply-Signature' => 'spoof', 'X-Custom' => '1']],
    ]);
}

test('an event is queued with its real event key and context', function () {
    Queue::fake();
    $channel = webhookChannel();

    $channel->sendOperationalMessage('Deploy failed', 'exit 1', 'https://dply.test/x', 'Open', [
        'event_key' => 'site.deployments',
        'severity' => 'error',
        'source' => 'Site 01ABC',
        'dedup_key' => 'dply:site:01ABC:site.deployments',
    ]);

    Queue::assertPushed(DeliverNotificationWebhookJob::class, function (DeliverNotificationWebhookJob $job) use ($channel): bool {
        return $job->channelId === $channel->id
            && $job->payload['event'] === 'site.deployments'
            && $job->payload['severity'] === 'error'
            && $job->payload['subject'] === 'Deploy failed';
    });
});

test('delivery is signed over the exact body sent', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok', 200)]);
    $channel = webhookChannel();

    (new DeliverNotificationWebhookJob($channel->id, ['event' => 'site.deployments', 'action_url' => 'https://dply.test/a/b'], 'delivery-1'))->handle();

    Http::assertSent(function (Request $request) use ($channel): bool {
        $ts = $request->header('X-Dply-Timestamp')[0];
        $expected = 't='.$ts.',v1='.hash_hmac('sha256', $ts.'.'.$request->body(), NotificationChannel::webhookSigningSecret($channel->id));

        return $request->header('X-Dply-Signature')[0] === $expected
            && $request->header('X-Dply-Event')[0] === 'site.deployments'
            && $request->header('X-Dply-Delivery-Id')[0] === 'delivery-1'
            && $request->header('X-Custom')[0] === '1'
            && json_decode($request->body(), true)['action_url'] === 'https://dply.test/a/b';
    });
});

test('transient failures throw so the queue retries; other 4xx do not', function () {
    $channel = webhookChannel();
    $job = new DeliverNotificationWebhookJob($channel->id, ['event' => 'e'], 'd');

    Http::fake(['*' => Http::sequence()->push('', 503)->push('', 429)->push('', 410)]);
    expect(fn () => $job->handle())->toThrow(\RuntimeException::class)
        ->and(fn () => $job->handle())->toThrow(\RuntimeException::class);
    $job->handle(); // 410 is final: no throw
    Http::assertSentCount(3);

    expect($job->tries)->toBe(5)->and($job->backoff())->toBe([10, 60, 300, 900]);
});

test('each channel has its own signing secret', function () {
    expect(NotificationChannel::webhookSigningSecret('a'))->not->toBe(NotificationChannel::webhookSigningSecret('b'));
});

test('the edit form shows the channel\'s signing secret', function () {
    $user = User::factory()->create();
    $org = Organization::factory()->create();
    $org->users()->attach($user->id, ['role' => 'owner']);
    $channel = $org->notificationChannels()->create([
        'type' => NotificationChannel::TYPE_WEBHOOK,
        'label' => 'Ops',
        'config' => ['url' => 'https://hooks.example.com/dply'],
    ]);

    Livewire::actingAs($user)
        ->test(OrgNotificationChannels::class, ['organization' => $org])
        ->assertDontSee(NotificationChannel::webhookSigningSecret($channel->id))
        ->call('startEdit', $channel->id)
        ->assertSee(NotificationChannel::webhookSigningSecret($channel->id));
});
