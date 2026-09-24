<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\Organization;
use App\Models\RecentResource;
use App\Models\Site;
use Illuminate\Support\Facades\Gate;

/**
 * Concern extracted from the host Livewire component to keep it under control.
 * Every public property/method name is unchanged, so Livewire snapshots and
 * wire:* bindings keep resolving against the composed class.
 */
trait BuildsCommandPaletteGroups
{
    /** Per-list result cap so a broad context can't balloon the payload. */
    private const LIST_LIMIT = 50;

    /** Smaller cap for the root direct-hit search across every resource. */
    private const SEARCH_LIMIT = 6;

    /** Tighter cap for per-result quick actions in root search (e.g. deploy). */

    /** How many recents the empty-query root surfaces. */
    private const RECENT_LIMIT = 5;

    /** Category contexts whose label is static (not derived from a record). */
    /**
     * @return array<string, string>
     */
    private function categoryLabels(): array
    {
        return [
            'sites' => 'Sites',
            'organizations' => 'Organizations',
            'switch-org' => 'Switch organization',
            'settings' => 'Settings',
            'admin' => 'Admin',
        ];
    }

    /**
     * Build the result groups for the current context + query.
     *
     * @param  array{type: string, id: ?string, label: string}|null  $context
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function groups(?array $context): array
    {
        $org = auth()->user()?->currentOrganization();
        $query = trim($this->query);

        $raw = match ($context['type'] ?? 'root') {
            'sites' => $this->sitesList($org, $query),
            'site' => $this->siteSubPages($org, $context['id'] ?? null, $query),
            'organizations' => $this->organizationList($query),
            'switch-org' => $this->switchOrgList($query),
            'settings' => $this->commandContext('settings', $org, $query),
            'admin' => $this->commandContext('admin', $org, $query),
            default => $this->rootGroups($org, $query),
        };

        // Drop empty groups so the palette never shows a bare header.
        return array_values(array_filter($raw, fn (array $group): bool => $group['items'] !== []));
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function rootGroups(?Organization $org, string $query): array
    {
        $needle = mb_strtolower($query);
        $isAdmin = Gate::check('viewPlatformAdmin');
        $groups = [];

        // Direct resource hits lead when searching — the most specific meaning
        // of the query.
        if ($org !== null && $query !== '') {
            foreach ($this->searchGroups($org, $query) as $group) {
                $groups[] = $group;
            }
        }

        // Empty query → lead with where the operator has recently been. Skipped
        // entirely while searching (the direct hits above are more relevant).
        if ($org !== null && $query === '') {
            $recent = $this->recentGroup($org);
            if ($recent['items'] !== []) {
                $groups[] = $recent;
            }
        }

        // Nestable resource categories.
        $resourceNav = [
            ['Sites', 'sites', 'globe-alt', 'sites apps websites edge'],
            ['Organizations', 'organizations', 'building-office-2', 'organizations teams orgs'],
        ];
        $resourceItems = [];
        foreach ($resourceNav as $entry) {
            [$label, $type, $icon, $keywords] = $entry;
            if ($needle !== '' && ! str_contains(mb_strtolower($label.' '.$keywords), $needle)) {
                continue;
            }
            $resourceItems[] = [
                'label' => __($label),
                'into' => ['type' => $type],
                'icon' => $icon,
            ];
        }
        $groups[] = ['label' => __('Resources'), 'items' => $resourceItems];

        $specs = $this->commandSpecs();
        $groups[] = ['label' => __('Create'), 'items' => $this->resolveCommandItems($specs['create'], $org, $needle, $isAdmin)];
        $groups[] = ['label' => __('Go to'), 'items' => $this->resolveCommandItems($specs['go'], $org, $needle, $isAdmin)];

        // Nestable management areas.
        $manage = [];
        if ($needle === '' || str_contains('settings preferences account profile', $needle)) {
            $manage[] = ['label' => __('Settings'), 'into' => ['type' => 'settings'], 'icon' => 'cog-6-tooth'];
        }
        // Switching is only meaningful with more than one org to switch between.
        if (auth()->user()?->organizations()->count() > 1 && ($needle === '' || str_contains('switch organization team', $needle))) {
            $manage[] = ['label' => __('Switch organization'), 'into' => ['type' => 'switch-org'], 'icon' => 'building-office-2'];
        }
        if ($isAdmin && ($needle === '' || str_contains('admin platform', $needle))) {
            $manage[] = ['label' => __('Admin'), 'into' => ['type' => 'admin'], 'icon' => 'wrench-screwdriver'];
        }
        $groups[] = ['label' => __('Manage'), 'items' => $manage];

        return $groups;
    }

    /**
     * The "Recently visited" group for the empty-query root. Reads the user's
     * recents (most-recent first) and re-resolves each against the live,
     * org-scoped record so renamed resources show fresh and ones that were
     * deleted or now sit outside the current org silently drop out.
     *
     * @return array{label: string, items: list<array<string, mixed>>}
     */
    private function recentGroup(Organization $org): array
    {
        $recents = RecentResource::query()
            ->where('user_id', auth()->id())
            ->orderByDesc('visited_at')
            ->get();

        $items = [];
        foreach ($recents as $recent) {
            if (count($items) >= self::RECENT_LIMIT) {
                break;
            }
            if ($recent->resource_type === 'site') {
                $site = $this->scopedSite($org, $recent->resource_id);
                if ($site !== null) {
                    $items[] = [
                        'label' => $site->name,
                        'sublabel' => $site->server?->name,
                        'into' => ['type' => 'site', 'id' => $site->id],
                        'icon' => 'globe-alt',
                    ];
                }
            }
        }

        return ['label' => __('Recently visited'), 'items' => $items];
    }

