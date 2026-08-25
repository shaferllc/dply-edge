<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Organizations'), 'icon' => 'building-office-2'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Organizations')"
        :description="__('Search all organizations, review override counts, and open org-specific flag tabs.')"
        icon="heroicon-o-building-office-2"
    >
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-magnifying-glass"
                :title="__('Search')"
                :note="__('Match on name, slug, or owner email.')"
            />
            <div class="px-3 py-3 sm:px-4">
                <label for="org-search" class="sr-only">{{ __('Search') }}</label>
                <input id="org-search" type="search" wire:model.live.debounce.300ms="search" class="block w-full max-w-md rounded-lg border-brand-ink/15 text-sm shadow-sm focus:border-brand-sage focus:ring-brand-sage/30" placeholder="{{ __('Name, slug, or email…') }}" />
            </div>
        </section>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-left text-sm">
                <thead class="bg-white text-2xs uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 font-semibold sm:px-4">{{ __('Organization') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('Servers') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('Sites') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('Overrides') }}</th>
                        <th class="px-3 py-2 font-semibold">{{ __('Created') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @forelse ($organizations as $org)
                        <tr wire:key="admin-org-{{ $org->id }}" class="hover:bg-brand-sand/20">
                            <td class="px-3 py-2.5 sm:px-4">
                                <a href="{{ route('admin.organizations.show', $org) }}" wire:navigate class="font-medium text-brand-ink hover:underline">{{ $org->name }}</a>
                                <p class="font-mono text-xs text-brand-mist">{{ $org->slug }}</p>
                            </td>
                            <td class="px-3 py-2.5 font-mono tabular-nums text-brand-moss">{{ number_format($org->servers_count) }}</td>
                            <td class="px-3 py-2.5 font-mono tabular-nums text-brand-moss">{{ number_format($org->sites_count) }}</td>
                            <td class="px-3 py-2.5 font-mono tabular-nums text-brand-moss">{{ number_format($overrideCounts[$org->id] ?? 0) }}</td>
                            <td class="px-3 py-2.5 text-xs text-brand-moss">{{ $org->created_at?->timezone(config('app.timezone'))->format('Y-m-d') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-brand-mist sm:px-4">{{ __('No organizations match your search.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>
            {{ $organizations->links() }}
        </x-slot:footer>
    </x-profile-shell>
</div>
