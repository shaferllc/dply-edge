<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('App-wide flags'), 'icon' => 'flag'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('App-wide feature flags')"
        :description="__('Global kill switches and cross-cutting product flags. These are set in config/features.php (via env) and shown here read-only.')"
        icon="heroicon-o-flag"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('admin.flags.all') }}" wire:navigate size="sm">
                <x-heroicon-o-list-bullet class="h-4 w-4 shrink-0 opacity-90" aria-hidden="true" />
                {{ __('All flags') }}
            </x-outline-link>
        </x-slot:actions>

        @foreach ($groups as $group)
            <section class="border-b border-brand-ink/10 last:border-0">
                <x-workspace-panel-head
                    dense
                    icon="heroicon-o-adjustments-horizontal"
                    :title="$group['title']"
                    :count="count($group['flags'])"
                />
                <ul class="grid gap-2 px-3 py-3 sm:px-4 lg:grid-cols-2">
                    @foreach ($group['flags'] as $flag)
                        <li wire:key="global-flag-{{ $flag['key'] }}">
                            <x-admin-flag-row :flag="$flag" mode="global">
                                <x-admin-flag-state :active="$flag['active']" />
                            </x-admin-flag-row>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </x-profile-shell>
</div>
