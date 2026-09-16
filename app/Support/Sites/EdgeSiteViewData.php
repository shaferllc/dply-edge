<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\Site;
use App\Modules\Edge\Services\EdgeDeliveryContextResolver;
use App\Modules\Edge\Support\EdgeLocalDevDiagnostics;
use App\Modules\Edge\Support\EdgePlatformCredentials;
use App\Modules\Edge\Support\FakeEdgeProvision;

/**
 * Shared Edge site dashboard variables for settings + show views.
 */
final class EdgeSiteViewData
{
    /**
     * @return array<string, mixed>
     */
    public static function context(Site $site, string $section = 'general'): array
    {
        $context = self::baseContext($site);

        if (self::sectionNeedsDeployments($section)) {
            $context = array_merge($context, self::deploymentsContext($site));
        }

        if (self::sectionNeedsDeliveryWorker($section)) {
            $context = array_merge($context, self::deliveryWorkerContext($site));
        }

        if (self::sectionNeedsDeliveryBanner($section)) {
            $context['edgeDeliveryBanner'] = self::deliveryBanner(
                $context['edgeFakeMode'],
                $context['edgeUsesManagedBackend'],
                $context['edgeUsesByoCloudflare'],
                $context['edgePlatformReady'],
                (string) $site->status,
                (string) ($site->edgeMeta()['last_error'] ?? ''),
            );
        } else {
            $context['edgeDeliveryBanner'] = null;
        }

        return $context;
    }

