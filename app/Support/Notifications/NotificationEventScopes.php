<?php

declare(strict_types=1);

namespace App\Support\Notifications;

use App\Models\Server;
use App\Models\Site;
use App\Support\SiteUptimeNotificationKeys;

/**
 * Which notification events belong to which subject.
 *
 * The web UI answers this implicitly — each workspace tab hardcodes the key set
 * it manages ({@see SiteUptimeNotificationKeys} and friends), which
 * works when a human is looking at one tab. A client asking "what can I
 * subscribe this site to?" needs the whole answer in one place, so this derives
 * it from `config('notification_events')` and the subject itself.
 *
 * Grouping is by event-key prefix, which the config already partitions cleanly:
 * `site.*` categories belong to a site, `server.*` and `backup.*` to a server.
 * Edge and serverless categories attach to the sites that are those products.
 *
 * Deliberately unattached: `queue.*`, `project.*`, `import.*`, `account.*` and
 * `worker_pool.*` are org- or flow-level surfaces subscribed elsewhere, not per
 * site/server — {@see all()} still lists them so `dply notifications events`
 * shows the full catalog.
 */
final class NotificationEventScopes
{
    /**
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    public static function forSite(Site $site): array
    {
        $groups = self::groupsWithPrefix('site.');

        $kind = $site->siteKind();

        if ($kind === 'edge') {
            $groups += self::group('edge');
        }

        if ($kind === 'serverless') {
            $groups += self::group('serverless');
        }

        return $groups;
    }

    /**
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    public static function forServer(Server $server): array
    {
        return self::groupsWithPrefix('server.') + self::groupsWithPrefix('backup.');
    }

    /**
     * Every category in the catalog, subject or not.
     *
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    public static function all(): array
    {
        $groups = [];

        foreach (self::categories() as $key => $category) {
            $groups[$key] = self::shape($category);
        }

        return $groups;
    }

    /**
     * Flat list of the keys a subject may be subscribed to.
     *
     * @param  array<string, array{label: string, events: array<string, string>}>  $groups
     * @return list<string>
     */
    public static function keys(array $groups): array
    {
        $keys = [];

        foreach ($groups as $group) {
            foreach (array_keys($group['events']) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    private static function groupsWithPrefix(string $prefix): array
    {
        $groups = [];

        foreach (self::categories() as $key => $category) {
            $events = (array) ($category['events'] ?? []);

            if ($events === []) {
                continue;
            }

            // Every key in the category must match — a mixed category would be
            // ambiguous, and today there are none.
            foreach (array_keys($events) as $eventKey) {
                if (! str_starts_with((string) $eventKey, $prefix)) {
                    continue 2;
                }
            }

            $groups[(string) $key] = self::shape($category);
        }

        return $groups;
    }

    /**
     * @return array<string, array{label: string, events: array<string, string>}>
     */
    private static function group(string $categoryKey): array
    {
        $category = self::categories()[$categoryKey] ?? null;

        return $category === null ? [] : [$categoryKey => self::shape((array) $category)];
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array{label: string, events: array<string, string>}
     */
    private static function shape(array $category): array
    {
        /** @var array<string, string> $events */
        $events = array_map(static fn (mixed $label): string => (string) $label, (array) ($category['events'] ?? []));

        return [
            'label' => (string) ($category['label'] ?? ''),
            'events' => $events,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function categories(): array
    {
        /** @var array<string, array<string, mixed>> $categories */
        $categories = (array) config('notification_events.categories', []);

        return $categories;
    }
}
