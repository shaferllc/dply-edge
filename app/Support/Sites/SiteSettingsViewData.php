<?php

declare(strict_types=1);

namespace App\Support\Sites;

use App\Models\ConsoleAction;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Modules\Billing\Services\EdgeSiteAccessAnalytics;
use App\Modules\Billing\Services\EdgeSiteBillingAnalytics;
use App\Modules\Billing\Services\EdgeSiteTrafficAnalytics;
use App\Modules\Billing\Services\ManagedProductCostEstimator;
use App\Support\Deployment\DeploymentContract;
use App\Support\SiteSettingsHeader;
use App\Support\SiteSettingsSidebar;
use Illuminate\Support\Collection;

/**
 * View-model for {@see resources/views/livewire/sites/settings.blade.php}. Keeps
 * catalog/setup out of the site settings blade tree.
 */
final class SiteSettingsViewData
{
    /**
     * @param  array<string, mixed>  $deploymentPreflight
     * @return array<string, mixed>
     */
    /**
     * dply-edge renders exactly one workspace shell — the edge one. The BYO
     * VM/container branch left with the VM platform.
     *
     * @param  array<string, mixed>  $deploymentPreflight
     * @return array<string, mixed>
     */
    public static function for(
        Server $server,
        Site $site,
        string $section,
        ?DeploymentContract $deploymentContract = null,
        array $deploymentPreflight = [],
        ?User $user = null,
    ): array {
        return self::forEdgeWorkspace($server, $site, $section, $user);
    }

    /**
     * Edge workspaces share the settings shell but skip BYO VM/container view-model work.
     *
     * @return array<string, mixed>
     */
    private static function forEdgeWorkspace(
        Server $server,
        Site $site,
        string $section,
        ?User $user = null,
    ): array {
        $runtimeTarget = $site->runtimeTarget();
        $runtimePublication = is_array($runtimeTarget['publication'] ?? null) ? $runtimeTarget['publication'] : [];
        $resourceNoun = __('App');
        $resourceNounLower = strtolower($resourceNoun);
        $resourcePlural = __('apps');
        $workspaceTitle = __('Edge site workspace');
        $settingsSidebarItems = SiteSettingsSidebar::items($site, $server);
        $sectionHeader = SiteSettingsHeader::for($site, $server, $section);
        $header = self::headerContext($site, $sectionHeader, $section, $user);
        $settingsBreadcrumbs = self::breadcrumbs($server, $site, $section, $sectionHeader);
        $edgeAnalytics = self::edgeAnalyticsForSection($site, $section);
        $edgeContext = EdgeSiteViewData::context($site, $section);
        $sectionConsoleActionKinds = (array) (config('console_actions.section_kinds.'.$section, []));
        $sectionConsoleActionRun = self::consoleActionRun($site, $sectionConsoleActionKinds);
        $contextualDocSlug = null;

        return array_merge(
            compact(
                'runtimePublication',
                'resourceNoun',
                'resourceNounLower',
                'resourcePlural',
                'workspaceTitle',
                'settingsSidebarItems',
                'sectionHeader',
                'settingsBreadcrumbs',
                'sectionConsoleActionKinds',
                'sectionConsoleActionRun',
                'contextualDocSlug',
            ),
            $header,
            [
                'isEdgeWorkspace' => true,
                'generalRecentDeployments' => new Collection,
            ],
            $edgeAnalytics,
            $edgeContext,
        );
    }

    /**
     * @param  array{title: string, description: string, icon: string}  $sectionHeader
     * @return array<string, mixed>
     */
    private static function headerContext(
        Site $site,
        array $sectionHeader,
        string $section,
        ?User $user,
    ): array {
        $headerUser = $user;
        $headerOrg = $headerUser?->currentOrganization();
        $headerCanUpdateSite = (bool) $headerUser?->can('update', $site);
        $headerCanDeleteSite = (bool) $headerUser?->can('delete', $site);
        $headerIsDeployer = (bool) $headerOrg?->userIsDeployer($headerUser);
        $headerIsAdmin = (bool) $headerOrg?->hasAdminAccess($headerUser);
        $headerRoleLabel = match (true) {
            $headerIsAdmin => null,
            $headerIsDeployer => __('Deployer'),
            $headerCanUpdateSite => __('Editor'),
            default => __('Read-only'),
        };
        $headerRoleTone = match (true) {
            $headerIsDeployer => 'bg-amber-100 text-amber-900 ring-amber-200/60',
            $headerCanUpdateSite => 'bg-emerald-100 text-emerald-900 ring-emerald-200/60',
            default => 'bg-slate-100 text-slate-700 ring-slate-200/60',
        };
        $sectionDescription = $headerCanUpdateSite
            ? $sectionHeader['description']
            : ($headerIsDeployer
                ? __('Review this section — settings are read-only for the Deployer role. Use Deploy actions to ship changes.')
                : __('You have read-only access to this section — settings cannot be changed from this account.'));

        return compact(
            'headerUser',
            'headerOrg',
            'headerCanUpdateSite',
            'headerCanDeleteSite',
            'headerIsDeployer',
            'headerIsAdmin',
            'headerRoleLabel',
            'headerRoleTone',
            'sectionDescription',
        );
    }

