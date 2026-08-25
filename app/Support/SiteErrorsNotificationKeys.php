<?php

namespace App\Support;

use App\Models\ErrorEvent;

/**
 * Notification event keys for a single site's error stream, surfaced on the
 * /servers/{server}/sites/{site}/errors workspace. The `site.` prefix maps these
 * to the Site subscribable in {@see NotificationSubscriptionRules::subscribableClassForEvent};
 * they are listed under the "site_errors" category in config/notification_events.php.
 *
 * The site mirror of {@see ServerErrorsNotificationKeys}: the same two discrete
 * kinds (one notification per newly-captured {@see ErrorEvent} row),
 * but scoped to the owning site rather than the whole box. A site error appears
 * in both the site stream and the server roll-up; routing is deduped per channel
 * and per in-app recipient in {@see ServerErrorsNotificationDispatcher}
 * so a subscriber wired to both surfaces is still notified once.
 */
final class SiteErrorsNotificationKeys
{
    /** @var list<string> */
    public const KINDS = ['deploy_failed', 'operation_failed'];

    public static function eventKey(string $kind): string
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new \InvalidArgumentException('Invalid errors notify kind.');
        }

        return 'site.errors.'.$kind;
    }

    /**
     * @return list<string>
     */
    public static function eventKeys(): array
    {
        return array_map(static fn (string $kind) => self::eventKey($kind), self::KINDS);
    }

    /** Map a captured error's category onto its notify kind. */
    public static function kindForCategory(string $category): string
    {
        return $category === 'deploy' ? 'deploy_failed' : 'operation_failed';
    }
}
