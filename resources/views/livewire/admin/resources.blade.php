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

        @if ($flash)
            <div class="flex items-start gap-3 border-b border-brand-ink/10 bg-brand-sand/30 px-4 py-2.5 text-sm text-brand-ink">
                <span class="min-w-0 flex-1">{{ $flash }}</span>
                <button type="button" wire:click="$set('flash', null)" class="text-xs text-brand-moss hover:text-brand-ink">{{ __('Dismiss') }}</button>
            </div>
        @endif

        @if ($queryFor)
            @php($access = $this->access($queryFor))
            <div class="border-b border-brand-ink/10 px-4 py-4">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold uppercase tracking-[0.14em] text-brand-moss">{{ __('Query') }}</span>
                    <span class="font-mono text-xs text-brand-ink">{{ $queryFor }}</span>
                    <x-secondary-button size="xs" class="ml-auto" wire:click="closeQuery">{{ __('Close') }}</x-secondary-button>
                </div>
                @if ($access === null)
                    <p class="mt-3 text-sm text-brand-moss">{{ __('This is customer data. Say why you need it: you get :minutes minutes on this database, every query is logged, and the customer sees it under Activity.', ['minutes' => \App\Livewire\Admin\Resources::ACCESS_MINUTES]) }}</p>
                    <form wire:submit="startAccess" class="mt-2 flex flex-wrap items-start gap-2">
                        <div class="min-w-0 flex-1">
                            <input type="text" wire:model="accessReason" placeholder="{{ __('Support ticket, or what you are investigating') }}" class="dply-input mt-0 w-full" />
                            <x-input-error :messages="$errors->get('accessReason')" class="mt-1" />
                        </div>
                        <x-primary-button type="submit" size="sm">{{ __('Open for :minutes minutes', ['minutes' => \App\Livewire\Admin\Resources::ACCESS_MINUTES]) }}</x-primary-button>
                    </form>
                @else
                    <p class="mt-2 text-xs text-brand-moss">{{ __('Open until :time · reason: :reason', ['time' => \Illuminate\Support\Carbon::parse($access['until'])->format('H:i'), 'reason' => $access['reason']]) }}</p>
                    <form wire:submit="runQuery" class="mt-2 space-y-2">
                        @if (str_starts_with($queryFor, 'mg-'))
                            <div class="flex flex-wrap gap-2">
                                <input type="text" wire:model="queryCollection" placeholder="{{ __('Collection') }}" class="dply-input mt-0 w-48 font-mono" />
                                <input type="text" wire:model="queryFilter" placeholder='{"status": "open"}' class="dply-input mt-0 min-w-0 flex-1 font-mono" />
                            </div>
                        @else
                            <textarea wire:model="querySql" rows="3" placeholder="select * from users order by id desc limit 20" class="dply-input mt-0 w-full font-mono text-xs"></textarea>
                        @endif
                        <x-primary-button type="submit" size="sm" wire:loading.attr="disabled">{{ __('Run (read-only)') }}</x-primary-button>
                    </form>
                    @if ($queryResult !== null)
                        @if (isset($queryResult['error']))
                            <p class="mt-3 break-all font-mono text-xs text-red-800 dark:text-red-300">{{ $queryResult['error'] }}</p>
                        @else
                            <pre class="mt-3 max-h-96 overflow-auto rounded-lg bg-brand-sand/30 px-3 py-2 font-mono text-2xs text-brand-ink">{{ json_encode($queryResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        @endif
                    @endif
                @endif
            </div>
        @endif

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
                            @if (in_array($group['key'], ['app', 'database', 'redis'], true))
                                <th class="{{ $th }}"><span class="sr-only">{{ __('Actions') }}</span></th>
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
                                    @if (isset($live[$row['id']]))
                                        <span class="mt-0.5 block font-mono text-2xs text-brand-ink">{{ $live[$row['id']] }}</span>
                                    @endif
                                </td>
                                @if ($group['key'] === 'app')
                                    <td class="{{ $td }} whitespace-nowrap text-right font-mono text-xs">{{ $row['costCents'] !== null ? '$'.number_format($row['costCents'] / 100, 2) : '—' }}</td>
                                @endif
                                @if (in_array($group['key'], ['app', 'database', 'redis'], true))
                                    <td class="{{ $td }} whitespace-nowrap text-right">
                                        @if ($group['key'] === 'app' && $row['engine'] === 'container')
                                            <x-secondary-button size="xs" wire:click="liveState('{{ $row['id'] }}')">{{ __('Live state') }}</x-secondary-button>
                                        @elseif ($group['key'] === 'database')
                                            <x-secondary-button size="xs" wire:click="openQuery('{{ $row['id'] }}')">{{ __('Query') }}</x-secondary-button>
                                            @if ($row['engine'] === 'postgres')
                                                <x-secondary-button size="xs" wire:click="verifyBackup('{{ $row['id'] }}')">{{ __('Restore check') }}</x-secondary-button>
                                            @endif
                                            <x-secondary-button size="xs" wire:click="sleepResource('database', '{{ $row['id'] }}')" wire:confirm="{{ __('Put :name to sleep? Open connections drop; it wakes on the next one.', ['name' => $row['name']]) }}">{{ __('Sleep') }}</x-secondary-button>
                                        @elseif ($group['key'] === 'redis' && str_starts_with($row['id'], 'valkey:'))
                                            <x-secondary-button size="xs" wire:click="sleepResource('redis', '{{ $row['id'] }}')" wire:confirm="{{ __('Put :name to sleep? Its data is saved first; it wakes on the next connection.', ['name' => $row['name']]) }}">{{ __('Sleep') }}</x-secondary-button>
                                        @endif
                                    </td>
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