    /**
     * Billing + traffic cards for the overview observability child (lazy wire:init).
     *
     * @return array{
     *     edgeUsageBillingEnabled: bool,
     *     edgeManagedFee: float|null,
     *     edgeUsageRates: array<string, mixed>,
     *     edgeSiteBilling: array<string, mixed>|null,
     *     edgeSiteTraffic: array<string, mixed>|null,
     * }
     */
    public static function edgeOverviewObservability(Site $site): array
    {
        $edgeUsageBillingEnabled = (bool) config('dply.edge.usage_billing.enabled', false);
        $edgeManagedFee = ((int) config('subscription.standard.edge_cents', 0)) / 100;
        $edgeUsageRates = app(ManagedProductCostEstimator::class)->edgeUsageRates();
        $edgeSiteBilling = app(EdgeSiteBillingAnalytics::class)->forSite($site);
        $edgeSiteTraffic = app(EdgeSiteTrafficAnalytics::class)->forSite($site, billing: $edgeSiteBilling);

        return [
            'edgeUsageBillingEnabled' => $edgeUsageBillingEnabled,
            'edgeManagedFee' => $edgeManagedFee,
            'edgeUsageRates' => $edgeUsageRates,
            'edgeSiteBilling' => $edgeSiteBilling,
            'edgeSiteTraffic' => $edgeSiteTraffic,
        ];
    }

    /**
     * Analytics payloads for nested Edge Livewire children (Traffic / Billing).
     * Request-memoized so a double-render cannot re-run the same snapshot queries.
     *
     * @return array{
     *     edgeUsageBillingEnabled: bool,
     *     edgeManagedFee: float|null,
     *     edgeUsageRates: array<string, mixed>,
     *     edgeSiteBilling: array<string, mixed>|null,
     *     edgeSiteTraffic: array<string, mixed>|null,
     *     edgeSiteAccess: array<string, mixed>|null,
     * }
     */
    public static function edgeSectionAnalytics(Site $site, string $section): array
    {
        return self::resolveEdgeAnalytics($site, $section);
    }

    /**
     * Edge billing/traffic/access snapshots are section-scoped — avoid running
     * usage queries on every workspace tab (Deploys, Build, Domains, etc.).
     *
     * Traffic / Billing are owned by nested Livewire children — the parent
     * EdgeSettings shell must not pre-load them or every page hits the same
     * snapshot queries twice in one request.
     *
     * @return array{
     *     edgeUsageBillingEnabled: bool,
     *     edgeManagedFee: float|null,
     *     edgeUsageRates: array<string, mixed>,
     *     edgeSiteBilling: array<string, mixed>|null,
     *     edgeSiteTraffic: array<string, mixed>|null,
     *     edgeSiteAccess: array<string, mixed>|null,
     * }
     */
    private static function edgeAnalyticsForSection(Site $site, string $section): array
    {
        if (! $site->usesEdgeRuntime()) {
            return self::emptyEdgeAnalytics();
        }

        // Nested children load these via {@see edgeSectionAnalytics()}.
        if (in_array($section, ['traffic', 'billing'], true)) {
            return self::edgeAnalyticsFlagsOnly();
        }

        return self::resolveEdgeAnalytics($site, $section);
    }