    /**
     * @return array<string, mixed>
     */
    private static function baseContext(Site $site): array
    {
        $edgeMeta = $site->edgeMeta();
        $edgeSourceSpec = is_array($edgeMeta['source'] ?? null) ? $edgeMeta['source'] : null;
        $edgeBuildSpec = is_array($edgeMeta['build'] ?? null) ? $edgeMeta['build'] : [];
        $edgeLiveUrl = $site->edgeLiveUrl();
        $edgeRepo = (string) ($edgeSourceSpec['repo'] ?? '');
        $edgeBranch = (string) ($edgeSourceSpec['branch'] ?? 'main');
        $edgeSourceRef = $edgeRepo !== '' ? $edgeRepo.'@'.$edgeBranch : '';
        $edgeBuildCommand = (string) ($edgeBuildSpec['command'] ?? 'npm ci && npm run build');
        $edgeOutputDir = (string) ($edgeBuildSpec['output_dir'] ?? 'dist');
        $edgeDeployOnPush = (bool) ($edgeSourceSpec['deploy_on_push'] ?? true);
        $edgeWebhookMeta = is_array($edgeMeta['webhook'] ?? null) ? $edgeMeta['webhook'] : null;
        $edgeGithubWebhookConnected = is_array($edgeWebhookMeta) && ($edgeWebhookMeta['hook_id'] ?? null) !== null;
        $edgeWebhookLastEventAt = is_array($edgeWebhookMeta) ? ($edgeWebhookMeta['last_event_at'] ?? null) : null;
        $edgeSpaFallback = (bool) (($edgeMeta['routing']['spa_fallback'] ?? null) ?? ($edgeMeta['spa_fallback'] ?? true));
        $edgeRuntimeMode = (string) ($edgeMeta['runtime_mode'] ?? 'static');
        $edgeOrigin = is_array($edgeMeta['origin'] ?? null) ? $edgeMeta['origin'] : null;
        $edgeAttachedDomains = is_array($edgeMeta['routing']['custom_domains'] ?? null) ? $edgeMeta['routing']['custom_domains'] : [];
        $edgeIsPreviewChild = ! empty($edgeMeta['preview_parent_site_id']);
        $edgeActiveDeploymentId = $edgeMeta['active_deployment_id'] ?? null;
        $edgeStatusBadgeClass = match ($site->status) {
            Site::STATUS_EDGE_ACTIVE => 'bg-emerald-100 text-emerald-800 ring-emerald-200/60 dark:bg-raw-emerald-950/40 dark:text-emerald-300 dark:ring-raw-emerald-800/40',
            Site::STATUS_EDGE_PROVISIONING => 'bg-sky-100 text-sky-800 ring-sky-200/60 dark:bg-sky-950/40 dark:text-sky-300 dark:ring-raw-sky-800/40',
            Site::STATUS_EDGE_FAILED => 'bg-rose-100 text-rose-800 ring-rose-200/60 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-raw-rose-800/40',
            default => 'bg-brand-sand/80 text-brand-moss ring-brand-ink/10',
        };
        $edgeStatusLabel = match ($site->status) {
            Site::STATUS_EDGE_ACTIVE => __('Live'),
            Site::STATUS_EDGE_PROVISIONING => __('Building'),
            Site::STATUS_EDGE_FAILED => __('Failed'),
            default => str_replace('_', ' ', (string) $site->status),
        };
        $edgeGithubRepoUrl = $edgeRepo !== '' ? 'https://github.com/'.$edgeRepo : null;
        $edgeFakeMode = FakeEdgeProvision::enabled();
        $edgeUsesManagedBackend = $site->edge_backend === 'dply_edge';
        $edgeUsesByoCloudflare = $site->usesOrgCloudflareEdge();
        $edgePlatformReady = EdgePlatformCredentials::isProductionReady();
        $edgeDeliveryBackendLabel = self::deliveryBackendLabel(
            $edgeUsesManagedBackend,
            $edgeUsesByoCloudflare,
            $edgeFakeMode,
        );
        $edgeDeliveryHostname = $site->edgeHostname();

        return compact(
            'edgeMeta',
            'edgeSourceSpec',
            'edgeBuildSpec',
            'edgeLiveUrl',
            'edgeRepo',
            'edgeBranch',
            'edgeSourceRef',
            'edgeBuildCommand',
            'edgeOutputDir',
            'edgeDeployOnPush',
            'edgeWebhookMeta',
            'edgeGithubWebhookConnected',
            'edgeWebhookLastEventAt',
            'edgeSpaFallback',
            'edgeRuntimeMode',
            'edgeOrigin',
            'edgeAttachedDomains',
            'edgeIsPreviewChild',
            'edgeActiveDeploymentId',
            'edgeStatusBadgeClass',
            'edgeStatusLabel',
            'edgeGithubRepoUrl',
            'edgeFakeMode',
            'edgeUsesManagedBackend',
            'edgeUsesByoCloudflare',
            'edgePlatformReady',
            'edgeDeliveryBackendLabel',
            'edgeDeliveryHostname',
        ) + [
            'edgeWorkerZoneName' => '',
            'edgeWorkerRoutes' => [],
            'edgeWorkerScriptName' => '',
            'edgeDeployments' => collect(),
            'edgeLatestDeployment' => null,
            'edgeDeliveryBanner' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function deploymentsContext(Site $site): array
    {
        $edgeDeployments = $site->relationLoaded('edgeDeployments')
            ? $site->edgeDeployments
            : $site->edgeDeployments()->limit(20)->get();

        return [
            'edgeDeployments' => $edgeDeployments,
            'edgeLatestDeployment' => $edgeDeployments->first(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function deliveryWorkerContext(Site $site): array
    {
        $edgeWorkerZoneName = '';
        $edgeWorkerRoutes = [];
        $edgeWorkerScriptName = '';

        try {
            $deliveryContext = app(EdgeDeliveryContextResolver::class)->forSite($site);
            $edgeWorkerZoneName = $deliveryContext->workerZoneName;
            $edgeWorkerRoutes = $deliveryContext->workerRoutes;
            $edgeWorkerScriptName = $deliveryContext->workerScriptName;
        } catch (\Throwable) {
            // Org credential may be missing on incomplete BYO sites.
        }

        return compact(
            'edgeWorkerZoneName',
            'edgeWorkerRoutes',
            'edgeWorkerScriptName',
        );
    }

    private static function sectionNeedsDeployments(string $section): bool
    {
        return in_array($section, ['general', 'edge-deploys', 'edge-logs', 'edge-build'], true);
    }

    private static function sectionNeedsDeliveryWorker(string $section): bool
    {
        // Delivery no longer surfaces worker/zone internals — Overview only.
        return $section === 'general';
    }

    private static function sectionNeedsDeliveryBanner(string $section): bool
    {
        return in_array($section, ['general', 'edge-delivery'], true);
    }

    private static function deliveryBackendLabel(
        bool $usesManagedBackend,
        bool $usesByoCloudflare,
        bool $fakeMode,
    ): string {
        if ($usesByoCloudflare) {
            return __('Your connected account');
        }

        if ($usesManagedBackend) {
            return $fakeMode
                ? __('Managed Edge (local)')
                : __('Managed Edge');
        }

        return __('Unknown delivery backend');
    }

    /**
     * @return array{tone: string, title: string, message: string}|null
     */
    private static function deliveryBanner(
        bool $fakeMode,
        bool $usesManagedBackend,
        bool $usesByoCloudflare,
        bool $platformReady,
        string $siteStatus = '',
        string $lastError = '',
    ): ?array {
        if ($fakeMode) {
            $hint = EdgeLocalDevDiagnostics::fakeModeBannerHint();

            return [
                'tone' => 'amber',
                'title' => __('Fake edge — local mode'),
                'message' => __('Local dev mode serves hostnames through this app. Deploys write to disk/cache only — not platform edge storage. Set DPLY_FAKE_EDGE=false and redeploy for real edge delivery.')
                    .' '
                    .$hint['message'],
            ];
        }

        if ($usesManagedBackend && ! $platformReady) {
            return [
                'tone' => 'amber',
                'title' => __('Platform edge not configured'),
                'message' => __('Platform Edge credentials are incomplete. Run php artisan dply:edge:bootstrap, deploy the worker, then set DPLY_FAKE_EDGE=false and redeploy this site.'),
            ];
        }

        if (! ($usesManagedBackend || $usesByoCloudflare)) {
            return null;
        }

        // Site-status-aware copy — the previous unconditional "Live on dply
        // Edge" was misleading when the latest deploy actually failed or
        // the site never went live, because the banner only checked
        // backend config, not real serving state.
        if ($siteStatus === Site::STATUS_EDGE_FAILED) {
            return [
                'tone' => 'rose',
                'title' => __('Last deploy failed'),
                'message' => $lastError !== ''
                    ? __('Edge is wired up, but the most recent build did not publish. Reason: :err', ['err' => $lastError])
                    : __('Edge is wired up, but the most recent build did not publish. Retry from the Deploys tab.'),
            ];
        }

        if ($siteStatus === Site::STATUS_EDGE_PROVISIONING) {
            return [
                'tone' => 'sky',
                'title' => __('Edge build in progress'),
                'message' => __('Builds publish to edge storage. The site goes live once this deploy reaches the publish step.'),
            ];
        }

        // Active sites no longer get a "Live on dply Edge" banner — the
        // hero + status badges already communicate this and the extra
        // green band was just visual noise on a healthy workspace.
        if ($siteStatus === Site::STATUS_EDGE_ACTIVE) {
            return null;
        }

        // Backend is configured but the site has no recognized active state
        // yet — surface a neutral tone instead of falsely claiming "Live".
        return [
            'tone' => 'amber',
            'title' => __('Edge delivery ready'),
            'message' => __('Edge backend is configured. Trigger a deploy to publish content to the edge network.'),
        ];
    }
}
