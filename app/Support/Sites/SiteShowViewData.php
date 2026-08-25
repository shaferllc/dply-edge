<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;
use App\Support\Deployment\DeploymentContract;
use Illuminate\Support\Collection;

/**
 * View-model for {@see resources/views/livewire/sites/show.blade.php}. Keeps
 * catalog/setup out of the site show blade tree.
 */
final class SiteShowViewData
{
    /**
     * @param  array<string, mixed> $deploymentPreflight
     * @return array<string, mixed>
     */
    /**
     * Public so the ad-hoc previews tab can render its own inline progress
     * card off a pending preview Site without going through the full
     * site-show view-data pipeline.
     *
     * @return array<string, mixed>
     */
    public static function edgeProvisioningJourney(Site $site): array
    {
        $edgeMeta = $site->edgeMeta();
        $sourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : [];
        $buildSpec = is_array($edgeMeta['build'] ?? null) ? $edgeMeta['build'] : [];
        $edgeBuildCommand = (string) ($buildSpec['command'] ?? 'npm ci && npm run build');
        $edgeOutputDir = (string) ($buildSpec['output_dir'] ?? 'dist');
        $edgeRepoLabel = (($sourceSpec['repo'] ?? '?').'@'.($sourceSpec['branch'] ?? 'main'));
        $edgeLiveUrl = $site->edgeLiveUrl();

        $edgeLatestDeployment = $site->relationLoaded('edgeDeployments')
            ? $site->edgeDeployments->first()
            : $site->edgeDeployments()->first();

        $edgeProvisioningState = self::resolveEdgeProvisioningState($site, $edgeLatestDeployment);
        $edgeProvisioningError = self::resolveEdgeProvisioningError($site, $edgeLatestDeployment);

        $edgeStatusSteps = [
            'queued' => __('Queued / cloning repository'),
            'building' => __('Installing dependencies & building'),
            'publishing' => __('Publishing to Edge CDN'),
            'live' => __('Live'),
            'failed' => __('Needs attention'),
        ];
        $edgeStepKeys = array_keys($edgeStatusSteps);
        $edgeCurrentStepIndex = array_search($edgeProvisioningState, $edgeStepKeys, true);
        $edgeCurrentStepIndex = $edgeCurrentStepIndex === false ? 0 : $edgeCurrentStepIndex;

        $edgeJourneyHasFailed = $edgeProvisioningState === 'failed';
        $edgeJourneyIsDone = $edgeProvisioningState === 'live';
        $edgeVisibleSteps = collect($edgeStatusSteps)->except('failed');
        $edgeTotalSteps = $edgeVisibleSteps->count();
        // Count the IN-FLIGHT step as the current step number — operators
        // read "(3/4)" as "we're on step 3", not "3 done and not started 4".
        // Done = totalSteps; failed = the step that died (currentStepIndex
        // stays at the live-time index since state flips to 'failed' only
        // after the failing step is set).
        $edgeCompletedSteps = $edgeJourneyHasFailed
            ? max(1, min($edgeTotalSteps, $edgeCurrentStepIndex))
            : ($edgeJourneyIsDone ? $edgeTotalSteps : max(1, min($edgeTotalSteps, $edgeCurrentStepIndex + 1)));
        $edgeProgressPercent = $edgeTotalSteps > 0
            ? (int) round(($edgeCompletedSteps / $edgeTotalSteps) * 100)
            : 0;
        $edgeCurrentLabel = $edgeStatusSteps[$edgeProvisioningState]
            ?? str_replace('_', ' ', $edgeProvisioningState);

        return compact(
            'edgeMeta',
            'sourceSpec',
            'buildSpec',
            'edgeBuildCommand',
            'edgeOutputDir',
            'edgeRepoLabel',
            'edgeLiveUrl',
            'edgeLatestDeployment',
            'edgeProvisioningState',
            'edgeProvisioningError',
            'edgeStatusSteps',
            'edgeStepKeys',
            'edgeCurrentStepIndex',
            'edgeJourneyHasFailed',
            'edgeJourneyIsDone',
            'edgeVisibleSteps',
            'edgeTotalSteps',
            'edgeCompletedSteps',
            'edgeProgressPercent',
            'edgeCurrentLabel',
        );
    }

