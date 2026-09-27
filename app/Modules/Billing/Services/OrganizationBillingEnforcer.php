<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Models\EdgeDeployment;
use App\Models\EdgeSiteEnvVar;
use App\Models\Organization;
use App\Models\Site;
use App\Modules\Edge\Actions\CancelStuckEdgeDeployment;
use App\Modules\Edge\Services\EdgeAppDatabase;
use App\Modules\Edge\Services\EdgeHostMapPublisher;
use App\Modules\Edge\Support\EdgeContainerConnections;
use App\Modules\Edge\Support\EdgeDplyDatabase;
use App\Modules\Edge\Support\EdgeQueueWorkers;
use App\Modules\Edge\Support\EdgeValkey;
use App\Modules\Notifications\Services\NotificationPublisher;
use App\Modules\Providers\Valkey\ValkeyGatewayClient;
use App\Notifications\OrganizationBillingNotice;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * The trial lifecycle for one org (ruling r-f17p5zgeh120cm5t). Run hourly by
 * dply:billing:enforce, every 5 minutes for a running trial (--trialing), and
 * after every billing webhook (SyncOrganizationBillingJob):
 *
 *   on a trial      email "started" once, "ending_soon" 3 days before,
 *                   "ending" about a day before
 *   trial past cap  usage passed the trial's spending cap (StarterUsageBudget):
 *                   pause as below until the trial converts; email
 *                   "capped" and the edge.usage.over_budget notification
 *   no plan         pause: builds in flight stop, queue workers stop, sites serve a paused page,
 *                   container traffic is gated, dply Valkey and database
 *                   tenants are put to sleep; email "paused"
 *   plan again      resume everything the pause stopped
 *   paused 30 days  delete the data (OrganizationDataPurger) when
 *                   subscription.standard.trial.purge_enabled is on (the
 *                   default); "deleting_soon" is emailed 7 days before and
 *                   "deleting" a day before, and deletion waits for each
 *                   warning's full lead time ({@see deleteAt()})
 *
 * One run per org at a time (a lock), so the hourly run and a webhook never
 * both send the same email.
 */
final class OrganizationBillingEnforcer
{
    /** Idle seconds before a paused org's Valkey or database sleeps again. */
    private const PAUSED_SLEEP = 60;

    /** Deletion warnings: notice kind => days before deletion it goes out. */
    public const DELETE_WARNINGS = ['deleting_soon' => 7, 'deleting' => 1];

    /**
     * When a paused org's data is deleted: keep_data_days after the pause,
     * pushed back so each warning already sent keeps its full lead time. An
     * org already past its keep period when purging is switched on is warned
     * first and deleted 7 days later, never in the same run. Null when not paused.
     */
    public static function deleteAt(Organization $org): ?CarbonInterface
    {
        if ($org->billing_paused_at === null) {
            return null;
        }
        $notices = (array) $org->billing_notices;
        $at = $org->billing_paused_at->copy()->addDays((int) config('subscription.standard.trial.keep_data_days', 30));
        foreach (self::DELETE_WARNINGS as $kind => $days) {
            if (isset($notices[$kind])) {
                $at = $at->max(Carbon::parse($notices[$kind])->addDays($days));
            }
        }

        return $at;
    }

    /** @var Closure(string): void */
    private Closure $say;

