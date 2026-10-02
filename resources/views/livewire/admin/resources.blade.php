@php
    $th = 'px-3 py-2 text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist sm:px-4';
    $td = 'px-3 py-2 align-top sm:px-4';
    $section = 'border-b border-brand-ink/10 px-3 py-2.5 text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss sm:px-4';
@endphp

<div>
    <x-breadcrumb-trail :items="[
        ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
        ['label' => __('Platform admin'), 'href' => route('admin.overview'), 'icon' => 'shield-check'],
        ['label' => __('Resources'), 'icon' => 'circle-stack'],
    ]" />

    <x-profile-shell
        class="mt-4"
        :title="__('Resources')"
        :description="__('Every organization\'s apps, databases, Valkey and other resources, from what dply already records. Read-only: nothing here wakes or calls an app.')"
        icon="heroicon-o-circle-stack"
    >
        <x-slot:actions>
            <x-secondary-button size="sm" wire:click="$refresh" wire:loading.attr="disabled">
                <x-heroicon-o-arrow-path class="h-4 w-4 shrink-0" wire:loading.class="animate-spin" aria-hidden="true" />
                {{ __('Refresh') }}
            </x-secondary-button>
        </x-slot:actions>

        <div class="flex flex-wrap items-center gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="{{ __('Organization, app, resource or id') }}" class="dply-input mt-0 w-full sm:w-72" aria-label="{{ __('Search') }}" />
            <select wire:model.live="kind" class="dply-input mt-0 w-auto" aria-label="{{ __('Kind') }}">
                <option value="">{{ __('Every kind') }}</option>
                @foreach (\App\Livewire\Admin\Resources::KINDS as $key => $label)
                    <option value="{{ $key }}">{{ __($label) }}</option>
                @endforeach
            </select>
            <label class="inline-flex items-center gap-1.5 text-sm text-brand-moss">
                <input type="checkbox" wire:model.live="troubleOnly" class="rounded border-brand-ink/20" />
                {{ __('Needs a look only') }}
            </label>
            <span class="ml-auto text-xs text-brand-moss">
                {{ trans_choice(':count row|:count rows', $total) }}
                @if ($trouble > 0)
                    · <span class="font-semibold text-amber-800 dark:text-amber-300">{{ trans_choice(':count needs a look|:count need a look', $trouble) }}</span>
                @endif
            </span>
        </div>

        @forelse ($groups as $group)
            <p class="{{ $section }}">{{ __($group['label']) }} · {{ count($group['rows']) }}</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-brand-ink/10 text-left">
                            <th class="{{ $th }}">{{ __('Name') }}</th>
                            <th class="{{ $th }}">{{ __('Kind') }}</th>
                            <th class="{{ $th }}">{{ __('Organization') }}</th>
                            @if ($group['key'] !== 'app')
                                <th class="{{ $th }}">{{ __('Used by') }}</th>
                            @endif
                            <th class="{{ $th }}">{{ __('Details') }}</th>
                            @if ($group['key'] === 'app')
                                <th class="{{ $th }} text-right">{{ __('This month') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-brand-ink/5">
                        @foreach ($group['rows'] as $row)
                            <tr wire:key="res-{{ $group['key'] }}-{{ $row['id'] }}-{{ $loop->index }}" @class(['bg-amber-50/50 dark:bg-amber-900/10' => $row['problem'] !== null])>
                                <td class="{{ $td }}">
                                    @if ($row['href'])
                                        <a href="{{ $row['href'] }}" class="font-medium text-brand-ink underline-offset-2 hover:underline">{{ $row['name'] }}</a>
                                    @else
                                        <span class="font-medium text-brand-ink">{{ $row['name'] }}</span>
                                    @endif
                                    <span class="block font-mono text-2xs text-brand-mist">{{ $row['id'] }}</span>
                                </td>
                                <td class="{{ $td }} whitespace-nowrap text-xs text-brand-moss">
                                    {{ $row['kind'] }}
                                    @if ($row['state'] !== '')
                                        <x-badge size="sm" :caps="false" :tone="$row['state'] === 'asleep' ? 'neutral' : 'success'">{{ $row['state'] }}</x-badge>
                                    @endif
                                </td>
                                <td class="{{ $td }} text-xs">
                                    @if ($row['orgId'])
                                        <a href="{{ route('admin.organizations.show', $row['orgId']) }}" wire:navigate class="text-brand-ink underline-offset-2 hover:underline">{{ $row['org'] }}</a>
                                    @else
                                        {{ $row['org'] }}
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
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="px-4 py-6 text-sm text-brand-moss">{{ __('Nothing matches.') }}</p>
        @endforelse
    </x-profile-shell>
</div>
