<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('All flags'), 'icon' => 'flag'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('All feature flags')"
        :description="__('Every Pennant flag in the app. Toggling here sets a platform-wide default that beats config/env for all scopes — an explicit per-org override still wins.')"
        icon="heroicon-o-flag"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('admin.flags.global') }}" wire:navigate size="sm">
                <x-heroicon-o-globe-alt class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('App-wide') }}
            </x-outline-link>
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-3 gap-2">
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Shown') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $totalShown }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Total') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none text-brand-ink">{{ $totalFlags }}</dd>
                </div>
                <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                    <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Overrides') }}</dt>
                    <dd class="mt-0.5 font-mono text-lg font-semibold tabular-nums leading-none {{ $overriddenCount > 0 ? 'text-brand-rust' : 'text-brand-ink' }}">{{ $overriddenCount }}</dd>
                </div>
            </dl>
        </x-slot:stats>

        {{-- Filters --}}
        <section class="border-b border-brand-ink/10">
        <x-workspace-panel-head
            dense
            icon="heroicon-o-funnel"
            :title="__('Filters')"
            :note="__('Narrow by key, namespace, or override state.')"
        />
        <div class="px-3 py-3 sm:px-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
            <label class="flex-1">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Search') }}</span>
                <input
                    type="search"
                    wire:model.live.debounce.250ms="search"
                    placeholder="{{ __('Filter by key or label…') }}"
                    class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                />
            </label>

            <label class="sm:w-56">
                <span class="mb-1 block text-xs font-semibold uppercase tracking-[0.12em] text-brand-mist">{{ __('Namespace') }}</span>
                <select
                    wire:model.live="namespace"
                    class="w-full rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage"
                >
                    <option value="">{{ __('All namespaces') }}</option>
                    @foreach ($namespaces as $ns)
                        <option value="{{ $ns }}">{{ $ns }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex items-center gap-2 pb-2 text-sm text-brand-moss">
                <input
                    type="checkbox"
                    wire:model.live="onlyOverridden"
                    class="h-4 w-4 rounded border-brand-ink/30 text-brand-sage focus:ring-brand-sage"
                />
                {{ __('Only overridden') }}
            </label>
        </div>

        @if ($search !== '' || $namespace !== '' || $onlyOverridden)
            <div class="mt-3 text-xs text-brand-mist">
                <button type="button" wire:click="resetFilters" class="font-medium text-brand-moss underline-offset-2 hover:underline">
                    {{ __('Reset filters') }}
                </button>
            </div>
        @endif
        </div>
        </section>

        {{-- Flag groups --}}
        @forelse ($groups as $group)
            <section class="border-b border-brand-ink/10 last:border-0" wire:key="ns-{{ $group['namespace'] }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-adjustments-horizontal"
                    :title="$group['namespace']"
                    :count="count($group['flags'])"
                />

                <ul class="grid gap-2 px-3 py-3 sm:px-4 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" mode="platform">
                                <span class="flex shrink-0 items-center gap-2">
                                    @if ($flag['overridden'])
                                        <span
                                            class="inline-flex items-center gap-1 rounded-full bg-brand-rust/10 px-2 py-0.5 text-2xs font-semibold text-brand-rust"
                                            title="{{ __('Platform override — differs from config default') }}"
                                        >
                                            {{ __('override') }}
                                            <button
                                                type="button"
                                                wire:click.prevent="resetPlatformFlag('{{ $flag['key'] }}')"
                                                wire:loading.attr="disabled"
                                                class="underline-offset-2 hover:underline"
                                            >{{ __('reset') }}</button>
                                        </span>
                                    @endif

                                    @if ($flag['orgOverrides'] > 0)
                                        <button
                                            type="button"
                                            wire:click.prevent="requestClearOrgOverrides('{{ $flag['key'] }}')"
                                            wire:loading.attr="disabled"
                                            class="inline-flex items-center rounded-full bg-brand-ink/5 px-2 py-0.5 text-2xs font-medium text-brand-moss hover:bg-brand-ink/10"
                                            title="{{ __('Clear per-org overrides') }}"
                                        >{{ __(':count org', ['count' => $flag['orgOverrides']]) }}</button>
                                    @endif

                                    <x-toggle-switch
                                        :enabled="(bool) $flag['active']"
                                        wire:click="togglePlatformFlag('{{ $flag['key'] }}')"
                                        wire:loading.attr="disabled"
                                        on-label=""
                                        off-label=""
                                    />
                                </span>
                            </x-admin-flag-row>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <div class="px-3 py-10 text-center text-sm text-brand-moss sm:px-4">
                {{ __('No flags match your filters.') }}
            </div>
        @endforelse
    </x-profile-shell>

    @include('livewire.partials.confirm-action-modal')
</div>