    /**
     * Journey shape scoped to a single deployment (build → publish → live),
     * without leaning on the parent Site's status. Used by the deployment
     * detail page so the progress card reflects THIS build even when the
     * site as a whole is already live on a different deployment.
     *
     * @return array<string, mixed>
     */
    public static function edgeDeploymentJourney(EdgeDeployment $deployment): array
    {
        $state = match ($deployment->status) {
            EdgeDeployment::STATUS_BUILDING => 'building',
            EdgeDeployment::STATUS_PUBLISHING => 'publishing',
            EdgeDeployment::STATUS_LIVE, EdgeDeployment::STATUS_SUPERSEDED => 'live',
            EdgeDeployment::STATUS_FAILED => 'failed',
            default => 'queued',
        };

        $statusSteps = [
            'queued' => __('Queued / cloning repository'),
            'building' => __('Installing dependencies & building'),
            'publishing' => __('Publishing to Edge CDN'),
            'live' => __('Live'),
            'failed' => __('Needs attention'),
        ];
        $stepKeys = array_keys($statusSteps);
        $currentStepIndex = array_search($state, $stepKeys, true);
        $currentStepIndex = $currentStepIndex === false ? 0 : $currentStepIndex;

        $hasFailed = $state === 'failed';
        $isDone = $state === 'live';
        $visibleSteps = collect($statusSteps)->except('failed');
        $totalSteps = $visibleSteps->count();
        // "(X/N)" reads as "currently on step X" — count the in-flight step
        // as the current number, not the count of finished ones.
        $completedSteps = $hasFailed
            ? max(1, min($totalSteps, $currentStepIndex))
            : ($isDone ? $totalSteps : max(1, min($totalSteps, $currentStepIndex + 1)));
        $progressPercent = $totalSteps > 0
            ? (int) round(($completedSteps / $totalSteps) * 100)
            : 0;
        $currentLabel = $statusSteps[$state];

        return [
            'state' => $state,
            'statusSteps' => $statusSteps,
            'stepKeys' => $stepKeys,
            'visibleSteps' => $visibleSteps,
            'currentStepIndex' => $currentStepIndex,
            'totalSteps' => $totalSteps,
            'completedSteps' => $completedSteps,
            'progressPercent' => $progressPercent,
            'currentLabel' => $currentLabel,
            'hasFailed' => $hasFailed,
            'isDone' => $isDone,
            'error' => is_string($deployment->failure_reason) && $deployment->failure_reason !== ''
                ? $deployment->failure_reason
                : null,
        ];
    }

    private static function resolveEdgeProvisioningState(Site $site, ?EdgeDeployment $deployment): string
    {
        if ($site->status === Site::STATUS_EDGE_FAILED
            || ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_FAILED)) {
            return 'failed';
        }

        if ($site->status === Site::STATUS_EDGE_ACTIVE
            || ($deployment !== null && $deployment->status === EdgeDeployment::STATUS_LIVE)) {
            return 'live';
        }

        if ($deployment === null) {
            return 'queued';
        }

