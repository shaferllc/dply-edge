@php
    /**
     * Per-channel notification matrix: each channel is an accordion row whose body
     * lists the events (grouped) it is subscribed to. Different channels can carry
     * different events.
     *
     * @var \Illuminate\Support\Collection $channels  Assignable channels.
     * @var array $eventGroups  list of ['label' => string, 'events' => array<key,label>]
     * @var array $selections   channelId => list<eventKey> (the component's bound prop)
     */
    $model = $model ?? 'channelEventSelections';
    $showFilter = $showFilter ?? false;
    $multiGroup = count($eventGroups) > 1;
@endphp

<div x-data="{ q: '' }">
    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <button
            type="button"
            wire:click="openCreateChannelModal"
            class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40"
        >
            <x-heroicon-o-plus-circle class="h-4 w-4 shrink-0" aria-hidden="true" />
            {{ __('Create a channel') }}
        </button>
        @if ($showFilter && ! $channels->isEmpty())
            <input aria-label="{{ __('Filter events') }}" type="search" x-model="q" placeholder="{{ __('Filter events…') }}"
                class="w-52 rounded-md border-brand-ink/15 text-xs shadow-sm focus:border-brand-sage focus:ring-brand-sage">
        @endif
    </div>

    @if ($channels->isEmpty())
        <p class="rounded-xl border border-dashed border-brand-ink/15 px-3 py-3 text-sm text-brand-moss">
            {{ __('No channels yet. Create one above (or under My channels / Organization channels) to start routing events.') }}
        </p>
    @else
        <div class="space-y-3">
            @foreach ($channels as $channel)
                @php $selected = (array) ($selections[$channel->id] ?? []); @endphp
                <div
                    wire:key="notif-matrix-ch-{{ $channel->id }}"
                    x-data="{ open: false, count: @js(count($selected)) }"
                    class="overflow-hidden rounded-xl border border-brand-ink/10 bg-white"
                >
                    <div class="flex items-center gap-1 pr-2 hover:bg-brand-sand/20">
                        <button type="button" @click="open = ! open"
                            class="flex min-w-0 flex-1 items-center justify-between gap-3 px-4 py-3 text-left">
                            <span class="min-w-0">
                                <span class="text-sm font-medium text-brand-ink">{{ $channel->label }}</span>
                                <span class="ml-1 text-xs text-brand-mist">[{{ \App\Models\NotificationChannel::labelForType($channel->type) }}]</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-0.5 text-xs font-semibold"
                                    :class="count > 0 ? 'bg-brand-forest/10 text-brand-forest' : 'bg-brand-sand/60 text-brand-mist'"
                                    x-text="count > 0 ? count + ' {{ __('events') }}' : '{{ __('Off') }}'"
                                ></span>
                                <span class="text-brand-mist transition" x-bind:class="open && 'rotate-180'">
                                    <x-heroicon-o-chevron-down class="h-4 w-4" aria-hidden="true" />
                                </span>
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="sendTestChannelNotification('{{ $channel->id }}')"
                            wire:loading.attr="disabled"
                            wire:target="sendTestChannelNotification('{{ $channel->id }}')"
                            title="{{ __('Send a test notification to this channel') }}"
                            class="inline-flex shrink-0 items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1 text-xs font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/40 disabled:opacity-60"
                        >
                            <span wire:loading.remove wire:target="sendTestChannelNotification('{{ $channel->id }}')" class="inline-flex items-center gap-1.5">
                                <x-heroicon-o-paper-airplane class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                {{ __('Test') }}
                            </span>
                            <span wire:loading wire:target="sendTestChannelNotification('{{ $channel->id }}')" class="inline-flex items-center gap-1.5">
                                <x-spinner variant="forest" size="sm" />
                                {{ __('Sending…') }}
                            </span>
                        </button>
                    </div>

                    <div
                        x-show="open"
                        x-cloak
                        x-collapse
                        @change="count = $el.querySelectorAll('input[type=checkbox]:checked').length"
                        class="border-t border-brand-ink/10 px-4 py-3"
                    >
                        <div class="space-y-4">
                            @foreach ($eventGroups as $group)
                                @continue(empty($group['events']))
                                <div x-show="q === '' || @js(strtolower($group['label'].' '.implode(' ', $group['events']))).includes(q.toLowerCase())">
                                    @if ($multiGroup)
                                        <p class="text-xs font-semibold text-brand-ink">{{ $group['label'] }}</p>
                                    @endif
                                    <div class="mt-1.5 grid gap-1.5 sm:grid-cols-2">
                                        @foreach ($group['events'] as $eventKey => $eventLabel)
                                            <label
                                                x-show="q === '' || @js(strtolower($eventLabel)).includes(q.toLowerCase()) || @js(strtolower($group['label'])).includes(q.toLowerCase())"
                                                class="flex items-center gap-2.5 rounded-lg border border-brand-ink/10 bg-brand-sand/15 px-3 py-1.5 text-sm text-brand-ink"
                                            >
                                                <input
                                                    type="checkbox"
                                                    wire:model="{{ $model }}.{{ $channel->id }}"
                                                    value="{{ $eventKey }}"
                                                    class="rounded border-brand-ink/20 text-brand-sage focus:ring-brand-sage"
                                                >
                                                <span>{{ $eventLabel }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
