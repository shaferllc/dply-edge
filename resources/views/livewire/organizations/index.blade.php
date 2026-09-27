@php
    $orgTotal = $allOrganizations->count();
    $rollupMembers = $allOrganizations->sum('users_count');
    $rollupServers = $allOrganizations->sum('servers_count');
    $rollupSites = $allOrganizations->sum('sites_count');
    $currentOrgId = session('current_organization_id');
    $hasOrgSearch = trim($search ?? '') !== '';
    $filteredCount = $organizations->count();
    // Header Add only when the list already has items — empty state owns the CTA.
    $showShellAdd = $orgTotal > 0;
@endphp

<div>
    <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
        <x-dashboard-breadcrumb doc-route="docs.markdown" doc-slug="roles-and-permissions" :current="__('Organizations')" current-icon="building-office-2" />

        <x-profile-shell
            :title="__('Organizations')"
            :description="$orgTotal === 0
                ? __('You\'re not in any organization yet. Spin one up to start grouping servers, teams, and billing.')
                : __('Switch workspaces, review usage, and open the organization you need.')"
            icon="heroicon-o-building-office-2"
        >
            <x-slot:actions>
                @if ($showShellAdd)
                    <a
                        href="{{ route('organizations.create') }}"
                        wire:navigate
                        class="inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('New organization') }}
                    </a>
                @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-3 gap-2">
                    <div @class([
                        'rounded-xl border px-4 py-3',
                        'border-brand-sage/30 bg-brand-sage/8' => $orgTotal > 0,
                        'border-brand-ink/10 bg-white/80' => $orgTotal === 0,
                    ])>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Workspaces') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $orgTotal }}</span>
                            <span class="text-xs text-brand-moss">{{ __('total') }}</span>
                        </dd>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('You belong to') }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-4 py-3">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $rollupMembers }}</span>
                            <span class="text-xs text-brand-moss">{{ trans_choice('person|people', $rollupMembers) }}</span>
                        </dd>
                        <p class="mt-1 text-xs text-brand-mist">{{ __('Across all orgs') }}</p>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-4 py-3">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Footprint') }}</dt>
                        <dd class="mt-1 flex items-baseline gap-1.5">
                            <span class="font-mono text-xl font-semibold tabular-nums text-brand-ink">{{ $rollupServers + $rollupSites }}</span>
                            <span class="text-xs text-brand-moss">{{ __('resources') }}</span>
                        </dd>
                        <p class="mt-1 text-xs text-brand-mist">{{ $rollupServers }} {{ trans_choice('server|servers', $rollupServers) }} · {{ $rollupSites }} {{ trans_choice('site|sites', $rollupSites) }}</p>
                    </div>
                </dl>
            </x-slot:stats>

            @if (session('success'))
                <div class="border-b border-brand-ink/10 px-5 py-4 sm:px-6">
                    <x-alert tone="success">{{ session('success') }}</x-alert>
                </div>
            @endif

            @if ($orgTotal === 0)
                <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-building-office-2 class="h-6 w-6" aria-hidden="true" />
                    </span>
                    <p class="mt-4 text-sm font-semibold text-brand-ink">{{ __('No organizations yet') }}</p>
                    <p class="mt-1 max-w-md text-sm leading-relaxed text-brand-moss">
                        {{ __('Create a workspace to group servers, teams, and billing in one place.') }}
                    </p>
                    <a
                        href="{{ route('organizations.create') }}"
                        wire:navigate
                        class="mt-5 inline-flex items-center gap-2 rounded-xl bg-brand-ink px-4 py-2 text-sm font-semibold text-brand-cream shadow-md transition-colors hover:bg-brand-forest"
                    >
                        <x-heroicon-o-plus class="h-4 w-4 shrink-0" aria-hidden="true" />
                        {{ __('Create your first organization') }}
                    </a>
                </div>
            @else
                @if ($orgTotal > 1 || $hasOrgSearch)
                    <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-5 py-3 sm:flex-row sm:items-center sm:justify-end sm:px-6">
                        <div class="w-full sm:max-w-sm">
                            <label for="org_search" class="sr-only">{{ __('Search') }}</label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 start-0 flex items-center ps-3 text-brand-mist">
                                    <x-heroicon-o-magnifying-glass class="h-4 w-4" aria-hidden="true" />
                                </span>
                                <input
                                    id="org_search"
                                    type="search"
                                    wire:model.live.debounce.300ms="search"
                                    placeholder="{{ __('Search organizations by name…') }}"
                                    autocomplete="off"
                                    class="w-full rounded-lg border-brand-ink/15 bg-white py-2 ps-9 pe-3 text-sm text-brand-ink placeholder:text-brand-mist shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                                />
                            </div>
                        </div>
                    </div>
                @endif

                @if ($hasOrgSearch && $filteredCount === 0)
                    <div class="flex flex-col items-center justify-center px-5 py-16 text-center sm:px-6">
                        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                            <x-heroicon-o-magnifying-glass class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <p class="mt-3 text-sm font-medium text-brand-ink">{{ __('No organizations match this search.') }}</p>
                        <button type="button" wire:click="$set('search', '')" class="mt-2 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Clear search') }}</button>
                    </div>
                @else
                    <ul class="divide-y divide-brand-ink/10">
                        @foreach ($organizations as $org)
                            @php
                                $initials = collect(preg_split('/\s+/', trim($org->name)))->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
                                if ($initials === '') {
                                    $initials = mb_strtoupper(mb_substr((string) $org->name, 0, 2));
                                }
                                $isCurrent = $currentOrgId == $org->id;
                            @endphp
                            <li wire:key="org-{{ $org->id }}" @class([
                                'flex flex-col gap-4 px-5 py-4 transition-colors hover:bg-brand-sand/15 sm:px-6 lg:flex-row lg:items-center lg:justify-between lg:gap-6',
                                'bg-brand-sage/5' => $isCurrent,
                            ])>
                                <div class="flex min-w-0 flex-1 items-start gap-4">
                                    <span @class([
                                        'flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-sm font-bold tracking-tight ring-1',
                                        'bg-brand-sage/15 text-brand-forest ring-brand-sage/25' => $isCurrent,
                                        'bg-brand-sand/40 text-brand-ink ring-brand-ink/10' => ! $isCurrent,
                                    ]) aria-hidden="true">
                                        <span class="select-none">{{ $initials }}</span>
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                            <a href="{{ route('organizations.show', $org) }}" wire:navigate class="truncate text-sm font-semibold text-brand-ink hover:text-brand-sage">{{ $org->name }}</a>
                                            @if ($isCurrent)
                                                <span class="inline-flex items-center gap-1 rounded-md border border-brand-sage/30 bg-brand-sage/15 px-1.5 py-0.5 text-2xs font-semibold uppercase tracking-wide text-brand-forest">
                                                    <x-heroicon-m-check-circle class="h-3 w-3" aria-hidden="true" />
                                                    {{ __('Current') }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-brand-moss">
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-user-group class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span class="font-mono tabular-nums text-brand-ink">{{ $org->users_count }}</span>
                                                {{ trans_choice('member|members', $org->users_count) }}
                                            </span>
                                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-squares-2x2 class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span class="font-mono tabular-nums text-brand-ink">{{ $org->teams_count }}</span>
                                                {{ trans_choice('team|teams', $org->teams_count) }}
                                            </span>
                                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-server-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span class="font-mono tabular-nums text-brand-ink">{{ $org->servers_count }}</span>
                                                {{ trans_choice('server|servers', $org->servers_count) }}
                                            </span>
                                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-globe-alt class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span class="font-mono tabular-nums text-brand-ink">{{ $org->sites_count }}</span>
                                                {{ trans_choice('site|sites', $org->sites_count) }}
                                            </span>
                                            <span aria-hidden="true" class="text-brand-mist/60">·</span>
                                            <span class="inline-flex items-center gap-1">
                                                <x-heroicon-m-rectangle-stack class="h-3.5 w-3.5 shrink-0 text-brand-mist" aria-hidden="true" />
                                                <span class="font-mono tabular-nums text-brand-ink">{{ $org->workspaces_count }}</span>
                                                {{ trans_choice('project|projects', $org->workspaces_count) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                                    @if (! $isCurrent)
                                        <button
                                            type="button"
                                            wire:click="switchOrganization('{{ $org->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="switchOrganization('{{ $org->id }}')"
                                            class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm transition hover:bg-brand-sand/40 disabled:opacity-50"
                                        >
                                            <span wire:loading.remove wire:target="switchOrganization('{{ $org->id }}')" class="inline-flex items-center gap-1.5">
                                                <x-heroicon-o-arrow-path-rounded-square class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Switch') }}
                                            </span>
                                            <span wire:loading wire:target="switchOrganization('{{ $org->id }}')" class="inline-flex items-center gap-1.5">
                                                <x-spinner variant="forest" size="sm" />
                                                {{ __('Switching…') }}
                                            </span>
                                        </button>
                                    @endif
                                    <a
                                        href="{{ route('organizations.show', $org) }}"
                                        wire:navigate
                                        class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-forest"
                                    >
                                        {{ __('Overview') }}
                                        <x-heroicon-m-arrow-up-right class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                                    </a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </x-profile-shell>
    </div>
</div>