        return match ($deployment->status) {
            EdgeDeployment::STATUS_PUBLISHING => 'publishing',
            EdgeDeployment::STATUS_BUILDING => 'building',
            default => 'queued',
        };
    }

    private static function resolveEdgeProvisioningError(Site $site, ?EdgeDeployment $deployment): ?string
    {
        $metaError = $site->edgeMeta()['last_error'] ?? null;
        $deploymentError = $deployment?->failure_reason;

        // This "Last error" box is rendered *alongside* the BuildJourney
        // component, which already shows the deployment failure_reason in its
        // own "Reason" box. So only surface the site-meta last_error here when
        // it *differs* — i.e. an infra-level failure (R2/KV perms) that never
        // reached the deployment row. When it matches, or when the only error
        // is the deployment one, stay null and let BuildJourney be the single
        // source of truth instead of double-printing the same string.
        if (is_string($metaError) && $metaError !== '' && $metaError !== $deploymentError) {
            return $metaError;
        }

        return null;
    }

    /**
     * Ordered BYO provision-journey keys + labels (including failed).
     * Wildcard TLS sits between the testing hostname and writing vhost — the
     * same place {@see \App\Services\Sites\SiteProvisioner} pauses — so the
     * progress bar does not fall through to 0 when state is
     * `waiting_for_wildcard_tls`.
     *
     * @return array<string, string>
     */
    public static function byoStatusSteps(Site $site, string $provisioningState, bool $entersFirstDeployState): array
    {
        $statusSteps = [
            'queued' => __('Queued'),
            'preparing_runtime_artifacts' => __('Preparing runtime artifacts'),
            'configuring_publication' => __('Preparing publication target'),
            'provisioning_testing_hostname' => __('Assigning testing hostname'),
        ];

        if (self::showsWildcardTlsStep($site, $provisioningState)) {
            $statusSteps['waiting_for_wildcard_tls'] = __('Issuing wildcard TLS');
        }

        $statusSteps['writing_site_config'] = __('Writing site config');
        $statusSteps['waiting_for_http'] = __('Checking reachability');

        if ($entersFirstDeployState) {
            $statusSteps['awaiting_first_deploy'] = __('Waiting for first deploy');
        }

        $statusSteps['ready'] = __('Site available');
        $statusSteps['failed'] = __('Needs attention');

        return $statusSteps;
    }

    /**
     * Show the wildcard step whenever this site will (or already did) wait
     * on a shared per-server cert — not only while the state key is live.
     * That keeps the step count stable from queued through ready.
     */
    private static function showsWildcardTlsStep(Site $site, string $provisioningState): bool
    {
        if ($provisioningState === 'waiting_for_wildcard_tls') {
            return true;
        }

        if (! (bool) config('sites.wildcard_testing_ssl', true)) {
            return false;
        }

        // dply-edge serves every site off the edge — no machine webserver to
        // wait on.
        return false;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $runtimeLogs
     * @return Collection<int, array{title: string, meta: string, transcript: string, action: string, status: string}>
     */
    public static function runtimeOperationConsoles(Collection $runtimeLogs): Collection
    {
        /** @var Collection<int, array{title: string, meta: string, transcript: string, action: string, status: string}> $rows */
        $rows = new Collection;

        foreach ($runtimeLogs as $runtimeLog) {
            $timestamp = (string) ($runtimeLog['ran_at'] ?? '');
            $status = strtoupper((string) ($runtimeLog['status'] ?? 'unknown'));
            $action = ucfirst((string) ($runtimeLog['action'] ?? 'runtime'));
            $headerParts = array_values(array_filter([$timestamp, $status]));
            $transcript = ($headerParts !== [] ? '['.implode('] [', $headerParts).'] ' : '').$action;
            $output = trim((string) ($runtimeLog['output'] ?? ''));

            if ($output !== '') {
                $transcript .= "\n\n".$output;
            }

            $rows->push([
                'title' => (string) __('Runtime activity'),
                'meta' => $action,
                'transcript' => $transcript,
                'action' => strtolower((string) ($runtimeLog['action'] ?? '')),
                'status' => strtolower((string) ($runtimeLog['status'] ?? '')),
            ]);
        }

        /** @var Collection<int, array{title: string, meta: string, transcript: string, action: string, status: string}> $consoles */
        $consoles = $rows->values();

        return $consoles;
    }

    /**
     * @return Collection<int, array{title: string, meta: string, transcript: string}>
     */
    public static function deploymentConsolesFor(Collection $deployments): Collection
    {
        /** @var Collection<int, array{title: string, meta: string, transcript: string}> $consoles */
        $consoles = self::deploymentConsoles($deployments);

        return $consoles;
    }

    /**
     * @return Collection<int, array{title: string, meta: string, transcript: string}>
     */
    private static function deploymentConsoles(Collection $deployments): Collection
    {
        /** @var Collection<int, array{title: string, meta: string, transcript: string}> $rows */
        $rows = new Collection;

        foreach ($deployments as $deployment) {
            $status = strtoupper((string) $deployment->status);
            $trigger = strtoupper((string) $deployment->trigger);
            $createdAt = $deployment->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s T');
            $prefix = array_values(array_filter([$createdAt, $status, $trigger]));
            $transcript = trim(implode("\n", array_filter([
                '['.implode('] [', $prefix).'] Deployment record',
                $deployment->git_sha ? 'SHA: '.$deployment->git_sha : null,
                trim((string) $deployment->log_output) !== '' ? trim((string) $deployment->log_output) : null,
            ])));

            $rows->push([
                'title' => (string) __('Deployment log'),
                'meta' => (string) $deployment->created_at->diffForHumans(),
                'transcript' => $transcript,
            ]);
        }

        /** @var Collection<int, array{title: string, meta: string, transcript: string}> $consoles */
        $consoles = $rows->values();

        return $consoles;
    }
}
