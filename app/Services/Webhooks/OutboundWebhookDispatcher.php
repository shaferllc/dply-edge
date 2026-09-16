<?php

namespace App\Services\Webhooks;

use App\Jobs\SendOutboundWebhookJob;
use App\Models\OutboundWebhookDelivery;
use App\Models\Server;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for emitting server-scoped outbound webhooks. Always records
 * a delivery row (even when the server has no URL configured — status `would_send`)
 * so users can see exactly what events Dply emits before wiring an endpoint up.
 */
class OutboundWebhookDispatcher
{
    /**
     * Build a delivery record and queue the HTTP send (if a URL is configured).
     *
     * @param  array<string, mixed>  $payload
     */
    public function dispatchForServer(string $eventKey, Server $server, array $payload, ?string $summary = null): OutboundWebhookDelivery
    {
        $server = $server->fresh() ?? $server;
        $meta = $server->meta ?? [];
        $url = isset($meta['server_event_webhook_url']) && is_string($meta['server_event_webhook_url'])
            ? trim($meta['server_event_webhook_url'])
            : '';
        $secret = $this->resolveSecret($server, $meta);

        $envelope = [
            'event' => $eventKey,
            'occurred_at' => now()->toIso8601String(),
            'server_id' => $server->id,
            'server_name' => $server->name,
            'organization_id' => $server->organization_id,
            'data' => $payload,
        ];
        if ($summary !== null && $summary !== '') {
            $envelope['summary'] = $summary;
        }

        $delivery = OutboundWebhookDelivery::create([
            'organization_id' => $server->organization_id,
            'server_id' => $server->id,
            'event_key' => $eventKey,
            'summary' => $summary !== null && $summary !== '' ? mb_substr($summary, 0, 300) : null,
            'payload' => $envelope,
            'url' => $url !== '' ? $url : null,
            'signed' => $secret !== null,
            'status' => $url === ''
                ? OutboundWebhookDelivery::STATUS_WOULD_SEND
                : OutboundWebhookDelivery::STATUS_PENDING,
        ]);

        if ($url !== '') {
            SendOutboundWebhookJob::dispatch($delivery->id);
        }

        return $delivery;
    }

    /**
     * Re-queue an existing delivery row (used for the "Resend" button). Resets the row
     * to pending and lets the send job run a fresh attempt sequence.
     */
    public function resend(OutboundWebhookDelivery $delivery): OutboundWebhookDelivery
    {
        if ($delivery->url === null || $delivery->url === '') {
            return $delivery;
        }

        $delivery->update([
            'status' => OutboundWebhookDelivery::STATUS_PENDING,
            'http_status' => null,
            'response_excerpt' => null,
            'error_message' => null,
            'completed_at' => null,
        ]);

        SendOutboundWebhookJob::dispatch($delivery->id);

        return $delivery;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveSecret(Server $server, array $meta): ?string
    {
        $enc = $meta['server_event_webhook_secret'] ?? null;
        if (! is_string($enc) || $enc === '') {
            return null;
        }

        try {
            return Crypt::decryptString($enc);
        } catch (\Throwable $e) {
            Log::warning('outbound_webhook secret decrypt failed', [
                'server_id' => $server->id,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
