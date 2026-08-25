@php
    $tabs = \App\Support\Admin\AdminFeatureFlags::productLineSlugs();
@endphp

<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Organizations'), 'href' => route('admin.organizations.index'), 'icon' => 'building-office-2'],
        ['label' => $organization->name, 'icon' => 'building-office-2'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="$organization->name"
        :description="__('Per-org Pennant overrides by product line. Emergency global kill switches are not overridable here.')"
        icon="heroicon-o-building-office-2"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('organizations.show', $organization) }}" wire:navigate size="sm">
                {{ __('Open in app') }}
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
            </x-outline-link>
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-2 gap-2">
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Overrides') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ number_format($overrideCount) }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Members') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $members->count() }}</dd>
                </div>
            </dl>
        </x-slot:stats>

        {{-- Members — impersonate any member to see the app from their seat. --}}
        <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-users"
            :title="__('Members')"
            :count="$members->count()"
            :note="__('ID :id', ['id' => $organization->id])"
        />
        <ul class="divide-y divide-brand-ink/5">
            @forelse ($members as $member)
                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium text-brand-ink">{{ $member->name }}</p>
                        <p class="truncate text-xs text-brand-moss">{{ $member->email }}</p>
                    </div>
                    <x-impersonate-button :user="$member" variant="subtle" />
                </li>
            @empty
                <li>
                    <x-empty-state
                        borderless
                        compact
                        icon="heroicon-o-users"
                        :title="__('No members')"
                        :description="__('Nobody has accepted an invitation to this organization yet.')"
                    />
                </li>
            @endforelse
        </ul>
        </section>

        <div class="border-b border-brand-ink/10 px-3 py-2 sm:px-4">
            <x-server-workspace-tablist :aria-label="__('Feature flag product lines')" scroll>
                @foreach ($tabs as $slug => $label)
                    <x-server-workspace-tab :active="$tab === $slug" icon="heroicon-o-flag" wire:click="setTab('{{ $slug }}')">{{ $label }}</x-server-workspace-tab>
                @endforeach
            </x-server-workspace-tablist>
        </div>

    <div class="space-y-3 px-3 py-3 sm:px-4">
        @forelse ($groups as $group)
            <details class="group rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2.5" @if($loop->first) open @endif wire:key="org-group-{{ $group['title'] }}">
                <summary class="cursor-pointer list-none font-semibold text-brand-ink marker:content-none [&::-webkit-details-marker]:hidden">
                    <span class="flex items-center justify-between gap-2">
                        {{ $group['title'] }}
                        <x-heroicon-o-chevron-down class="h-5 w-5 text-brand-moss transition group-open:rotate-180" />
                    </span>
                </summary>
                <ul class="mt-4 space-y-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="org-flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" mode="org">
                                <x-toggle-switch
                                    :enabled="(bool) $flag['active']"
                                    wire:click="toggleOrgFeatureFlag('{{ $flag['key'] }}')"
                                    wire:loading.attr="disabled"
                                    on-label=""
                                    off-label=""
                                />
                            </x-admin-flag-row>
                        </li>
                    @endforeach
                </ul>
            </details>
        @empty
            <x-empty-state
                icon="heroicon-o-flag"
                :title="__('No org-scoped flags')"
                :description="__('This product line has no flags that can be overridden per organization.')"
            />
        @endforelse
    </div>
    </x-profile-shell>
</div>
