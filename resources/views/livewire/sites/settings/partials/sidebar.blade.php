@php
    $card = 'dply-card overflow-hidden';
    $navLink = 'flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left text-sm font-medium transition-colors';

    $sidebarEdgeLiveUrl = $site->usesEdgeRuntime() ? $site->edgeLiveUrl() : null;
    $sidebarPrimaryHostname = optional($site->primaryDomain())->hostname
        ?? ($runtimePublication['hostname'] ?? null)
        ?? $site->name;
    $sidebarVisitUrl = $sidebarEdgeLiveUrl ?: $site->visitUrl();
    $sidebarUrlSeed = (string) ($sidebarPrimaryHostname ?: $site->name ?: $site->id);
    $sidebarCanRedeployEdge = is_string($sidebarEdgeLiveUrl)
        && $sidebarEdgeLiveUrl !== ''
        && method_exists($this, 'redeployEdge');
@endphp

{{-- The <aside> stays the grid column (col-span-3); only its CARD contents are
     @persist'd across wire:navigate so the sidebar isn't rebuilt between sections
     of the same site (skips ~40 nav items + the error-count query per nav).
     Keyed by site id → a different site re-renders. Active highlighting is kept
     client-side on `livewire:navigated` (script below) since the persisted DOM
     doesn't re-render. NOTE: @persist must wrap the card, NOT the <aside> — it
     compiles to a wrapping <div x-persist>, which would otherwise become the grid
     child and collapse the column to 1/12 width. --}}
<aside class="ws-sidebar relative sm:col-span-3 mb-8 lg:mb-0"
    x-data="{
        copiedUrl: false,
    }"
    :class="{ 'ws-collapsed': $store.wsnav && $store.wsnav.collapsed }"
