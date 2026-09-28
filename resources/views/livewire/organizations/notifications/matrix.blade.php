{{-- Routing matrix: events × channels. Each cell toggles one org-wide subscription. --}}
@php
    $menuItem = 'flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-brand-ink transition-colors hover:bg-brand-sand/40';
@endphp
<section class="dply-card overflow-hidden p-0" aria-label="{{ __('Notification routing') }}">
    <div class="overflow-x-auto">
        <table class="min-w-full text-left text-sm">
            <thead>
                <tr class="border-b border-brand-ink/10">
                    <th scope="col" class="px-5 py-3 align-bottom text-2xs font-semibold uppercase tracking-[0.14em] text-brand-mist sm:px-6">{{ __('Event') }}</th>
                    @foreach ($channels as $channel)
                        <th scope="col" wire:key="nc-col-{{ $channel->id }}" class="min-w-[8.5rem] px-3 py-3 align-bottom font-normal">
                            <div class="flex items-start justify-center gap-1">
                                <div class="min-w-0 text-center">
                                    <p class="truncate text-sm font-semibold text-brand-ink" title="{{ $channel->describeDestination() ?: $channel->label }}">{{ $channel->label }}</p>
                                    <p class="text-xs text-brand-moss">{{ \App\Models\NotificationChannel::labelForType($channel->type) }}</p>
                                    @if ($channel->isPaging())
                                        {{-- A pager mistaken for a chat room is a 3am phone call. --}}
                                        <span class="mt-1 inline-flex items-center gap-0.5 rounded bg-rose-500/10 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-rose-700 dark:text-rose-300" title="{{ __('Alerts sent here page whoever is on call for the PagerDuty service.') }}">
                                            <x-heroicon-m-bell-alert class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Pages on-call') }}
                                        </span>
                                    @endif
                                    @if ($channel->subscriptions_count === 0)
                                        {{-- Configured, test passes, never fires: the page's quietest failure. --}}
                                        <span class="mt-1 inline-flex items-center gap-0.5 rounded bg-amber-500/15 px-1.5 py-px text-2xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-300" title="{{ __('This channel is not subscribed to any events, so nothing will be delivered to it.') }}">
                                            <x-heroicon-m-exclamation-triangle class="h-3 w-3" aria-hidden="true" />
                                            {{ __('Not routed') }}
                                        </span>
                                    @endif
                                </div>
                                @if ($canManage)
                                    <x-dropdown align="right" width="w-40" contentClasses="py-1">
                                        <x-slot name="trigger">
                                            <button type="button" class="dply-icon-btn -mr-1" aria-label="{{ __('Actions for :channel', ['channel' => $channel->label]) }}" aria-haspopup="menu">
                                                <x-heroicon-m-ellipsis-vertical class="h-4 w-4" aria-hidden="true" />
                                            </button>
                                        </x-slot>
                                        <x-slot name="content">
                                            <button type="button" wire:click="sendTest('{{ $channel->id }}')" class="{{ $menuItem }}">
                                                <x-heroicon-o-paper-airplane class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                                {{ __('Send test') }}
                                            </button>
                                            <button type="button" wire:click="editChannel('{{ $channel->id }}')" class="{{ $menuItem }}">
                                                <x-heroicon-o-pencil-square class="h-4 w-4 shrink-0 text-brand-moss" aria-hidden="true" />
                                                {{ __('Edit') }}
                                            </button>
                                            <button
                                                type="button"
                                                wire:click="openConfirmActionModal('deleteChannel', ['{{ $channel->id }}'], @js(__('Delete destination')), @js(__('Remove :channel? Every event routed to it stops going there.', ['channel' => $channel->label])), @js(__('Delete')), true)"
                                                class="{{ $menuItem }} text-rose-700 dark:text-rose-300"
                                            >
                                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                                                {{ __('Delete') }}
                                            </button>
                                        </x-slot>
                                    </x-dropdown>
                                @endif
                            </div>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-brand-ink/8">
                @foreach ($events as $event)
                    <tr wire:key="nc-row-{{ $event['key'] }}" class="transition-colors hover:bg-brand-sand/15">
                        <th scope="row" class="px-5 py-3 font-normal sm:px-6">
                            <p class="font-semibold text-brand-ink">{{ $event['label'] }}</p>
                            @if ($event['help'] !== '')
                                <p class="mt-0.5 text-xs text-brand-moss">{{ $event['help'] }}</p>
                            @endif
                        </th>
                        @foreach ($channels as $channel)
                            @php($routed = in_array($event['key'], $matrix[(string) $channel->id] ?? [], true))
                            <td wire:key="nc-cell-{{ $channel->id }}-{{ $event['key'] }}" class="px-3 py-3 text-center">
                                <input
                                    type="checkbox"
                                    @checked($routed)
                                    @disabled(! $canManage)
                                    @if ($canManage)
                                        wire:click="toggleRoute('{{ $channel->id }}', '{{ $event['key'] }}')"
                                    @endif
                                    aria-label="{{ __('Send “:event” to :channel', ['event' => $event['label'], 'channel' => $channel->label]) }}"
                                    class="h-4 w-4 cursor-pointer rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage disabled:cursor-not-allowed disabled:opacity-60"
                                />
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
