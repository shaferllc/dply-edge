<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Users'), 'icon' => 'users'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Users')"
        :description="__('Search any user and impersonate them to see the app from their perspective.')"
        icon="heroicon-o-users"
    >
        <section class="border-b border-brand-ink/10">
            <x-workspace-panel-head
                dense
                icon="heroicon-o-magnifying-glass"
                :title="__('Search')"
                :note="__('Match on name or email address.')"
            />
            <div class="px-3 py-3 sm:px-4">
                <label for="user-search" class="sr-only">{{ __('Search') }}</label>
                <input
                    id="user-search"
                    type="search"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search by name or email…') }}"
                    class="block w-full max-w-md rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm placeholder:text-brand-mist focus:border-brand-sage focus:ring-brand-sage/30"
                />
            </div>
        </section>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-brand-ink/10 text-sm">
                <thead class="bg-white text-left text-2xs font-semibold uppercase tracking-wide text-brand-mist">
                    <tr>
                        <th class="px-3 py-2 sm:px-4">{{ __('Name') }}</th>
                        <th class="px-3 py-2">{{ __('Email') }}</th>
                        <th class="px-3 py-2">{{ __('Organizations') }}</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @forelse ($users as $user)
                        <tr class="hover:bg-brand-sand/20">
                            <td class="px-3 py-2.5 font-medium text-brand-ink sm:px-4">{{ $user->name }}</td>
                            <td class="px-3 py-2.5 text-brand-moss">{{ $user->email }}</td>
                            <td class="px-3 py-2.5 text-brand-moss">
                                {{ $user->organizations->pluck('name')->join(', ') ?: '—' }}
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                <div class="flex justify-end">
                                    <x-impersonate-button :user="$user" variant="subtle" />
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-3 py-10 text-center text-brand-mist sm:px-4">{{ __('No users match that search.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-slot:footer>
            {{ $users->links() }}
        </x-slot:footer>
    </x-profile-shell>
</div>
