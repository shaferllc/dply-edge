<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Billing\Services\OrganizationDataPurger;
use App\Modules\Billing\Services\StarterTrafficGate;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Notifications\OrganizationBillingNotice;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
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

    public function handle(OrganizationDataPurger $purger, StarterTrafficGate $gate, EdgeHostMapPublisher $hostMap): int
    {
        $dry = (bool) $this->option('dry-run');
        $organizations = Organization::query()->when($this->option('org'), fn ($q, $id) => $q->whereKey($id))->get();
        foreach ($organizations as $organization) {
            try {
                $this->enforce($organization, $dry, $purger, $gate, $hostMap);
            } catch (Throwable $e) {
                report($e);
                $this->error($organization->name.': '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    private function enforce(Organization $org, bool $dry, OrganizationDataPurger $purger, StarterTrafficGate $gate, EdgeHostMapPublisher $hostMap): void
    {
        if ($org->onTrialPlan()) {
            $ends = $org->planTrialEndsAt();
            $this->notice($org, 'trial_started', $dry, $ends);
            if ($ends !== null && $ends->lte(now()->addDay())) {
                $this->notice($org, 'trial_ending', $dry, $ends);
            }
        }

        // A new org that has not started its trial yet: nothing to pause.
        if (! $org->hasPlan() && $org->trial_ends_at === null && ! $org->subscriptions()->exists()) {
            return;
        }

        if ($org->hasPlan()) {
            if ($org->billing_paused_at !== null) {
                $this->line($org->name.': resume');
                if (! $dry) {
                    $org->forceFill(['billing_paused_at' => null, 'billing_notices' => array_diff_key((array) $org->billing_notices, array_flip(['paused', 'deleting']))])->save();
                    $this->republish($org, $hostMap);
                    $gate->syncOrganization($org);
                    $this->workers($org, false);
                }
            }

            return;
        }

        if ($org->billing_paused_at === null) {
            $this->line($org->name.': pause');
            if (! $dry) {
                // Workers first: their pause call goes through the site's URL,
                // which the paused page blocks once it is published.
                $this->workers($org, true);
                $org->forceFill(['billing_paused_at' => now()])->save();
                $this->republish($org, $hostMap);
                $gate->syncOrganization($org);
                $this->notice($org, 'paused', false);
            }

            return;
        }

        $keep = (int) config('subscription.standard.trial.keep_data_days', 7);
        $deleteAt = $org->billing_paused_at->copy()->addDays($keep);
        if (! config('subscription.standard.trial.purge_enabled')) {
            if ($dry && $deleteAt->isPast()) {
                $this->line($org->name.': would purge (purge is off):');
                foreach ($purger->plan($org) as $line) {
                    $this->line('  '.$line);
                }
            }

            return;
        }
        if ($deleteAt->copy()->subDays(2)->isPast()) {
            $this->notice($org, 'deleting', $dry, $deleteAt);
        }
        if ($deleteAt->isPast() && ! isset($org->billing_notices['purged'])) {
            $plan = $purger->plan($org);
            $this->line($org->name.': purge');
            foreach ($plan as $line) {
                $this->line('  '.$line);
            }
            if (! $dry) {
                $failed = $purger->purge($org);
                if ($failed === []) {
                    $org->forceFill(['billing_notices' => ['purged' => now()->toIso8601String()] + (array) $org->billing_notices])->save();
                } else {
                    $this->error($org->name.': '.implode(' · ', $failed));
                }
            }
        }
    }

    /** Emails each owner once per kind (billing_notices records it). */
    private function notice(Organization $org, string $kind, bool $dry, ?Carbon $date = null): void
    {
        $sent = (array) $org->billing_notices;
        if (isset($sent[$kind])) {
            return;
        }
        $this->line($org->name.': email '.$kind);
        if ($dry) {
            return;
        }
        $owners = $org->users()->wherePivot('role', 'owner')->get();
        if ($owners->isNotEmpty()) {
            Notification::send($owners, new OrganizationBillingNotice($org, $kind, $date));
        }
        $org->forceFill(['billing_notices' => [$kind => now()->toIso8601String()] + $sent])->save();
    }

    /** Publish each live site again so the paused page goes up or comes down. */
    private function republish(Organization $org, EdgeHostMapPublisher $hostMap): void
    {
        $org->refresh();
        foreach (Site::query()->where('organization_id', $org->id)->whereNotNull('edge_backend')->get() as $site) {
            $live = EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)->latest('id')->first();
            if ($live === null) {
                continue;
            }
            try {
                $site->setRelation('organization', $org);
                $hostMap->publish($site, $live);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * Pause the queue workers the billing pause stops, and on resume only
     * those (workers the customer paused stay paused).
     */
    private function workers(Organization $org, bool $pause): void
    {
        $sites = Site::query()->where('organization_id', $org->id)->where('meta->edge->container->workers->enabled', true)->get();
        foreach ($sites as $site) {
            $workers = (array) ($site->edgeMeta()['container']['workers'] ?? []);
            if ($pause ? ($workers['paused'] ?? false) : ! ($workers['billing_paused'] ?? false)) {
                continue; // already paused by its owner / not paused by us
            }
            try {
                EdgeQueueWorkers::pause($site, $pause);
            } catch (Throwable $e) {
                report($e); // asleep or unreachable: the flag below still stops the scaler, and the container gate holds
            }
            $site = $site->fresh();
            $container = (array) ($site->edgeMeta()['container'] ?? []);
            $container['workers']['paused'] = $pause;
            $container['workers']['billing_paused'] = $pause;
            $site->mergeEdgeMeta(['container' => $container]);
            $site->save();
        }
    }
}
