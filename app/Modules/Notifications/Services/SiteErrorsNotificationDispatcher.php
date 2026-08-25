<?php

namespace App\Modules\Notifications\Services;

use App\Models\ErrorEvent;
use App\Models\Site;
use App\Models\User;
use App\Support\SiteErrorsNotificationKeys;
use Illuminate\Support\Str;

/**
 * Publishes a notification for a single newly-captured {@see ErrorEvent} to the
 * owning {@see Site}'s subscribers — the site-scoped mirror of
 * {@see ServerErrorsNotificationDispatcher}.
 *
 * Only fires for errors tied to a site (deploys, bindings, SSL, env… anything
 * with a site_id); pure server/org-level errors have no site to route to and are
 * skipped here (the server dispatcher covers them). Subject is the {@see Site};
 * the per-kind title is the config label and "Open" deep-links to the source, or
 * to the site's Errors stream as a fallback.
 *
 * The same failure also lands in the server roll-up; the orchestrating
 * {@see ServerErrorsNotificationDispatcher} dedupes the two so a subscriber wired
 * to both surfaces is notified once.
 */
final class SiteErrorsNotificationDispatcher
{
    public function __construct(
        private readonly NotificationPublisher $publisher,
    ) {}

    public function notify(ErrorEvent $event, ?User $actor = null): void
    {
        $site = $event->site;
        if (! $site instanceof Site) {
            return;
        }

        $kind = SiteErrorsNotificationKeys::kindForCategory((string) $event->category);
        $eventKey = SiteErrorsNotificationKeys::eventKey($kind);
        $label = $this->label($eventKey, $kind);

        $title = '['.config('app.name').'] '.$site->name.' — '.$label;

        $lines = [__('Site: :name', ['name' => $site->name])];

        $errorTitle = trim((string) $event->title);
        if ($errorTitle !== '') {
            $lines[] = $errorTitle;
        }

        $detail = trim((string) $event->detail);
        if ($detail !== '') {
            $lines[] = Str::limit($detail, 500);
        }

        if ($actor !== null) {
            $lines[] = __('Actor: :name', ['name' => $actor->name]);
        }

        $fallbackUrl = $site->server
            ? route('sites.show', [$site->server, $site, 'edge-logs'], absolute: true)
            : null;

        $this->publisher->publish(
            eventKey: $eventKey,
            subject: $site,
            title: $title,
            body: implode("\n", $lines),
            url: ($event->link_url ?: null) ?? $fallbackUrl,
            metadata: [
                'site_id' => $site->id,
                'server_id' => $event->server_id,
                'error_event_id' => $event->id,
                'category' => (string) $event->category,
                'kind' => $kind,
            ],
            actor: $actor,
        );
    }

    private function label(string $eventKey, string $kind): string
    {
        $events = (array) config('notification_events.categories.site_errors.events', []);

        return (string) ($events[$eventKey] ?? ucfirst(str_replace('_', ' ', $kind)));
    }
}
