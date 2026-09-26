<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeDeployment;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Notifications\OrganizationBillingNotice;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The trial lifecycle for one org (ruling r-f17p5zgeh120cm5t). Run hourly by
 * dply:billing:enforce and after every billing webhook (SyncOrganizationBillingJob):
 *
 *   on a trial      email "started" once, "ending" about a day before
 *   no plan         pause: queue workers stop, sites serve a paused page,
 *                   container traffic is gated; email "paused"
 *   plan again      resume everything the pause stopped
 *   paused 7 days   delete the data (OrganizationDataPurger), only when
 *                   subscription.standard.trial.purge_enabled is on; the
 *                   "deleting" email goes two days before
 *
 * One run per org at a time (a lock), so the hourly run and a webhook never
 * both send the same email.
 */
final class OrganizationBillingEnforcer
{
    /** @var Closure(string): void */
    private Closure $say;

    public function __construct(
        private OrganizationDataPurger $purger,
        private StarterTrafficGate $gate,
        private EdgeHostMapPublisher $hostMap,
    ) {
        $this->say = static function (string $line): void {};
    }

    /** @param  (Closure(string): void)|null  $say  where to report what it does (the command's output) */
    public function enforce(Organization $org, bool $dry = false, ?Closure $say = null): void
    {
        $this->say = $say ?? static function (string $line): void {};
        Cache::lock('billing-enforce:'.$org->id, 600)->get(fn () => $this->run($org->fresh() ?? $org, $dry));
    }

    private function run(Organization $org, bool $dry): void
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
                ($this->say)($org->name.': resume');
                if (! $dry) {
                    $org->forceFill(['billing_paused_at' => null, 'billing_notices' => array_diff_key((array) $org->billing_notices, array_flip(['paused', 'deleting']))])->save();
                    $this->republish($org);
                    $this->gate->syncOrganization($org);
                    $this->workers($org, false);
                }
            }

            return;
        }

        if ($org->billing_paused_at === null) {
            ($this->say)($org->name.': pause');
            if (! $dry) {
                // Workers first: their pause call goes through the site's URL,
                // which the paused page blocks once it is published.
                $this->workers($org, true);
                $org->forceFill(['billing_paused_at' => now()])->save();
                $this->republish($org);
                $this->gate->syncOrganization($org);
                $this->notice($org, 'paused', false);
            }

            return;
        }

        $keep = (int) config('subscription.standard.trial.keep_data_days', 7);
        $deleteAt = $org->billing_paused_at->copy()->addDays($keep);
        if (! config('subscription.standard.trial.purge_enabled')) {
            if ($dry && $deleteAt->isPast()) {
                ($this->say)($org->name.': would purge (purge is off):');
                foreach ($this->purger->plan($org) as $line) {
                    ($this->say)('  '.$line);
                }
            }

            return;
        }
        if ($deleteAt->copy()->subDays(2)->isPast()) {
            $this->notice($org, 'deleting', $dry, $deleteAt);
        }
        if ($deleteAt->isPast() && ! isset($org->billing_notices['purged'])) {
            $plan = $this->purger->plan($org);
            ($this->say)($org->name.': purge');
            foreach ($plan as $line) {
                ($this->say)('  '.$line);
            }
            if (! $dry) {
                $failed = $this->purger->purge($org);
                if ($failed === []) {
                    $org->forceFill(['billing_notices' => ['purged' => now()->toIso8601String()] + (array) $org->billing_notices])->save();
                } else {
                    ($this->say)($org->name.': '.implode(' · ', $failed));
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
        ($this->say)($org->name.': email '.$kind);
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
    private function republish(Organization $org): void
    {
        $org->refresh();
        foreach (Site::query()->where('organization_id', $org->id)->whereNotNull('edge_backend')->get() as $site) {
            $live = EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)->latest('id')->first();
            if ($live === null) {
                continue;
            }
            try {
                $site->setRelation('organization', $org);
                $this->hostMap->publish($site, $live);
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
