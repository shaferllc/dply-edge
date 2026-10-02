<?php

declare(strict_types=1);

namespace App\Console\Scheduling;

use App\Console\Commands\CheckGitProviderTokensCommand;
use App\Console\Commands\CheckProviderCredentialsCommand;
use App\Console\Commands\DispatchSiteUptimeChecksCommand;
use App\Console\Commands\DispatchSiteUrlHealthChecksCommand;
use App\Console\Commands\ProcessScheduledSiteDeletionsCommand;
use App\Console\Commands\PruneAuditLogsCommand;
use App\Console\Commands\PruneErrorEventsCommand;
use App\Console\Commands\PruneNotificationInboxItemsCommand;
use App\Console\Commands\PruneOrphanedSiteDataCommand;
use App\Console\Commands\PruneSiteUptimeCheckResultsCommand;
use App\Console\Commands\ReapStuckConsoleActionsCommand;
use App\Console\Commands\SyncErrorEventsCommand;
use App\Modules\Billing\Console\EnforceOrganizationBillingCommand;
use App\Modules\Billing\Console\SnapshotOrganizationBillingCommand;
use App\Modules\Billing\Console\SyncAllOrganizationBillingCommand;
use App\Modules\Edge\Console\CheckEdgeBuildersCommand;
use App\Modules\Edge\Console\CheckEdgeQueueWorkersCommand;
use App\Modules\Edge\Console\CheckEdgeRealtimeCommand;
use App\Modules\Edge\Console\CheckEdgeRumAlertsCommand;
use App\Modules\Edge\Console\CollectEdgeContainerUsageCommand;
use App\Modules\Edge\Console\CollectEdgeDataUsageCommand;
use App\Modules\Edge\Console\CollectEdgeKvUsageCommand;
use App\Modules\Edge\Console\CollectEdgeMessagesUsageCommand;
use App\Modules\Edge\Console\CollectEdgePlatformUsageCommand;
use App\Modules\Edge\Console\CollectEdgeRealtimeUsageCommand;
use App\Modules\Edge\Console\CollectEdgeUsageCommand;
use App\Modules\Edge\Console\CollectEdgeValkeyUsageCommand;
use App\Modules\Edge\Console\EvaluateEdgeGuardrailsCommand;
use App\Modules\Edge\Console\ReapStuckEdgeBuildsCommand;
use App\Modules\Edge\Console\ResizeEdgeDatabasesCommand;
use App\Modules\Edge\Console\RollupEdgeAnalyticsEngineCommand;
use App\Modules\Edge\Console\SampleContainerMemoryCommand;
use App\Modules\Edge\Console\SampleEdgeDatabasesCommand;
use App\Modules\Edge\Console\ScaleEdgeQueueWorkersCommand;
use App\Modules\Edge\Console\SweepDatabaseBackupsCommand;
use App\Modules\Edge\Console\VerifyDatabaseBackupsCommand;
use App\Modules\Edge\Console\WarmEdgeBuildImagesCommand;
use App\Modules\Edge\Console\WarmEdgeContainersCommand;
use App\Modules\Edge\Jobs\CheckEdgeDnsZonesJob;
use App\Modules\Edge\Jobs\VerifyEdgeCustomDomainsJob;
use App\Modules\Edge\Services\Realtime\EdgeRealtimeMonitor;
use App\Modules\Secrets\Console\SecretsEscrowCommand;
use App\Modules\Secrets\Console\SecretsRestoreDrillCommand;
use App\Support\DplyRuntime;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\QueuedCommand;

