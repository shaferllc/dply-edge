<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\EdgeDeployment;
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
 *   paused 30 days  delete the data (OrganizationDataPurger) while
 *                   subscription.standard.trial.purge_enabled is on (the
 *                   default); warning emails go 7 days and 1 day before
 *
 *   php artisan dply:billing:enforce [--org=] [--dry-run] [--trialing]
 *
 * --trialing (every 5 minutes) checks only unpaused trials (and paid orgs
 * with a spending cap) with something
 * that bills while it runs (a container app, which is where workers,
 * databases and Valkey hang, or a build in flight), so a trial is paused
 * within minutes of its cap instead of within the hour.
 *
 * --dry-run changes nothing and lists what each step would do, including
 * everything a purge would delete.
 */
class EnforceOrganizationBillingCommand extends Command
{
    protected $signature = 'dply:billing:enforce
        {--org= : Only this organization id}
        {--dry-run : List what would happen without changing anything}
        {--trialing : Only unpaused trials with something running (the 5-minute cap check)}';

    protected $description = 'Pause orgs whose trial ended unpaid, resume paid ones, send trial emails, and purge after the keep period.';

    public function handle(OrganizationBillingEnforcer $enforcer): int
    {
        $dry = (bool) $this->option('dry-run');
        $organizations = Organization::query()
            ->when($this->option('org'), fn ($q, $id) => $q->whereKey($id))
            ->when($this->option('trialing'), fn ($q) => $q
                ->whereNull('billing_paused_at')
                ->where(fn ($q) => $q->whereNull('comped_until')->orWhere('comped_until', '<=', now()))
                ->where(fn ($q) => $q->where('trial_ends_at', '>', now())->orWhereNotNull('spending_cap_cents')->orWhereHas('subscriptions', fn ($s) => $s->where('stripe_status', 'trialing')))
                ->where(fn ($q) => $q
                    ->whereHas('sites', fn ($s) => $s->where('meta->edge->runtime_mode', 'container'))
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from('edge_deployments')
                        ->whereColumn('edge_deployments.organization_id', 'organizations.id')
                        ->whereIn('edge_deployments.status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING]))))
            ->get();
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
