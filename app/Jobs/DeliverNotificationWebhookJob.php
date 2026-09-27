<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationChannel;
use App\Support\Http\UnsafeOutboundUrlException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Delivers one event to an HTTP webhook notification channel. Each attempt is
 * re-signed with a fresh timestamp and re-resolves the URL (PublicOutboundUrl
 * pinning, redirects off). Transient failures — connection errors, 5xx, 429 —
 * throw so the queue retries with backoff; any other 4xx or a refused URL is
 * final. The delivery id stays the same across attempts so receivers can
 * de-duplicate.
 */
class DeliverNotificationWebhookJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $channelId,
        public array $payload,
        public string $deliveryId,
    ) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        $channel = NotificationChannel::query()->find($this->channelId);
        $url = $channel?->config['url'] ?? null;
        if ($channel === null || $channel->type !== NotificationChannel::TYPE_WEBHOOK || ! is_string($url) || $url === '') {
            return;
        }

        try {
            $response = $channel->postSignedWebhook($url, $this->payload, $this->deliveryId);
        } catch (UnsafeOutboundUrlException $e) {
            Log::warning('notification_channel.webhook_refused', ['channel_id' => $channel->id, 'error' => $e->getMessage()]);

            return;
        }

        if ($response->successful()) {
            return;
        }

        if ($response->serverError() || $response->status() === 429) {
            throw new RuntimeException('Webhook endpoint returned HTTP '.$response->status());
        }

        Log::warning('notification_channel.webhook_rejected', [
            'channel_id' => $channel->id,
            'event' => $this->payload['event'] ?? null,
            'status' => $response->status(),
        ]);
    }
}