>
    {{-- Collapse / expand handle: a tab riding the sidebar's right edge (the
         card itself is overflow-hidden, so the tab anchors to the <aside>). It
         replaced a full-width header row that spent ~40px on one icon. State
         lives in the global Alpine `wsnav` store (localStorage-persisted) and
         also sets data-wsnav on <html> so the grid reclaims the column —
         see the script below + app.css. Desktop-only, like collapse itself. --}}
    <button type="button" @click="$store.wsnav && $store.wsnav.toggle()"
        class="absolute -right-3.5 top-7 z-20 hidden h-7 w-7 items-center justify-center rounded-full border border-brand-ink/10 bg-white text-brand-mist shadow-md transition hover:text-brand-ink hover:shadow-lg lg:flex"
        :title="($store.wsnav && $store.wsnav.collapsed) ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'"
        :aria-label="($store.wsnav && $store.wsnav.collapsed) ? '{{ __('Expand sidebar') }}' : '{{ __('Collapse sidebar') }}'">
        <svg class="h-3.5 w-3.5 transition-transform duration-200" :class="($store.wsnav && $store.wsnav.collapsed) ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 01-.02 1.06L9.832 9.25H17.5a.75.75 0 010 1.5H9.832l2.938 2.96a.75.75 0 11-1.06 1.06l-4.25-4.25a.75.75 0 010-1.06l4.25-4.25a.75.75 0 011.06.02z" clip-rule="evenodd" />
        </svg>
    </button>

    @persist('site-sidebar-'.$site->id)
    <div class="{{ $card }}">
        <div class="ws-hide-collapsed border-b border-brand-ink/10 p-4 sm:p-5">
            {{-- The @feature guards keep these "back to the index" links from
                 pointing at a parked surface: the workspace stays reachable for
                 existing sites, but its index is gated. --}}
            @feature('surface.edge')
                <a href="{{ route('dashboard') }}" wire:navigate
                    class="-ms-1 mb-3 inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 text-xs font-medium text-brand-moss transition-colors hover:bg-brand-sand/50 hover:text-brand-ink">
                    <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Back to projects') }}
                </a>
            @endfeature
            <div class="flex items-start gap-3">
                <x-entity-avatar :seed="$site->name" :image="$site->logoUrl()" class="h-12 w-12 text-base" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-base font-semibold text-brand-ink">{{ $sidebarPrimaryHostname }}</p>
                    @php $sidebarTestingHostname = trim((string) $site->testingHostname()); @endphp
                    @if ($sidebarTestingHostname !== '' && $sidebarTestingHostname !== $sidebarPrimaryHostname)
                        <p class="mt-0.5 truncate font-mono text-xs text-brand-mist" title="{{ $sidebarTestingHostname }}">{{ $sidebarTestingHostname }}</p>
                    @endif
                    @php
                        // Resolve through the request-scoped registry rather than
                        // reading $server->workspace: the SitePolicy already fetched
                        // this exact row for the site, and the server's belongsTo is a
                        // separate relation on a separate instance, so touching it here
                        // re-SELECTed `workspaces` on every workspace page. Priming the
                        // relation keeps any later $server->workspace free too.
                        $sidebarWorkspace = app(\App\Support\Workspaces\WorkspaceRegistry::class)->find($server->workspace_id);
                        if ($sidebarWorkspace !== null && ! $server->relationLoaded('workspace')) {
                            $server->setRelation('workspace', $sidebarWorkspace);
                        }
                    @endphp
                </div>
            </div>

            <div class="mt-3 flex items-center gap-1.5">
                <span class="shrink-0 rounded-md bg-brand-sand/70 px-2 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-moss">URL</span>
                @if ($sidebarVisitUrl || $sidebarPrimaryHostname)
                    @php
                        $sidebarDisplayUrl = $sidebarVisitUrl
                            ? preg_replace('#^https?://#', '', $sidebarVisitUrl)
                            : $sidebarPrimaryHostname;
                        $sidebarCopyUrl = $sidebarVisitUrl ?: $sidebarDisplayUrl;
                    @endphp
                    @if ($sidebarVisitUrl)
                        <a
                            href="{{ $sidebarVisitUrl }}"
                            target="_blank"
                            rel="noreferrer"
                            class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink underline-offset-2 hover:text-brand-sage hover:underline"
                            title="{{ $sidebarVisitUrl }}"
                        >{{ $sidebarDisplayUrl }}</a>
                    @else
                        <span class="min-w-0 flex-1 truncate font-mono text-xs text-brand-ink" title="{{ $sidebarDisplayUrl }}">{{ $sidebarDisplayUrl }}</span>
                    @endif
                    <button
                        type="button"
                        class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                        title="{{ __('Copy URL') }}"
                        @click="navigator.clipboard.writeText(@js($sidebarCopyUrl)); copiedUrl = true; setTimeout(() => copiedUrl = false, 2000)"
                    >
                        <x-heroicon-o-clipboard class="h-4 w-4" />
                    </button>
                    @if ($sidebarVisitUrl)
                        <a
                            href="{{ $sidebarVisitUrl }}"
                            target="_blank"
                            rel="noreferrer"
                            title="{{ __('Open site') }}"
                            class="rounded-md p-1 text-brand-mist hover:bg-brand-sand/50 hover:text-brand-ink"
                        >
                            <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4" />
                        </a>
                    @endif
                    <span x-show="copiedUrl" x-cloak class="text-2xs font-medium text-brand-forest">{{ __('Copied') }}</span>
                @else
                    <span class="text-xs text-brand-mist">—</span>
                @endif
            </div>

            @if ($sidebarCanRedeployEdge)
                @can('update', $site)
                    <button
                        type="button"
                        wire:click="redeployEdge"
                        wire:loading.attr="disabled"
                        wire:target="redeployEdge"
                        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-lg bg-brand-ink px-3 py-2 text-xs font-semibold text-white shadow-sm hover:bg-brand-ink/90 disabled:cursor-wait disabled:opacity-60"
                    >
                        <x-heroicon-o-arrow-path class="h-4 w-4" wire:loading.remove wire:target="redeployEdge" aria-hidden="true" />
                        <x-spinner wire:loading wire:target="redeployEdge" variant="white" size="sm" />
                        <span wire:loading.remove wire:target="redeployEdge">{{ __('Deploy') }}</span>
                        <span wire:loading wire:target="redeployEdge">{{ __('Queuing…') }}</span>
                    </button>
                @endcan
            @endif
        </div>
        @php
            $groupLabels = (array) config('site_settings.nav_groups', []);
            $orderedGroupKeys = [];
            foreach ($settingsSidebarItems as $i) {
                $gk = $i['group'] ?? '_ungrouped';
                if (! in_array($gk, $orderedGroupKeys, true)) {
                    $orderedGroupKeys[] = $gk;
                }
            }
            $itemsByGroup = collect($settingsSidebarItems)->groupBy(fn ($i) => $i['group'] ?? '_ungrouped');

            // First visit: open the FIRST group only, collapse the rest. An
            // edge site has 26 sections and rendering them all expanded is the
            // wall this grouping exists to remove — but only as a default. The
            // moment anyone toggles a group we store their whole map in
            // localStorage and never seed again, so someone who lives in
            // Protect keeps it open. A group holding the section the user is
            // ON is never seeded collapsed, or a deep link would land them in
            // a closed group.
            $activeGroup = collect($settingsSidebarItems)
                ->firstWhere(fn ($i) => ($i['id'] ?? null) === $section)['group'] ?? null;

            $defaultCollapsed = collect($orderedGroupKeys)
                ->filter(fn (string $g): bool => isset($groupLabels[$g]))
                ->slice(1)
                ->reject(fn (string $g): bool => $g === $activeGroup)
                ->mapWithKeys(fn (string $g): array => [$g => true])
                ->all();
        @endphp
        <nav
            id="site-settings-sidebar"
            class="flex flex-col gap-0.5 p-2"
            aria-label="{{ __($resourceNoun.' settings sections') }}"
            x-data="{
                _k: 'dply.siteNav.collapsed:{{ $site->id }}',
                collapsed: (() => {
                    try {
                        const stored = localStorage.getItem('dply.siteNav.collapsed:{{ $site->id }}');
                        if (stored) return JSON.parse(stored);
                    } catch (e) {}
                    return @js((object) $defaultCollapsed);
                })(),
                init() {
                    this.$nextTick(() => document.getElementById('ws-nav-groups')?.remove());
                },
                toggle(g) { this.collapsed[g] = ! this.collapsed[g]; localStorage.setItem(this._k, JSON.stringify(this.collapsed)); },
            }"
        >
            {{-- Hide collapsed groups before Alpine boots. x-show starts true
                 while collapsed is empty, and x-collapse then animates them
                 shut — that is the open flash on load. Caller: this nav only.
                 localStorage key dply.siteNav.collapsed:{site id}. User: "the
                 sidebar flashes open when the page loads". --}}
            <script>
                (function () {
                    if (document.getElementById('ws-nav-groups')) return;
                    const fallback = @js((object) $defaultCollapsed);
                    let map = fallback;
                    try {
                        const stored = localStorage.getItem('dply.siteNav.collapsed:{{ $site->id }}');
                        if (stored) map = JSON.parse(stored);
                    } catch (e) {}
                    const rules = [];
                    Object.keys(map || {}).forEach((group) => {
                        if (!map[group]) return;
                        const name = CSS.escape(String(group));
                        rules.push('[data-nav-group="' + name + '"]{display:none!important}');
                    });
                    if (!rules.length) return;
                    const style = document.createElement('style');
                    style.id = 'ws-nav-groups';
                    style.textContent = rules.join('');
                    document.head.appendChild(style);
                })();
            </script>
            @foreach ($orderedGroupKeys as $groupKey)
                @php
                    $itemsInGroup = $itemsByGroup[$groupKey] ?? collect();

                    // Roll alert signals up to the (collapsible) group header so a
                    // count/dot stays visible even when the section is collapsed.
                    $groupAlertCount = 0;
                    $groupNeedsSetup = false;
                    foreach ($itemsInGroup as $gi) {
                        if (($gi['id'] ?? null) === 'errors' && empty($gi['preview_only'])) {
                            $groupAlertCount += \App\Models\ErrorEvent::undismissedCountForSite((string) $site->id);
                        }
                        if (! empty($gi['needs_setup'])) {
                            $groupNeedsSetup = true;
                        }
                    }
                    $isCollapsibleGroup = $groupKey !== '_ungrouped' && isset($groupLabels[$groupKey]);
                @endphp
                @if ($itemsInGroup->isEmpty())
                    @continue
                @endif
                @if ($isCollapsibleGroup)
                    <button
                        type="button"
                        x-on:click="toggle('{{ $groupKey }}')"
                        :aria-expanded="(! collapsed['{{ $groupKey }}']).toString()"
                        class="ws-hide-collapsed {{ ! $loop->first ? 'mt-3 ' : '' }}group flex w-full items-center gap-1.5 rounded-md px-3 pb-1 pt-0.5 text-2xs font-semibold uppercase tracking-[0.16em] text-brand-mist hover:text-brand-moss"
                    >
                        <span x-bind:class="collapsed['{{ $groupKey }}'] ? '' : 'rotate-90'" class="inline-flex transition-transform">
                            <x-heroicon-o-chevron-right class="h-3 w-3" />
                        </span>
                        <span class="flex-1 text-left">{{ __($groupLabels[$groupKey]) }}</span>
                        @if ($groupAlertCount > 0)
                            <span
                                x-show="collapsed['{{ $groupKey }}']"
                                class="shrink-0 rounded-full bg-rose-100 px-1.5 py-0.5 text-3xs font-bold text-rose-700"
                            >{{ $groupAlertCount > 99 ? '99+' : $groupAlertCount }}</span>
                        @endif
                        @if ($groupNeedsSetup)
                            <span
                                x-show="collapsed['{{ $groupKey }}']"
                                class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                role="img"
                                aria-label="{{ __('Setup required') }}"
                            ></span>
                        @endif
                    </button>
                @endif
                <div
                    class="ws-group-items flex flex-col gap-0.5"
                    @if ($isCollapsibleGroup) data-nav-group="{{ $groupKey }}" x-show="! collapsed['{{ $groupKey }}']" x-collapse @endif
                >
                @foreach ($itemsInGroup as $item)
                    @php
                        $isChild = ! empty($item['parent'] ?? null);
                        if (! empty($item['route'] ?? null)) {
                            $routeArgs = match ($item['route_params'] ?? null) {
                                'server_only' => ['server' => $server],
                                // server-level route, but carry the site as a query
                                // param so the target can render in site context.
                                'server_with_site' => ['server' => $server, 'site' => $site->id],
                                'organization' => ['organization' => $site->organization_id ?? auth()->user()?->currentOrganization()?->id],
                                default => ['server' => $server, 'site' => $site],
                            };
                            $href = route($item['route'], $routeArgs + ($item['route_query'] ?? []));
                        } else {
                            $sectionQuery = array_merge(
                                ($item['id'] === 'routing' && isset($routingTab)) ? ['tab' => $routingTab] : [],
                                $item['id'] === 'laravel-stack' ? ['laravel_tab' => $laravel_tab ?? 'commands'] : [],
                            );
                            $href = route('sites.show', array_merge([
                                'server' => $server,
                                'site' => $site,
                                'section' => $item['id'],
                            ], $sectionQuery));
                        }
                    @endphp
                    <a
                        href="{{ $href }}"
                        wire:navigate
                        data-nav-link
                        @if ($item['id'] === 'deploys')
                            data-nav-match="{{ route('sites.edge.deployments.show', ['site' => $site, 'deployment' => '0']) }}"
                        @endif
                        {{-- In the collapsed icon rail the label is hidden, so show
                             it as a native tooltip on hover; no tooltip when expanded. --}}
                        :title="($store.wsnav && $store.wsnav.collapsed) ? @js($item['label']) : null"
                        @class([
                            $navLink,
                            // Children indent as a whole pill (margin, not padding) so the
                            // active highlight hugs the content instead of leaving a wide
                            // empty gutter on the left.
                            'ms-4 !w-auto' => $isChild,
                            'bg-brand-sand/60 text-brand-ink' => $section === $item['id'],
                            'text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' => $section !== $item['id'],
                        ])
                    >
                        @if ($isChild)
                            <x-dynamic-component :component="$item['icon']" class="h-4 w-4 shrink-0 opacity-70" />
                        @else
                            <x-dynamic-component :component="$item['icon']" class="h-5 w-5 shrink-0 opacity-90" />
                        @endif
                        <span class="ws-hide-collapsed flex-1 truncate">{{ $item['label'] }}</span>
                        {{-- Styled popover tooltip — only rendered visible in the
                             collapsed icon rail (see app.css); pairs with the native
                             title above. --}}
                        <span class="ws-tip" role="tooltip" aria-hidden="true">{{ $item['label'] }}</span>
                        {{-- Only show the open-error count when Errors is actually
                             live — not while it's a "Soon" (preview_only) item. --}}
                        @if ($item['id'] === 'errors' && empty($item['preview_only']))
                            @php $openErrorCount = \App\Models\ErrorEvent::undismissedCountForSite((string) $site->id); @endphp
                            @if ($openErrorCount > 0)
                                <span class="ws-hide-collapsed shrink-0 rounded-full bg-rose-100 px-1.5 py-0.5 text-2xs font-bold text-rose-700">{{ $openErrorCount > 99 ? '99+' : $openErrorCount }}</span>
                            @endif
                        @endif
                        @if (! empty($item['preview_only']))
                            <span class="ws-hide-collapsed shrink-0 rounded-full bg-brand-sand/80 px-1.5 py-0.5 text-3xs font-semibold uppercase tracking-wide text-brand-moss">
                                {{ __('Soon') }}
                            </span>
                        @endif
                        @if (! empty($item['needs_setup']))
                            <span
                                class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber-500"
                                role="img"
                                aria-label="{{ __('Setup required') }}"
                                title="{{ __('Supervisor is not installed yet. Open this section to set it up.') }}"
                            ></span>
                        @endif
                    </a>
                @endforeach
                </div>
            @endforeach
        </nav>
        <div class="ws-hide-collapsed border-t border-brand-ink/10 p-3">
            @if ($site->usesEdgeRuntime())
                <a
                    href="{{ route('dashboard') }}"
                    wire:navigate
                    class="flex items-center gap-2 text-xs font-medium text-brand-moss hover:text-brand-ink"
                >
                    <x-heroicon-o-arrow-left class="h-4 w-4 shrink-0" />
                    {{ __('Back to projects') }}
                </a>
            @endif
        </div>
    </div>
    @endpersist

    {{-- Maintain the active-section highlight client-side. The sidebar DOM is
         persisted across wire:navigate, so Blade's $section comparison only runs
         on the first paint; this re-applies the highlight on each navigation by
         matching location.pathname (exact, then longest-prefix for detail pages
         nested under a section). --}}
    @once
        <script>
            (function () {
                if (window.__dplySidebarActiveBound) return;
                window.__dplySidebarActiveBound = true;

                // Collapse state: set <html data-wsnav> synchronously (no flash) so
                // the workspace grid reclaims the column on first paint (see app.css),
                // and expose a global Alpine store the sidebar toggle/labels bind to.
                const KEY = 'dply.wsnav.collapsed';
                const applyHtml = () => {
                    document.documentElement.dataset.wsnav =
                        localStorage.getItem(KEY) === '1' ? 'collapsed' : 'expanded';
                };
                applyHtml();
                document.addEventListener('alpine:init', () => {
                    if (!window.Alpine || Alpine.store('wsnav')) return;
                    Alpine.store('wsnav', {
                        collapsed: localStorage.getItem(KEY) === '1',
                        toggle() {
                            this.collapsed = !this.collapsed;
                            localStorage.setItem(KEY, this.collapsed ? '1' : '0');
                            document.documentElement.dataset.wsnav = this.collapsed ? 'collapsed' : 'expanded';
                        },
                    });
                });
                document.addEventListener('livewire:navigated', applyHtml);

                const sync = () => {
                    const here = location.pathname;
                    const links = Array.from(document.querySelectorAll('a[data-nav-link]'));
                    let best = null;
                    let bestLen = -1;
                    const consider = (a, path) => {
                        if (here === path) { best = a; bestLen = Infinity; return true; }
                        const prefix = path.endsWith('/') ? path : path + '/';
                        if (here.startsWith(prefix) && path.length > bestLen) { best = a; bestLen = path.length; }
                        return false;
                    };
                    for (const a of links) {
                        let path;
                        try { path = new URL(a.href).pathname; } catch (e) { continue; }
                        if (consider(a, path)) break;
                        const extra = a.getAttribute('data-nav-match');
                        if (!extra) continue;
                        try {
                            // The match URL carries a placeholder id; the section prefix is what matters.
                            const matchPath = new URL(extra, location.origin).pathname.replace(/\/[^/]+$/, '');
                            if (consider(a, matchPath)) break;
                        } catch (e) { /* ignore a bad match URL */ }
                    }
                    for (const a of links) {
                        const on = a === best;
                        a.classList.toggle('bg-brand-sand/60', on);
                        a.classList.toggle('text-brand-ink', on);
                        a.classList.toggle('text-brand-moss', !on);
                    }
                };

                document.addEventListener('livewire:navigated', sync);
                // A plain Livewire update (poll, action, state change) morphs the
                // nav back to its server-rendered classes, wiping the highlight
                // applied here — re-apply after every commit round-trip.
                document.addEventListener('livewire:init', () => {
                    if (window.Livewire?.hook) {
                        window.Livewire.hook('commit', ({ succeed }) => {
                            succeed(() => queueMicrotask(sync));
                        });
                    }
                });
                sync();
            })();
        </script>
    @endonce
</aside>
