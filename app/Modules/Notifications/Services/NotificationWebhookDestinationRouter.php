<?php

namespace App\Modules\Notifications\Services;

use App\Models\NotificationChannel;
use App\Models\NotificationEvent;
use App\Models\NotificationWebhookDestination;
use Illuminate\Support\Facades\Log;

/**
 * DEAD CODE (2026-08-14) — no longer called.
 *
 * NotificationRoutingResolver used to invoke this immediately after fanning out
 * to notification channels, producing two deliveries for the same event. That
 * call is gone; this file is retained only alongside the deprecated
 * NotificationWebhookDestination model and should be deleted with it.
 */
class NotificationWebhookDestinationRouter
{
    public function route(NotificationEvent $event): void
    {
        $hookEvent = $this->mapEvent($event);
        if ($hookEvent === null || $event->organization_id === null) {
            return;
        }

        $destinations = NotificationWebhookDestination::query()
            ->where('organization_id', $event->organization_id)
            ->where('enabled', true)
            ->when($this->siteId($event) !== null, function ($query) use ($event): void {
                $siteId = $this->siteId($event);
                $query->where(function ($q) use ($siteId): void {
                    $q->whereNull('site_id')
                        ->orWhere('site_id', $siteId);
                });
            }, function ($query): void {
                $query->whereNull('site_id');
            })
            ->get();

        foreach ($destinations as $destination) {
            if (! $destination->wantsEvent($hookEvent)) {
                continue;
            }

            $this->sendOne($destination, $event, $hookEvent);
        }
    }

    private function mapEvent(NotificationEvent $event): ?string
    {
        if ($event->event_key === 'site.deployments') {
            return match ((string) ($event->metadata['status'] ?? '')) {
                'success' => 'deploy_success',
                'failed' => 'deploy_failed',
                'skipped' => 'deploy_skipped',
                default => null,
            };
        }

        if ($event->event_key === 'site.deployment_started') {
            return 'deploy_started';
        }

        if ($event->event_key === 'site.uptime.down') {
            return match ((string) ($event->metadata['state'] ?? '')) {
                'down' => 'uptime_down',
                'recovered' => 'uptime_recovered',
                default => null,
            };
        }

        if ($event->event_key === 'site.uptime.degraded') {
            return 'uptime_degraded';
        }

        if ($event->event_key === 'site.ssl.expiring') {
            return 'ssl_expiring';
        }

        if ($event->event_key === 'server.insights_alerts') {
            return ($event->metadata['insight_state'] ?? 'opened') === 'resolved'
                ? 'insight_resolved'
                : 'insight_opened';
        }

        return null;
    }

    private function siteId(NotificationEvent $event): ?string
    {
        $siteId = $event->metadata['site_id'] ?? null;

        return is_scalar($siteId) && $siteId !== '' ? (string) $siteId : null;
    }

    private function sendOne(NotificationWebhookDestination $destination, NotificationEvent $event, string $hookEvent): void
    {
        $url = $destination->webhook_url;
        if ($url === '') {
            return;
        }

        $text = $event->title;
        if (filled($event->body)) {
            $text .= "\n".$event->body;
        }
        if (filled($event->url)) {
            $text .= "\n".$event->url;
        }

        try {
            $payload = match ($destination->driver) {
                NotificationWebhookDestination::DRIVER_DISCORD => ['content' => $text],
                NotificationWebhookDestination::DRIVER_TEAMS => [
                    '@type' => 'MessageCard',
                    '@context' => 'https://schema.org/extensions',
                    'summary' => $event->title,
                    'title' => $event->title,
                    'text' => $text,
                ],
                default => ['text' => $text],
            };

            $response = NotificationChannel::postToWebhookUrl($url, $payload, ['Accept' => 'application/json']);
            if (! $response->successful()) {
                Log::warning('notification webhook destination failed', [
                    'hook_id' => $destination->id,
                    'event' => $hookEvent,
                    'status' => $response->status(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('notification webhook destination exception', [
                'hook_id' => $destination->id,
                'event' => $hookEvent,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
