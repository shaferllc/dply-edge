<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Modules\Billing\Jobs\SyncOrganizationBillingJob;
use App\Modules\Billing\Services\OrganizationBillingStateComputer;
use Illuminate\Console\Command;

/**
 * Nightly safety sweep: dispatches a SyncOrganizationBillingJob for every
 * organization that has a Cashier subscription. The event-driven path (see
 * ServerObserver) covers the bulk of changes in real time; this sweep catches
 * any drift — servers added or removed outside the observer, missed events
 * from worker outages, manual SQL fixups, etc.
 *
 * Each dispatched job is unique-by-org-ID, so overlapping schedules collapse.
 *
 * Example:
 *   php artisan dply:billing:sync-all
 *   php artisan dply:billing:sync-all --dry-run
 */
class SyncAllOrganizationBillingCommand extends Command
{
    protected $signature = 'dply:billing:sync-all
                            {--dry-run : List orgs without dispatching}';

    protected $description = 'Reconcile every Standard-subscription org against its current server fleet.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Pull every org with at least one Cashier subscription row. The job
        // itself checks onStandardSubscription() and skips legacy/canceled orgs.
        $orgs = Organization::query()
            ->whereHas('subscriptions')
            ->orderBy('created_at')
            ->get();

        if ($orgs->isEmpty()) {
            $this->info('No subscribed organizations found.');

            return self::SUCCESS;
        }

        $dispatched = 0;

        foreach ($orgs as $org) {
            if ($dryRun) {
                // Pre-tier subscriptions get swapped onto a plan (invoiced now) —
                // show who and for how much before anyone runs it for real.
                if ($org->onStandardSubscription() && $org->subscribedTier() === null) {
                    $desired = app(OrganizationBillingStateComputer::class)->compute($org);
                    $this->line(sprintf(
                        '[dry-run] would MOVE org=%s (%s) → %s at $%s/mo (%d sites, %d seats)',
                        $org->id, $org->name, $desired->planLabel, number_format($desired->monthlyTotalCents / 100, 2),
                        $desired->edgeCount, $desired->seatCount,
                    ));
                } else {
                    $this->line(sprintf('[dry-run] would sync org=%s', $org->id));
                }

                continue;
            }

            SyncOrganizationBillingJob::dispatch($org->id, 'nightly_sweep');
            $dispatched++;
        }

        if ($dryRun) {
            $this->info(sprintf('Dry-run: %d org(s) would have been synced.', $orgs->count()));

            return self::SUCCESS;
        }

        $this->info(sprintf('Dispatched %d billing sync job(s).', $dispatched));

        return self::SUCCESS;
    }
}