    public function __construct(
        private OrganizationDataPurger $purger,
        private StarterTrafficGate $gate,
        private EdgeHostMapPublisher $hostMap,
        private StarterUsageBudget $budget,
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
        $capped = false;
        if ($org->onTrialPlan()) {
            $ends = $org->planTrialEndsAt();
            $this->notice($org, 'trial_started', $dry, $ends);
            if ($ends !== null && $ends->lte(now()->addDay())) {
                $this->notice($org, 'trial_ending', $dry, $ends);
            } elseif ($ends !== null && $ends->lte(now()->addDays(3))) {
                $this->notice($org, 'trial_ending_soon', $dry, $ends);
            }
            $capped = $this->budget->status($org)['exhausted'];
        }

        // A new org that has not started its trial yet: nothing to pause.
        if (! $org->hasPlan() && $org->trial_ends_at === null && ! $org->subscriptions()->exists()) {
            return;
        }

        if ($org->hasPlan() && ! $capped) {
            if ($org->billing_paused_at !== null) {
                ($this->say)($org->name.': resume');
                if (! $dry) {
                    // Every pause-scoped notice goes, so a later pause warns (and purges) afresh.
                    $org->forceFill(['billing_paused_at' => null, 'billing_notices' => array_diff_key((array) $org->billing_notices, array_flip(['paused', 'capped', 'purged', ...array_keys(self::DELETE_WARNINGS)]))])->save();
                    $this->republish($org);
                    $this->gate->syncOrganization($org);
                    $this->workers($org, false);
                    $this->dataStores($org, false);
                }
            }

            return;
        }

        if ($capped) {
            if ($org->billing_paused_at === null) {
                ($this->say)($org->name.': pause (trial spending cap)');
                if (! $dry) {
                    $this->pause($org);
                    $this->notice($org, 'capped', false, $org->planTrialEndsAt());
                    $this->overBudget($org);
                }
            }

            return;
        }

        // Paused over the cap, now without a plan: the "paused" email and the
        // keep period start here.
        $notices = (array) $org->billing_notices;
        if ($org->billing_paused_at === null || (isset($notices['capped']) && ! isset($notices['paused']))) {
            ($this->say)($org->name.': pause');
            if (! $dry) {
                if ($org->billing_paused_at === null) {
                    $this->pause($org);
                } else {
                    $org->forceFill(['billing_paused_at' => now()])->save();
                    $this->republish($org);
                }
                $this->notice($org, 'paused', false);
            }

            return;
        }

        $deleteAt = self::deleteAt($org);
        if (! config('subscription.standard.trial.purge_enabled')) {
            if ($dry && $deleteAt->isPast()) {
                ($this->say)($org->name.': would purge (purge is off):');
                foreach ($this->purger->plan($org) as $line) {
                    ($this->say)('  '.$line);
                }
            }

            return;
        }
        // Each warning goes out with its full lead time: one sent late (the
        // run missed, or purge was just switched on) pushes deletion back.
        $warned = true;
        foreach (self::DELETE_WARNINGS as $kind => $days) {
            if (isset(((array) $org->billing_notices)[$kind])) {
                continue;
            }
            if (now()->lt($deleteAt->copy()->subDays($days))) {
                $warned = false;

                continue;
            }
            $deleteAt = $deleteAt->max(now()->addDays($days));
            $this->notice($org, $kind, $dry, $deleteAt);
            $warned = $warned && ! $dry;
        }
        if ($warned && $deleteAt->isPast() && ! isset($org->billing_notices['purged'])) {
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
    private function notice(Organization $org, string $kind, bool $dry, ?CarbonInterface $date = null): void
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

    private function pause(Organization $org): void
    {
        // Workers first: their pause call goes through the site's URL,
        // which the paused page blocks once it is published.
        $this->workers($org, true);
        $org->forceFill(['billing_paused_at' => now()])->save();
        $this->republish($org);
        $this->gate->syncOrganization($org);
        $this->dataStores($org, true);
        $this->stopBuilds($org);
    }

    /** Builds bill by the second, so a pause stops the ones in flight. */
    private function stopBuilds(Organization $org): void
    {
        $inFlight = EdgeDeployment::query()
            ->where('organization_id', $org->id)
            ->whereIn('status', [EdgeDeployment::STATUS_BUILDING, EdgeDeployment::STATUS_PUBLISHING])
            ->with('site')
            ->get();
        foreach ($inFlight as $deployment) {
            if ($deployment->site === null) {
                continue;
            }
            $this->attempt(fn () => app(CancelStuckEdgeDeployment::class)->abandon($deployment->site, $deployment, __('Stopped: this organization is paused (billing).')));
        }
    }

    /** The same notification a build past the cap sends (BuildEdgeSiteJob), once a month. */
    private function overBudget(Organization $org): void
    {
        $site = Site::query()->where('organization_id', $org->id)->whereNotNull('edge_backend')->first();
        if ($site === null || ! Cache::add('starter-spend:'.$org->id.':'.now()->format('Y-m').':over', true, now()->endOfMonth())) {
            return;
        }
        $limit = number_format(((int) config('subscription.standard.trial.spending_limit_cents')) / 100, 0);
        try {
            app(NotificationPublisher::class)->publish(
                eventKey: 'edge.usage.over_budget',
                subject: $site,
                title: __('Usage credit used up'),
                body: __('The trial’s $:limit usage credit is used up, so your sites are paused until the trial ends. End it early on the billing page to continue now.', ['limit' => $limit]),
                url: route('billing.show', ['organization' => $org->id]),
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * dply Valkey and database tenants keep costing while awake, and a
     * "stays on" one never sleeps. Paused: each sleeps now and a minute after
     * any wake. Resumed: the owner's settings, still on the site's meta
     * (meta.edge.valkey_sleep, meta.edge.database.suspend), go back.
     * Without the password (from the app's env) only the sleep call is made:
     * a PUT with a wrong one would change it.
     */
    private function dataStores(Organization $org, bool $pause): void
    {
        if (! ValkeyGatewayClient::configured()) {
            return;
        }
        foreach (Site::query()->where('organization_id', $org->id)->whereNotNull('edge_backend')->get() as $site) {
            $env = fn (string $key): string => (string) ($site->edgeEnvVars()->where('scope', EdgeSiteEnvVar::SCOPE_PRODUCTION)->where('key', $key)->first()?->value ?? '');
            $database = $site->edgeMeta()['database'] ?? null;
            if (is_array($database) && EdgeAppDatabase::isDply($database) && (string) ($database['remote_id'] ?? '') !== '') {
                $id = (string) $database['remote_id'];
                $engine = (string) ($database['engine'] ?? 'postgres');
                $region = EdgeDplyDatabase::regionOf($database);
                $password = $engine === 'mongodb' ? rawurldecode((string) (parse_url($env('MONGODB_URI'), PHP_URL_PASS) ?? '')) : $env('DB_PASSWORD');
                $this->attempt(function () use ($id, $engine, $region, $password, $database, $pause): void {
                    if ($password !== '' && ($pause || isset($database['suspend']))) {
                        EdgeDplyDatabase::update($id, $password, (string) ($database['size'] ?? ''), $pause ? self::PAUSED_SLEEP : (int) $database['suspend'], (int) ($database['disk_gb'] ?? 0), $engine, $region);
                    }
                    if ($pause) {
                        ValkeyGatewayClient::fromConfig($region)->sleep($id);
                    }
                });
            }

            $sleeps = (array) ($site->edgeMeta()['valkey_sleep'] ?? []);
            foreach (EdgeContainerConnections::for($site) as $connection) {
                if ($connection['kind'] !== 'redis' || ! EdgeValkey::isTarget($connection['target'])) {
                    continue;
                }
                $target = $connection['target'];
                $id = EdgeValkey::tenantId($target);
                $class = $connection['plan'] !== '' ? $connection['plan'] : EdgeValkey::DEFAULT_CLASS;
                $url = $env('REDIS_URL');
                $password = rawurldecode((string) (parse_url($url, PHP_URL_PASS) ?? ''));
                $this->attempt(function () use ($target, $id, $class, $url, $password, $sleeps, $pause, $connection): void {
                    $client = ValkeyGatewayClient::fromConfig(EdgeValkey::region($target));
                    if ($password === '') {
                        $pause && $client->sleep($id);

                        return;
                    }
                    if (! $pause) {
                        // Keep a store the user put to sleep asleep; only restore its own sleep setting otherwise.
                        EdgeValkey::setAsleep($target, $url, $class, (int) ($sleeps[$target] ?? $sleeps[$id] ?? EdgeValkey::DEFAULT_SLEEP), $connection['asleep']);

                        return;
                    }
                    $spec = EdgeValkey::CLASSES[$class];
                    $client->put($id, $password, $spec['memory_mb'], self::PAUSED_SLEEP, ! $spec['sleeps']);
                    $client->sleep($id);
                });
            }
        }
    }

    private function attempt(Closure $run): void
    {
        try {
            $run();
        } catch (Throwable $e) {
            report($e); // unreachable gateway: the next pause/resume run does not retry, the paused page and gate still hold
        }
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
