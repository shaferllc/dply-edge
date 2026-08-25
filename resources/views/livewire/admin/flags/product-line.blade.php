<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Flags'), 'href' => route('admin.flags.all'), 'icon' => 'flag'],
        ['label' => $lineTitle, 'icon' => 'squares-2x2'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="$lineTitle"
        :description="$lineDescription"
        icon="heroicon-o-flag"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('admin.flags.all') }}" wire:navigate size="sm">
                <x-heroicon-o-list-bullet class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('All flags') }}
            </x-outline-link>
        </x-slot:actions>

    @if ($emergencyFlags !== [])
        <section class="border-b border-brand-ink/10" aria-labelledby="emergency-flags-heading">
            <x-workspace-panel-head
                dense
                tone="danger"
                icon="heroicon-o-exclamation-triangle"
                :title="__('Emergency controls')"
                :note="__('Kill switches are set in config/features.php (via env) and shown here read-only.')"
                title-id="emergency-flags-heading"
            />
            <ul class="grid gap-2 px-3 py-3 sm:px-4 lg:grid-cols-2">
                @foreach ($emergencyFlags as $flag)
                    <li wire:key="emergency-{{ $flag['key'] }}">
                        <x-admin-flag-row :flag="$flag" mode="global">
                            <x-admin-flag-state :active="$flag['active']" />
                        </x-admin-flag-row>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

        @foreach ($groups as $group)
            <section class="border-b border-brand-ink/10 last:border-0" wire:key="group-{{ $group['title'] }}">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-adjustments-horizontal"
                    :title="$group['title']"
                    :count="count($group['flags'])"
                    :note="$group['mode'] === 'global'
                        ? __('App-wide flag set in config — not overridable per org.')
                        : __('Config default for every org unless an org has an explicit override.')"
                />
                <ul class="grid gap-2 px-3 py-3 sm:px-4 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="flag-{{ $flag['key'] }}">
                            @if (! empty($flag['preview']))
                                <div class="overflow-hidden rounded-lg border border-brand-ink/10 bg-white shadow-sm">
                                    <div class="border-b border-brand-ink/8 px-3 py-2.5">
                                        <x-admin-flag-row :flag="$flag" :mode="$group['mode'] === 'global' ? 'global' : 'platform'">
                                            @if ($group['mode'] === 'global')
                                                <x-admin-flag-state :active="$flag['active']" />
                                            @else
                                                <div class="flex shrink-0 flex-col items-end gap-2">
                                                    <x-admin-flag-state :active="$flag['active']" />
                                                    @if (($orgOverrideCounts[$flag['key']] ?? 0) > 0)
                                                        <button
                                                            type="button"
                                                            wire:click="requestClearOrgOverridesForFlag('{{ $flag['key'] }}')"
                                                            class="text-2xs font-semibold text-amber-800 underline decoration-amber-800/40 underline-offset-2 hover:text-amber-900"
                                                        >
                                                            {{ __('Clear :count org override(s)', ['count' => $orgOverrideCounts[$flag['key']]]) }}
                                                        </button>
                                                    @endif
                                                </div>
                                            @endif
                                        </x-admin-flag-row>
                                    </div>
                                    <div class="bg-brand-sand/20 px-3 py-2.5">
                                        <x-admin-flag-row :flag="$flag['preview']" mode="platform">
                                            <div class="flex shrink-0 flex-col items-end gap-2">
                                                <x-admin-flag-state :active="$flag['preview']['active']" />
                                                @if (($orgOverrideCounts[$flag['preview']['key']] ?? 0) > 0)
                                                    <button
                                                        type="button"
                                                        wire:click="requestClearOrgOverridesForFlag('{{ $flag['preview']['key'] }}')"
                                                        class="text-2xs font-semibold text-amber-800 underline decoration-amber-800/40 underline-offset-2 hover:text-amber-900"
                                                    >
                                                        {{ __('Clear :count org override(s)', ['count' => $orgOverrideCounts[$flag['preview']['key']]]) }}
                                                    </button>
                                                @endif
                                            </div>
                                        </x-admin-flag-row>
                                        <p class="mt-1.5 ps-0.5 text-2xs leading-relaxed text-brand-moss">{{ __('Shows Soon badge + teaser page when the full workspace above is off. Overridable per org.') }}</p>
                                    </div>
                                </div>
                            @else
                                <x-admin-flag-row :flag="$flag" :mode="$group['mode'] === 'global' ? 'global' : 'platform'">
                                    @if ($group['mode'] === 'global')
                                        <x-admin-flag-state :active="$flag['active']" />
                                    @else
                                        <div class="flex shrink-0 flex-col items-end gap-2">
                                            <x-admin-flag-state :active="$flag['active']" />
                                            @if (($orgOverrideCounts[$flag['key']] ?? 0) > 0)
                                                <button
                                                    type="button"
                                                    wire:click="requestClearOrgOverridesForFlag('{{ $flag['key'] }}')"
                                                    class="text-2xs font-semibold text-amber-800 underline decoration-amber-800/40 underline-offset-2 hover:text-amber-900"
                                                >
                                                    {{ __('Clear :count org override(s)', ['count' => $orgOverrideCounts[$flag['key']]]) }}
                                                </button>
                                            @endif
                                        </div>
                                    @endif
                                </x-admin-flag-row>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </x-profile-shell>

    @include('livewire.partials.confirm-action-modal')
</div>