final class DplySchedule
{
    public static function register(Schedule $schedule): void
    {
        $schedule->command(DispatchSiteUrlHealthChecksCommand::class)
            ->everyTenMinutes()
            ->name('dispatch-site-url-health-checks');

        $schedule->command(DispatchSiteUptimeChecksCommand::class)
            ->everyFiveMinutes()
            ->name('dispatch-site-uptime-checks');

        // Validate stored Git tokens + capture real expiry, and warn owners
        // BEFORE an expired token starts failing deploys at clone time.
        $schedule->command(CheckGitProviderTokensCommand::class)
            ->dailyAt('03:35')
            ->withoutOverlapping()
            ->name('check-git-provider-tokens');

        // Provider API tokens rot independently of Git. Catch a revoked
        // Cloudflare/DigitalOcean key here instead of during a deploy.
        $schedule->command(CheckProviderCredentialsCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->name('check-provider-credentials');

        $schedule->command(ProcessScheduledSiteDeletionsCommand::class)->everyMinute();

        // Fail console actions a restarted worker stranded mid-flight, so their
        // page-top banners stop spinning forever (conservative thresholds — never
        // touches a legitimately long-running job).
        $schedule->command(ReapStuckConsoleActionsCommand::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('reap-stuck-console-actions');

        $schedule->command(SyncAllOrganizationBillingCommand::class)->dailyAt('02:30');
        // Trials: emails, pause when unpaid, resume when paid (ruling r-f17p5zgeh120cm5t).
        $schedule->command(EnforceOrganizationBillingCommand::class)->hourly()->withoutOverlapping()->onOneServer();
        // Running trials against their cap every 5 minutes, with a fresh estimate
        // of what they spent since the hourly usage collection (TrialRunningCost).
        $schedule->command(EnforceOrganizationBillingCommand::class, ['--trialing'])->everyFiveMinutes()->withoutOverlapping()->onOneServer()->name('billing-enforce-trialing');
        $schedule->command(SnapshotOrganizationBillingCommand::class)->dailyAt('02:10');

        // Value-less flags must be scheduled as `--today` (not `--today => true`,
        // which becomes `--today=1` and Symfony rejects). That bug left MTD at 0.
        $schedule->command(CollectEdgeUsageCommand::class, ['--today'])
            ->hourly()
            ->name('edge-usage-today');
        // The hourly run never sees a day's last hour: collect yesterday in full.
        $schedule->command(CollectEdgeUsageCommand::class)
            ->dailyAt('01:30')
            ->name('edge-usage-yesterday');

        // Container compute: today so far every hour, and yesterday once more
        // after Cloudflare's late samples land (per-minute billing reads both).
        $schedule->command(CollectEdgeContainerUsageCommand::class, ['--today'])
            ->hourly()
            ->withoutOverlapping()
            ->name('edge-container-usage-today');
        $schedule->command(CollectEdgeContainerUsageCommand::class)
            ->dailyAt('01:40')
            ->name('edge-container-usage-yesterday');
        // Min instances, scaling windows and always-on jobs instances. Windows
        // start on the minute, so an instance can take up to 5 minutes to follow.
        // Every minute: a deploy's rolling update restarts containers after the
        // deploy's own warm, and always-on workers should not wait long.
        $schedule->command(WarmEdgeContainersCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->name('edge-warm-containers');
        // Autoscaled queue workers follow each app's backlog.
        $schedule->command(ScaleEdgeQueueWorkersCommand::class, ['--for' => 50, '--every' => 10])
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground() // a slow neighbour must not delay scaling
            ->name('edge-scale-queue-workers');
        // Failing jobs and crash-looping workers, from the workers' logs.
        $schedule->command(CheckEdgeQueueWorkersCommand::class)
            ->everyFiveMinutes()
            ->withoutOverlapping()
            ->runInBackground() // a slow neighbour must not delay scaling
            ->name('edge-check-queue-workers');
        // dply database history and disk / connection alerts, from
        // each database's last snapshot (never wakes one).
        // Restore every dply Postgres database from its backups (VerifyDatabaseBackupJob).
        $schedule->command(VerifyDatabaseBackupsCommand::class)
            ->weeklyOn(0, '04:00')
            ->withoutOverlapping()
            ->name('databases-verify-backups');
        // Leftover backups of deleted databases, once the bucket lock lets go.
        $schedule->command(SweepDatabaseBackupsCommand::class)
            ->dailyAt('05:10')
            ->withoutOverlapping()
            ->name('databases-sweep-backups');
        $schedule->command(SampleEdgeDatabasesCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->name('edge-sample-databases');
        // Database resizes someone approved for tonight (EdgeDatabaseResize).
        $schedule->command(ResizeEdgeDatabasesCommand::class)
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->name('edge-resize-databases');
        // Peak memory of awake container apps, for smaller-size suggestions.
        $schedule->command(SampleContainerMemoryCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->runInBackground()
            ->name('edge-sample-container-memory');
        $schedule->command(CollectEdgeDataUsageCommand::class, ['--today'])
            ->hourly()
            ->withoutOverlapping()
            ->name('edge-data-usage-today');
        $schedule->command(CollectEdgeDataUsageCommand::class)
            ->dailyAt('01:50')
            ->name('edge-data-usage-yesterday');
        // Builds whose worker died sit at "building" and hold the org's slot.
        $schedule->command(ReapStuckEdgeBuildsCommand::class)
            ->everyMinute()
            ->withoutOverlapping()
            ->name('edge-reap-stuck-builds');
        // dply Valkey awake seconds; each run adds what changed since the last.
        $schedule->command(CollectEdgeValkeyUsageCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->name('edge-valkey-usage');
        $schedule->command(CollectEdgeKvUsageCommand::class, ['--today'])
            ->hourly()
            ->withoutOverlapping()
            ->name('edge-kv-usage-today');
        $schedule->command(CollectEdgeKvUsageCommand::class)
            ->dailyAt('02:00')
            ->name('edge-kv-usage-yesterday');
        // Workers CPU, Durable Objects, customer R2 buckets (full-day totals, re-runs overwrite).
        $schedule->command(CollectEdgePlatformUsageCommand::class, ['--today'])
            ->hourly()
            ->withoutOverlapping()
            ->name('edge-platform-usage-today');
        $schedule->command(CollectEdgePlatformUsageCommand::class)
            ->dailyAt('02:10')
            ->name('edge-platform-usage-yesterday');
        // Realtime connection time + messages; each run adds what changed since the last.
        // dply Messages: published messages since the last run, per organization.
        $schedule->command(CollectEdgeMessagesUsageCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->name('edge-messages-usage');
        $schedule->command(CollectEdgeRealtimeUsageCommand::class)
            ->hourly()
            ->withoutOverlapping()
            ->name('edge-realtime-usage');
        // Builds run on the builder pool: alert when none has checked in for
        // 5 minutes. A host that builds itself has nothing to watch.
        if (! DplyRuntime::runsBuilds()) {
            $schedule->command(CheckEdgeBuildersCommand::class)
                ->everyMinute()
                ->withoutOverlapping()
                ->name('edge-check-builders');
        }
        // Synthetic round trip through the customer realtime relay; alerts platform admins.
        if (EdgeRealtimeMonitor::enabled()) {
            // node: the builder has it, a container does not.
            $realtime = self::onBuildHost($schedule, CheckEdgeRealtimeCommand::class)
                ->name('edge-check-realtime')
                ->everyMinute()
                ->withoutOverlapping();
            if (DplyRuntime::runsBuilds()) {
                $realtime->runInBackground();
            }
        }

        // Keep Node build images warm on workers so Edge deploys skip cold pulls.
        if ((bool) config('edge.build.warm_images_on_schedule', true)) {
            self::onBuildHost($schedule, WarmEdgeBuildImagesCommand::class)
                ->name('edge-warm-build-images')
                ->everySixHours()
                ->withoutOverlapping()
                ->onOneServer();
        }

        $schedule->command(RollupEdgeAnalyticsEngineCommand::class)->hourlyAt(5);

        $schedule->command(EvaluateEdgeGuardrailsCommand::class)
            ->dailyAt('02:45')
            ->withoutOverlapping();

        $schedule->command(CheckEdgeRumAlertsCommand::class)->hourly()->withoutOverlapping();

        $schedule->job(new VerifyEdgeCustomDomainsJob)->everyFifteenMinutes();
        $schedule->job(new CheckEdgeDnsZonesJob)->everyFiveMinutes();

        // Capture failed operations into the dedicated error stream, then cap
        // its growth nightly. The sweeper polls the source tables (failures are
        // written via the query builder, which bypasses model events).
        $schedule->command(SyncErrorEventsCommand::class)->everyMinute()->withoutOverlapping();
        $schedule->command(PruneErrorEventsCommand::class)->dailyAt('03:25');
        $schedule->command(PruneNotificationInboxItemsCommand::class)->dailyAt('03:35');
        $schedule->command(PruneAuditLogsCommand::class)->dailyAt('03:20');
        $schedule->command(PruneOrphanedSiteDataCommand::class)->weeklyOn(1, '04:40');
        $schedule->command(PruneSiteUptimeCheckResultsCommand::class)->dailyAt('03:55');

        // Secret-vault (app-native, W1 off-box break-glass): daily age-encrypted
        // escrow of the platform .env (→ APP_KEY), an independent DB dump, and the
        // fast-recovery critical-keys bundle.
        // age + pg_dump live on the builder, not in the container image.
        self::onBuildHost($schedule, SecretsEscrowCommand::class, ['--source' => 'platform-env'])
            ->name('secrets-escrow-env')
            ->dailyAt('04:20')
            ->withoutOverlapping();
        self::onBuildHost($schedule, SecretsEscrowCommand::class, ['--source' => 'db-dump'])
            ->name('secrets-escrow-db-dump')
            ->dailyAt('04:30')
            ->withoutOverlapping();
        self::onBuildHost($schedule, SecretsEscrowCommand::class, ['--source' => 'critical-keys'])
            ->name('secrets-escrow-critical-keys')
            ->dailyAt('04:35')
            ->withoutOverlapping()
            ->when(fn (): bool => filled(config('secret_vault.critical_keys.pg_password')) || filled(config('secret_vault.critical_keys.ssh_recovery_key_path')));

        // Restore drill runs ONLY on the isolated drill host (it alone holds the
        // age identity + a scratch DB), gated by SECRET_VAULT_DRILL_ENABLED.
        $schedule->command(SecretsRestoreDrillCommand::class)
            ->dailyAt('05:00')
            ->withoutOverlapping()
            ->name('secrets-restore-drill')
            ->when(fn (): bool => (bool) config('secret_vault.drill.enabled'));

        if (DplyRuntime::isSplitDeployment()) {
            foreach ($schedule->events() as $event) {
                $event->onOneServer();
            }
        }
    }

    /**
     * A scheduled command that shells out (docker, node, age, pg_dump). Runs
     * here when this host builds; a container queues it for the builder.
     *
     * @param  class-string  $command
     * @param  array<string, mixed>  $parameters
     */
    private static function onBuildHost(Schedule $schedule, string $command, array $parameters = []): Event
    {
        if (DplyRuntime::runsBuilds()) {
            return $schedule->command($command, $parameters);
        }

        return $schedule->job(new QueuedCommand([app($command)->getName(), $parameters]), DplyRuntime::BUILDER_QUEUE);
    }
}
