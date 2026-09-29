<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Feature flags'), 'icon' => 'flag'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Feature flags')"
        :description="__('Which resources each organization can add under Add resource. Off hides it; apps that already have one keep it. Localhost only: elsewhere use php artisan dply:feature.')"
        icon="heroicon-o-flag"
    >
        <div class="border-b border-brand-ink/10 px-3 py-2.5 sm:px-4">
            <input aria-label="{{ __('Search organizations') }}" type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Search organizations') }}" class="dply-input mt-0 w-full sm:w-72" />
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-brand-ink/10 text-left">
                        <th class="px-3 py-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist sm:px-4">{{ __('Organization') }}</th>
                        @foreach ($kinds as $kind => $label)
                            <th class="px-2 py-2 text-center align-bottom">
                                <span class="block text-2xs font-semibold uppercase tracking-[0.1em] text-brand-mist">{{ __($label) }}</span>
                                <span class="mt-1 inline-flex gap-1 text-2xs font-semibold">
                                    <button type="button" wire:click="setForAll('{{ $kind }}', true)" class="text-brand-forest hover:underline" title="{{ __('Turn on for every organization') }}">{{ __('all on') }}</button>
                                    <span class="text-brand-mist">·</span>
                                    <button type="button" wire:click="setForAll('{{ $kind }}', false)" class="text-brand-moss hover:underline" title="{{ __('Turn off for every organization') }}">{{ __('off') }}</button>
                                </span>
                            </th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-brand-ink/5">
                    @forelse ($organizations as $organization)
                        <tr wire:key="flags-{{ $organization->id }}">
                            <td class="px-3 py-2 sm:px-4">
                                <span class="block font-semibold text-brand-ink">{{ $organization->name }}</span>
                                <span class="block font-mono text-2xs text-brand-mist">{{ $organization->slug }}</span>
                            </td>
                            @foreach ($kinds as $kind => $label)
                                @php $on = (bool) ($states[$organization->id][\App\Modules\Edge\Support\EdgeContainerConnections::flag($kind)] ?? false); @endphp
                                <td class="px-2 py-2 text-center">
                                    <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}" aria-label="{{ __(':kind for :org', ['kind' => __($label), 'org' => $organization->name]) }}"
                                        wire:click="toggle('{{ $organization->id }}', '{{ $kind }}')"
                                        @class(['relative inline-flex h-5 w-9 shrink-0 rounded-full transition', 'bg-brand-forest' => $on, 'bg-brand-ink/15 dark:bg-brand-mist/25' => ! $on])>
                                        <span @class(['absolute top-0.5 h-4 w-4 rounded-full bg-white shadow transition-all', 'left-[1.125rem]' => $on, 'left-0.5' => ! $on])></span>
                                    </button>
                                </td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($kinds) + 1 }}" class="px-4 py-6 text-center text-brand-moss">{{ __('No organizations match.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-profile-shell>
</div>
