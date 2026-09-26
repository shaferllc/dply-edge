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
        :description="__('Members of this organization. Impersonate one to see the app from their seat.')"
        icon="heroicon-o-building-office-2"
    >
        <x-slot:actions>
            <x-outline-link href="{{ route('organizations.show', $organization) }}" wire:navigate size="sm">
                {{ __('Open in app') }}
                <x-heroicon-o-arrow-top-right-on-square class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
            </x-outline-link>
        </x-slot:actions>

        <x-slot:stats>
            <dl class="grid grid-cols-1 gap-2">
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

    </x-profile-shell>
</div>
