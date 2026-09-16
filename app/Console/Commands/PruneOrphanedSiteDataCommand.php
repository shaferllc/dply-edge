<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Sites\SiteRelationPurger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backfill cleanup: removes rows that reference a Site which no longer exists —
 * the orphans left behind by sites deleted BEFORE SiteRelationPurger wired the
 * cleanup into Site::deleting. Going forward the deleting hook keeps things
 * clean; this command (also scheduled as a weekly safety net) catches anything
 * a crashed delete or a non-Eloquent write left dangling.
 *
 * @see SiteRelationPurger
 */
class PruneOrphanedSiteDataCommand extends Command
{
    protected $signature = 'dply:prune-orphaned-site-data {--dry-run : Report what would be removed without deleting}';

    protected $description = 'Remove rows (errors, logs, polymorphic links) pointing at sites that no longer exist.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $morph = (new Site)->getMorphClass();
        $total = 0;

        // Denormalised site_id columns → delete orphans.
        foreach (SiteRelationPurger::SITE_ID_TABLES as $table) {
            $total += $this->sweep($table, 'site_id', $morph, false, $dry);
        }

        // server_databases: UNLINK orphans (null the owner), never drop the DB.
        $total += $this->sweep('server_databases', 'site_id', $morph, true, $dry);

        // Polymorphic links pointing at a vanished site.
        $total += $this->sweepPoly('console_actions', 'subject_type', 'subject_id', $morph, $dry);
        $total += $this->sweepPoly('notification_subscriptions', 'subscribable_type', 'subscribable_id', $morph, $dry);
        $total += $this->sweepPoly('insight_settings', 'settingsable_type', 'settingsable_id', $morph, $dry);
        $total += $this->sweepPoly('notification_events', 'subject_type', 'subject_id', $morph, $dry);

        $this->info(($dry ? 'Would remove ' : 'Removed ').$total.' orphaned row(s)'.($dry ? ' (dry run).' : '.'));

        return self::SUCCESS;
    }

    private function sweep(string $table, string $column, string $morph, bool $unlink, bool $dry): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $base = DB::table($table)
            ->whereNotNull($column)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sites')->whereColumn('sites.id', $table.'.'.$column));

        $count = (clone $base)->count();
        if ($count > 0) {
            $this->line(sprintf('  %-28s %d', $table, $count));
            if (! $dry) {
                $unlink ? $base->update([$column => null]) : $base->delete();
            }
        }

        return $count;
    }

    private function sweepPoly(string $table, string $typeCol, string $idCol, string $morph, bool $dry): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $typeCol)) {
            return 0;
        }

        $base = DB::table($table)
            ->where($typeCol, $morph)
            ->whereNotNull($idCol)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('sites')->whereColumn('sites.id', $table.'.'.$idCol));

        $count = (clone $base)->count();
        if ($count > 0) {
            $this->line(sprintf('  %-28s %d (polymorphic)', $table, $count));
            if (! $dry) {
                $base->delete();
            }
        }

        return $count;
    }
}