    /**
     * @return array{
     *     edgeUsageBillingEnabled: bool,
     *     edgeManagedFee: float|null,
     *     edgeUsageRates: array<string, mixed>,
     *     edgeSiteBilling: array<string, mixed>|null,
     *     edgeSiteTraffic: array<string, mixed>|null,
     *     edgeSiteAccess: array<string, mixed>|null,
     * }
     */
    private static function resolveEdgeAnalytics(Site $site, string $section): array
    {
        $memoKey = 'site_settings.edge_analytics.'.$site->id.'.'.$section;
        if (app()->bound('request')) {
            $cached = request()->attributes->get($memoKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $flags = self::edgeAnalyticsFlagsOnly();

        $needsBillingSnapshot = $section === 'billing';
        $needsTrafficSnapshot = $section === 'traffic';
        $needsAccessSnapshot = $section === 'traffic';

        if (! $needsBillingSnapshot && ! $needsTrafficSnapshot && ! $needsAccessSnapshot) {
            return $flags;
        }

        $edgeUsageRates = ($needsBillingSnapshot || $needsTrafficSnapshot)
            ? app(ManagedProductCostEstimator::class)->edgeUsageRates()
            : [];

        $edgeSiteBilling = ($needsBillingSnapshot || $needsTrafficSnapshot)
            ? app(EdgeSiteBillingAnalytics::class)->forSite($site)
            : null;

        $edgeSiteTraffic = $needsTrafficSnapshot
            ? app(EdgeSiteTrafficAnalytics::class)->forSite($site, billing: $edgeSiteBilling)
            : null;

        $edgeSiteAccess = $needsAccessSnapshot
            ? app(EdgeSiteAccessAnalytics::class)->forSite($site)
            : null;

        $payload = [
            'edgeUsageBillingEnabled' => $flags['edgeUsageBillingEnabled'],
            'edgeManagedFee' => $flags['edgeManagedFee'],
            'edgeUsageRates' => $edgeUsageRates,
            'edgeSiteBilling' => $edgeSiteBilling,
            'edgeSiteTraffic' => $edgeSiteTraffic,
            'edgeSiteAccess' => $edgeSiteAccess,
        ];

        if (app()->bound('request')) {
            request()->attributes->set($memoKey, $payload);
        }

        return $payload;
    }

    /**
     * @return array{
     *     edgeUsageBillingEnabled: bool,
     *     edgeManagedFee: float|null,
     *     edgeUsageRates: array<string, mixed>,
     *     edgeSiteBilling: null,
     *     edgeSiteTraffic: null,
     *     edgeSiteAccess: null,
     * }
     */
    private static function edgeAnalyticsFlagsOnly(): array
    {
        return [
            'edgeUsageBillingEnabled' => (bool) config('dply.edge.usage_billing.enabled', false),
            'edgeManagedFee' => ((int) config('subscription.standard.edge_cents', 0)) / 100,
            'edgeUsageRates' => [],
            'edgeSiteBilling' => null,
            'edgeSiteTraffic' => null,
            'edgeSiteAccess' => null,
        ];
    }

    /**
     * @return array{
     *     edgeUsageBillingEnabled: false,
     *     edgeManagedFee: null,
     *     edgeUsageRates: array{},
     *     edgeSiteBilling: null,
     *     edgeSiteTraffic: null,
     *     edgeSiteAccess: null,
     * }
     */
    private static function emptyEdgeAnalytics(): array
    {
        return [
            'edgeUsageBillingEnabled' => false,
            'edgeManagedFee' => null,
            'edgeUsageRates' => [],
            'edgeSiteBilling' => null,
            'edgeSiteTraffic' => null,
            'edgeSiteAccess' => null,
        ];
    }

    /**
     * @param  array{title: string, description: string, icon: string}  $sectionHeader
     * @return list<array{label: string, href?: string|null, icon: string}>
     */
    private static function breadcrumbs(Server $server, Site $site, string $section, array $sectionHeader): array
    {
        $items = [
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            SiteWorkspaceBreadcrumbs::projectsItem(),
            SiteWorkspaceBreadcrumbs::projectItem(
                $site,
                $section === 'general' ? null : route('sites.show', ['server' => $server, 'site' => $site, 'section' => 'general']),
            ),
        ];

        if ($section !== 'general') {
            $items[] = [
                'label' => $sectionHeader['title'],
                'icon' => SiteWorkspaceBreadcrumbs::iconKeyFromSection($section, $site, $server),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $kinds
     */
    private static function consoleActionRun(Site $site, array $kinds): ?ConsoleAction
    {
        if ($kinds === []) {
            return null;
        }

        return ConsoleAction::query()
            ->where('subject_type', $site->getMorphClass())
            ->where('subject_id', $site->id)
            ->whereIn('kind', $kinds)
            ->whereNull('dismissed_at')
            ->orderByDesc('created_at')
            ->first();
    }
}
