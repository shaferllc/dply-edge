<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\EdgeDeployment;
use App\Models\Server;
use App\Models\Site;

/**
 * View-model for the Edge site provisioning shell (build in progress).
 */
final class EdgeProvisioningViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Server $server, Site $site): array
    {
        $site->loadMissing([
            'edgeDeployments' => fn ($query) => $query->limit(1),
        ]);

        $journey = self::journey($site);

        return array_merge($journey, [
            'server' => $server,
            'site' => $site,
            'siteHeaderBreadcrumbs' => [
                ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
                SiteWorkspaceBreadcrumbs::projectsItem(),
                SiteWorkspaceBreadcrumbs::projectItem($site),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function journey(Site $site): array
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

        $edgeProvisioningState = self::resolveState($site, $edgeLatestDeployment);
        $edgeProvisioningError = self::resolveError($site, $edgeLatestDeployment);

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
        $edgeCompletedSteps = $edgeJourneyHasFailed
            ? max(0, $edgeCurrentStepIndex)
            : ($edgeJourneyIsDone ? $edgeTotalSteps : max(0, $edgeCurrentStepIndex));
        $edgeProgressPercent = $edgeTotalSteps > 0
            ? (int) round(($edgeCompletedSteps / $edgeTotalSteps) * 100)
            : 0;
        $edgeCurrentLabel = $edgeStatusSteps[$edgeProvisioningState]
            ?? str_replace('_', ' ', $edgeProvisioningState);

        return compact(
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

    private static function resolveState(Site $site, ?EdgeDeployment $deployment): string
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

    private static function resolveError(Site $site, ?EdgeDeployment $deployment): ?string
    {
        $metaError = $site->edgeMeta()['last_error'] ?? null;
        $deploymentError = $deployment?->failure_reason;

        // BuildJourney already shows failure_reason as "Reason". Only surface
        // site-meta last_error when it differs (infra error that never hit
        // the deployment row) — otherwise we print the same string twice.
        if (is_string($metaError) && $metaError !== '' && $metaError !== $deploymentError) {
            return $metaError;
        }

        return null;
    }
}
