<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Support\Errors\ErrorEventRecorder;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the Site relations that the database FK cascade does NOT clean up on
 * its own, so deleting a site leaves nothing behind:
 *
 *  - Denormalised `site_id` columns with no FK constraint (error_events is the
 *    big one — the Errors stream is written by a sweeper via the query builder,
 *    so it has only an index, not a cascading FK).
 *  - Polymorphic links that point AT the site (console_actions subject,
 *    notification_subscriptions, insight_settings, notification_events) — a
 *    subject_type/subject_id pair can't be a simple cascading FK.
 *
 * Called from Site::deleting (the single chokepoint for every delete path:
 * immediate UI delete + ProcessScheduledSiteDeletionsCommand). Best-effort per
 * table: one failure is logged and the rest still run, so a cleanup hiccup never
 * blocks the site deletion itself.
 *
 * @see ErrorEventRecorder why error_events has no FK
 */
final class SiteRelationPurger
{
    /** Tables with a denormalised `site_id` and no cascading FK → delete the rows. */
    public const SITE_ID_TABLES = [
        'error_events',
        'function_invocations',
        'function_actions',
        'app_logs',
        'site_backends',
        'cloud_database_site',
        'cloud_bucket_site',
        'cloud_workers',
        'cloud_deploy_tasks',
    ];

    /**
     * @return array<string, int> rows removed/updated per table
     */
    public function purge(Site $site): array
    {
        $id = (string) $site->getKey();
        $morph = $site->getMorphClass();
        $counts = [];

        foreach (self::SITE_ID_TABLES as $table) {
            $counts[$table] = $this->run($table, fn (): int => (int) DB::table($table)->where('site_id', $id)->delete());
        }

        // Managed databases are real infra holding real data — UNLINK (null the
        // owner) rather than drop the row on a site delete. Dropping the actual
        // database is a separate, explicit, destructive action.
        $counts['server_databases'] = $this->run(
            'server_databases',
            fn (): int => (int) DB::table('server_databases')->where('site_id', $id)->update(['site_id' => null]),
        );

        // Polymorphic links pointing at this site.
        $counts['console_actions'] = $this->run(
            'console_actions',
            fn (): int => (int) DB::table('console_actions')->where('subject_type', $morph)->where('subject_id', $id)->delete(),
        );
        $counts['notification_subscriptions'] = $this->run(
            'notification_subscriptions',
            fn (): int => (int) DB::table('notification_subscriptions')->where('subscribable_type', $morph)->where('subscribable_id', $id)->delete(),
        );
        $counts['insight_settings'] = $this->run(
            'insight_settings',
            fn (): int => (int) DB::table('insight_settings')->where('settingsable_type', $morph)->where('settingsable_id', $id)->delete(),
        );
        $counts['notification_events'] = $this->run(
            'notification_events',
            fn (): int => (int) DB::table('notification_events')->where('subject_type', $morph)->where('subject_id', $id)->delete(),
        );

        return array_filter($counts);
    }

    private function run(string $table, Closure $op): int
    {
        try {
            if (! Schema::hasTable($table)) {
                return 0;
            }

            return $op();
        } catch (\Throwable $e) {
            Log::warning('SiteRelationPurger: failed purging '.$table, ['error' => $e->getMessage()]);

            return 0;
        }
    }
}
