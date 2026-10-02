@php
    $th = 'px-3 py-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist sm:px-4';
    $td = 'px-3 py-2 align-top sm:px-4';
@endphp

<div class="dply-page-shell space-y-4 pt-6">
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Projects'), 'href' => route('dashboard'), 'icon' => 'globe-alt'],
        ['label' => __('Resources'), 'icon' => 'squares-2x2'],
    ]" />

    <x-profile-shell
        :title="__('Resources')"
        :description="__('Every app in this organization and everything it uses: containers, databases, Valkey, storage, queues and more. Open a row to manage it.')"
        icon="heroicon-o-squares-2x2"
    >
        <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('App, resource or id') }}" class="dply-input mt-0 w-full sm:w-72" aria-label="{{ __('Search') }}" />
            <select wire:model.live="kind" class="dply-input mt-0 w-auto" aria-label="{{ __('Kind') }}">
                <option value="">{{ __('Every kind') }}</option>
                @foreach (\App\Modules\Edge\Support\EdgeResourceIndex::KINDS as $key => $label)
                    <option value="{{ $key }}">{{ __($label) }}</option>
                @endforeach
            </select>
            @if ($trouble > 0)
                <span class="ml-auto text-xs font-semibold text-amber-800 dark:text-amber-300">{{ trans_choice(':count needs a look|:count need a look', $trouble) }}</span>
            @endif
        </div>

        @forelse ($groups as $group)
            <p class="border-b border-brand-ink/10 px-3 py-2.5 text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss sm:px-4">{{ __($group['label']) }} · {{ count($group['rows']) }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-brand-ink/10 text-left">
                            <th class="{{ $th }}">{{ __('Name') }}</th>
                            <th class="{{ $th }}">{{ __('Kind') }}</th>
                            @if ($group['key'] !== 'app')
                                <th class="{{ $th }}">{{ __('Used by') }}</th>
                            @endif
                            <th class="{{ $th }}">{{ __('Details') }}</th>
                            @if ($group['key'] === 'app')
                                <th class="{{ $th }} text-right">{{ __('This month') }}</th>
                            @endif
                            <th class="{{ $th }}"><span class="sr-only">{{ __('Links') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($group['rows'] as $row)
                            <tr wire:key="r-{{ $group['key'] }}-{{ $row['id'] }}-{{ $loop->index }}" @class(['bg-amber-50/50 dark:bg-amber-900/10' => $row['problem'] !== null])>
                                <td class="{{ $td }}">
                                    <span class="font-medium text-brand-ink">{{ $row['name'] }}</span>
                                    <span class="block font-mono text-2xs text-brand-mist">{{ $row['id'] }}</span>
                                </td>
                                <td class="{{ $td }} whitespace-nowrap text-xs text-brand-moss">
                                    {{ $row['kind'] }}
                                    @if ($row['state'] === 'asleep')
                                        <x-badge size="sm" :caps="false">{{ __('asleep') }}</x-badge>
                                    @endif
                                </td>
                                @if ($group['key'] !== 'app')
                                    <td class="{{ $td }} text-xs text-brand-moss">{{ $row['apps'] !== [] ? implode(', ', $row['apps']) : __('No app') }}</td>
                                @endif
                                <td class="{{ $td }} text-xs text-brand-moss">
                                    {{ $row['detail'] !== '' ? $row['detail'] : '—' }}
                                    @if ($row['problem'])
                                        <span class="mt-0.5 block font-semibold text-amber-800 dark:text-amber-300">{{ $row['problem'] }}</span>
                                    @endif
                                </td>
                                @if ($group['key'] === 'app')
                                    <td class="{{ $td }} whitespace-nowrap text-right font-mono text-xs">{{ $row['costCents'] !== null ? '$'.number_format($row['costCents'] / 100, 2) : '—' }}</td>
                                @endif
                                <td class="{{ $td }} whitespace-nowrap text-right">
                                    @foreach ($row['links'] as $link)
                                        <a href="{{ $link['href'] }}" wire:navigate class="ml-2 text-xs font-medium text-brand-ink underline-offset-2 hover:underline">{{ $link['label'] }}</a>
                                    @endforeach
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="px-4 py-6 text-sm text-brand-moss">{{ $search !== '' || $kind !== '' ? __('Nothing matches.') : __('No apps or resources yet.') }}</p>
        @endforelse
    </x-profile-shell>
</div>
