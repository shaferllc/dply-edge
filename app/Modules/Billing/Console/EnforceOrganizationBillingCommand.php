<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\Organization;
use App\Modules\Billing\Services\OrganizationBillingEnforcer;
use Illuminate\Console\Command;
use Throwable;

/**
 * The trial lifecycle (ruling r-f17p5zgeh120cm5t), hourly and after every
 * billing webhook:
 *
 *   on a trial      email "started" once, "ending" about a day before
 *   no plan         pause: queue workers stop, sites serve a paused page,
 *                   container traffic is gated; email "paused"
 *   plan again      resume everything the pause stopped
 *   paused 7 days   delete the data (OrganizationDataPurger), only when
 *                   subscription.standard.trial.purge_enabled is on; the
 *                   "deleting" email goes two days before
 *
 *   php artisan dply:billing:enforce [--org=] [--dry-run]
 *
 * --dry-run changes nothing and lists what each step would do, including
 * everything a purge would delete.
 */
class EnforceOrganizationBillingCommand extends Command
{
    protected $signature = 'dply:billing:enforce
        {--org= : Only this organization id}
        {--dry-run : List what would happen without changing anything}';

    protected $description = 'Pause orgs whose trial ended unpaid, resume paid ones, send trial emails, and purge after the keep period.';

    public function handle(OrganizationBillingEnforcer $enforcer): int
    {
        $dry = (bool) $this->option('dry-run');
        $organizations = Organization::query()->when($this->option('org'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($organizations as $organization) {
            try {
                $enforcer->enforce($organization, $dry, fn (string $line) => $this->line($line));
            } catch (Throwable $e) {
                report($e);
                $this->error($organization->name.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
