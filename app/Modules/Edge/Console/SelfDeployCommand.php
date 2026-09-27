<?php

declare(strict_types=1);

namespace App\Modules\Edge\Console;

use App\Models\EdgeDeployment;
use App\Models\Site;
use App\Modules\Edge\Console\Concerns\RunsWithEnvFile;
use App\Modules\Edge\Services\EdgeProductionEnv;
use App\Modules\Edge\Services\SelfHosting\SelfDeployer;
use App\Modules\Edge\Services\SelfHosting\SelfDeployState;
use Dotenv\Dotenv;
use Illuminate\Console\Command;
use Throwable;

/**
 * Break-glass deploy of dply itself to Cloudflare, from any machine with
 * docker, git and this checkout — no running control plane needed. Day to
 * day, dply deploys itself from its own dashboard (dply:self:register).
 * See docs/self-hosting-runbook.md.
 */
class SelfDeployCommand extends Command
{
    protected $signature = 'dply:self:deploy
        {--env-file= : Production .env to deploy with (e.g. restored from escrow). Default: this process\'s env}
        {--ref= : Git ref or commit to deploy (default: origin/<edge.self.branch>)}
        {--repo= : Git repository to check out from (default: this checkout)}
        {--rollback : Redeploy the previous successful commit}
        {--skip-migrate : Deploy code without running migrations}
        {--no-auto-rollback : Leave a failed deploy in place}
        {--dry-run : Print every step and command; run nothing, write nothing}';

    protected $description = 'Deploy dply itself (break-glass path; works while the control plane is down)';

    use RunsWithEnvFile;

    /** What a deploy cannot run without (the rest may come from the site's env). */
    public const REQUIRED_ENV = ['APP_KEY', 'DPLY_EDGE_R2_BUCKET', 'DPLY_EDGE_CF_ACCOUNT_ID', 'DPLY_EDGE_CF_API_TOKEN', 'DPLY_EDGE_CF_DISPATCH_NAMESPACE'];

    public function handle(SelfDeployer $deployer, SelfDeployState $state): int
    {
        $envFile = trim((string) $this->option('env-file'));
        if ($envFile !== '' && ! $this->isEnvFileChild()) {
            return $this->reexecWithEnvFile($envFile, self::REQUIRED_ENV);
        }

        $dryRun = (bool) $this->option('dry-run');
        $deployer->dryRun = $dryRun;
        $deployer->logTo(fn (string $line) => $this->output->write($line));
        $fileEnv = $envFile !== '' ? Dotenv::parse((string) file_get_contents($envFile)) : [];

        try {
            $saved = $state->load();
        } catch (Throwable $e) {
            if (! $dryRun) {
                $this->error('Could not read the deploy state from R2: '.$e->getMessage());

                return self::FAILURE;
            }
            $this->warn('R2 state not readable ('.$e->getMessage().'); continuing the dry run without it.');
            $saved = ['site_id' => '', 'deploys' => [], 'worker' => []];
        }

        $siteId = trim((string) config('edge.self.site_id')) ?: $saved['site_id'];
        if ($siteId === '') {
            $this->error('No self site id: run php artisan dply:self:register first (it saves the id to R2), or set DPLY_SELF_SITE_ID.');

            return self::FAILURE;
        }

        $db = $deployer->dbReachable();
        $this->line('Database: '.($db ? 'reachable' : 'UNREACHABLE (using the R2 snapshot)'));
        try {
            $site = $deployer->site($siteId, $db, $saved['worker']);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $history = SelfDeployState::history($saved['deploys'], $siteId, $db);
        $repo = trim((string) $this->option('repo')) ?: base_path();

        if ($this->option('rollback')) {
            $target = SelfDeployState::rollbackTarget($history);
            if ($target === null) {
                $this->error('No earlier successful deploy to roll back to.');

                return self::FAILURE;
            }
            $sha = $target['sha'];
            $ref = 'rollback';
            $migrate = false;
            $this->info("Rolling back to {$sha} (deployed {$target['at']}).");
        } else {
            $ref = trim((string) $this->option('ref')) ?: 'origin/'.config('edge.self.branch', 'main');
            try {
                $sha = $deployer->resolveSha($repo, $ref);
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
            $migrate = ! $this->option('skip-migrate');
            if ($migrate && ! $db && ! $dryRun) {
                $this->error('The production database is unreachable, so migrations cannot run. Fix the database first, or pass --skip-migrate to deploy code only.');

                return self::FAILURE;
            }
        }

        $appEnv = $fileEnv;
        if ($db && ! $dryRun) {
            // The site's own production env; an env file (break-glass) wins.
            $appEnv = array_merge(app(EdgeProductionEnv::class)->forSite($site), $fileEnv);
        }

        if ($dryRun && ! $this->option('rollback')) {
            $previous = SelfDeployState::rollbackTarget($history);
            $this->line('[dry-run] → If rollout or the health check fails: '.($this->option('no-auto-rollback') || $previous === null
                ? 'stop (no automatic rollback'.($previous === null ? ': no earlier successful deploy' : '').')'
                : "redeploy {$previous['sha']} without migrations"));
        }
        $snapshot = null;
        $failure = $this->release($deployer, $site, $db, $repo, $sha, $appEnv, $migrate, $saved['worker'], $snapshot);
        if ($failure === null) {
            $this->record($state, $saved, $site, $db, $sha, $ref, 'live', $snapshot);
            $this->info('dply is live at '.$sha.'.');

            return self::SUCCESS;
        }

        $this->error('Deploy failed: '.$failure);
        $this->record($state, $saved, $site, $db, $sha, $ref, 'failed', null, $failure);

        if (! $deployer->rolledOut) {
            $this->line('Nothing reached Cloudflare, so production is unchanged. No rollback needed.');

            return self::FAILURE;
        }
        $previous = SelfDeployState::rollbackTarget($history, $sha);
        if ($this->option('rollback') || $this->option('no-auto-rollback') || $previous === null) {
            if ($previous === null && ! $this->option('rollback')) {
                $this->warn('No earlier successful deploy to roll back to.');
            }

            return self::FAILURE;
        }

        // Once, never recursively: a failed rollback leaves it to a human.
        $this->warn("Rolling back to {$previous['sha']} (no migrations).");
        $rollbackFailure = $this->release($deployer, $site, $db, $repo, $previous['sha'], $appEnv, false, $saved['worker'], $snapshot);
        if ($rollbackFailure !== null) {
            $this->error('Rollback failed too: '.$rollbackFailure.' — see docs/self-hosting-runbook.md.');
            $this->record($state, $saved, $site, $db, $previous['sha'], 'rollback', 'failed', null, $rollbackFailure);

            return self::FAILURE;
        }
        $this->record($state, $saved, $site, $db, $previous['sha'], 'rollback', 'live', $snapshot);
        $this->warn("Rolled back: {$previous['sha']} is live again. The failed commit was {$sha}.");

        return self::FAILURE;
    }

    /**
     * Build, roll out, health-check. Null on success, else why it failed.
     *
     * @param  array<string, string>  $appEnv
     * @param  array<string, mixed>  $worker
     *
     * @param-out array<string, mixed>|null  $snapshot
     */
    private function release(SelfDeployer $deployer, Site $site, bool $db, string $repo, string $sha, array $appEnv, bool $migrate, array $worker, ?array &$snapshot): ?string
    {
        $snapshot = null;
        try {
            $snapshot = $deployer->release($site, $db, $repo, $sha, $appEnv, $migrate, $worker);
            $rollout = $deployer->awaitRollout($site);
            if ($rollout['settled'] && ! $rollout['ok']) {
                return 'rollout: '.(string) $rollout['reason'];
            }

            return $deployer->healthFailure($site);
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    /**
     * R2 always (rollback must work with the database down); the database
     * too when it answers, as an EdgeDeployment row on the self site so the
     * dashboard's history shows break-glass deploys.
     *
     * @param  array<string, mixed>  $saved
     * @param  array<string, mixed>|null  $snapshot
     */
    private function record(SelfDeployState $state, array &$saved, Site $site, bool $db, string $sha, string $ref, string $status, ?array $snapshot, ?string $failure = null): void
    {
        if ($this->option('dry-run')) {
            $this->line("[dry-run] → Record {$status} deploy of ".($sha ?: '<sha>').' in R2'.($db ? ' and the database' : ''));

            return;
        }

        $saved['site_id'] = (string) $site->id;
        $saved = SelfDeployState::withDeploy($saved, ['sha' => $sha, 'ref' => $ref, 'status' => $status, 'via' => 'self:deploy', 'host' => (string) gethostname()]);
        if ($snapshot !== null && $snapshot !== []) {
            $saved['worker'] = $snapshot;
        }
        try {
            $state->save($saved);
        } catch (Throwable $e) {
            $this->warn('Could not save the deploy state to R2: '.$e->getMessage());
        }

        if (! $db || ! $site->exists) {
            return;
        }
        try {
            if ($status === 'live') {
                EdgeDeployment::query()->where('site_id', $site->id)->where('status', EdgeDeployment::STATUS_LIVE)
                    ->update(['status' => EdgeDeployment::STATUS_SUPERSEDED]);
            }
            // No meta.container.fingerprint: a dashboard deploy would read it
            // as "this exact build is live" and skip building.
            EdgeDeployment::query()->create([
                'site_id' => $site->id,
                'organization_id' => $site->organization_id,
                'status' => $status === 'live' ? EdgeDeployment::STATUS_LIVE : EdgeDeployment::STATUS_FAILED,
                'git_commit' => $sha,
                'git_branch' => $ref,
                'published_at' => $status === 'live' ? now() : null,
                'failed_at' => $status === 'live' ? null : now(),
                'failure_reason' => $failure,
                'meta' => ['self_deploy' => true, 'host' => (string) gethostname()],
            ]);
        } catch (Throwable $e) {
            $this->warn('Could not record the deploy in the database: '.$e->getMessage());
        }
    }
}