    /**
     * Org-scoped direct-hit search across every resource (root level only).
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function searchGroups(Organization $org, string $query): array
    {
        $like = $this->like($query);
        $serverIds = $org->serverIds();
        $groups = [];

        $siteModels = Site::query()
            ->whereIn('server_id', $serverIds)
            ->where('name', 'like', $like)
            ->with('server')
            ->orderByDesc('id')
            ->limit(self::SEARCH_LIMIT)
            ->get();
        $sites = $siteModels
            ->map(fn (Site $site): array => [
                'label' => $site->name,
                'sublabel' => $site->server?->name,
                'into' => ['type' => 'site', 'id' => $site->id],
                'icon' => 'globe-alt',
            ])
            ->all();
        if ($sites !== []) {
            $groups[] = ['label' => __('Sites'), 'items' => $sites];
        }

        return $groups;
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function sitesList(?Organization $org, string $query): array
    {
        if ($org === null) {
            return [];
        }
        $items = $this->maybeIndexLink($query, 'all projects index', __('All projects'), __('Open the projects index'), 'dashboard', 'globe-alt');

        $serverIds = $org->serverIds();
        foreach (
            Site::query()
                ->whereIn('server_id', $serverIds)
                ->where('name', 'like', $this->like($query))
                ->with('server')
                ->orderByDesc('id')
                ->limit(self::LIST_LIMIT)
                ->get() as $site
        ) {
            $items[] = [
                'label' => $site->name,
                'sublabel' => $site->server?->name,
                'into' => ['type' => 'site', 'id' => $site->id],
                'icon' => 'globe-alt',
            ];
        }

        return [['label' => __('Sites'), 'items' => $items]];
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function organizationList(string $query): array
    {
        $items = $this->maybeIndexLink($query, 'all organizations index', __('All organizations'), __('Open the organizations index'), 'organizations.index', 'building-office-2');

        foreach (
            auth()->user()->organizations()
                ->where('name', 'like', $this->like($query))
                ->orderBy('name')
                ->limit(self::LIST_LIMIT)
                ->get() as $organization
        ) {
            $items[] = [
                'label' => $organization->name,
                'sublabel' => null,
                'url' => route('organizations.show', $organization),
                'icon' => 'building-office-2',
            ];
        }

        return [['label' => __('Organizations'), 'items' => $items]];
    }

    /**
     * The other organizations the user belongs to, as switch *actions* (the
     * current org is omitted — you can't switch to where you are).
     *
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function switchOrgList(string $query): array
    {
        $current = auth()->user()?->currentOrganization();

        $items = [];
        foreach (
            auth()->user()->organizations()
                ->where('name', 'like', $this->like($query))
                ->orderBy('name')
                ->limit(self::LIST_LIMIT)
                ->get() as $organization
        ) {
            if ($current !== null && $organization->id === $current->id) {
                continue;
            }
            $items[] = [
                'label' => $organization->name,
                'sublabel' => null,
                'action' => ['key' => 'org.switch', 'id' => $organization->id],
                'icon' => 'building-office-2',
            ];
        }

        return [['label' => __('Switch organization'), 'items' => $items]];
    }

    /**
     * @return list<array{label: string, items: list<array<string, mixed>>}>
     */
    private function siteSubPages(?Organization $org, ?string $id, string $query): array
    {
        $site = $org !== null ? $this->scopedSite($org, $id) : null;
        if ($site === null) {
            return [];
        }
        $server = $site->server;

        // [label, route, icon, keywords] — the edge workspace is one route with
        // a `section` segment, so the palette offers the workspace entry point
        // and the standalone pages that have routes of their own.
        $pages = [
            ['Overview', 'sites.show', 'globe-alt', 'overview summary workspace'],
            ['Preview comments', 'sites.preview-comments', 'chat-bubble-left-right', 'preview comments review'],
        ];

        $items = $this->resolveResourcePages($pages, [$server, $site], $query);

        return $items === [] ? [] : [['label' => $site->name, 'items' => $items]];
    }
}
