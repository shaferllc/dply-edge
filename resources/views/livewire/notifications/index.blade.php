<div class="py-8">
    <div class="dply-page-shell space-y-6">
        <x-breadcrumb-trail doc-route="docs.index" :items="[
            ['label' => __('Dashboard'), 'href' => route('dashboard'), 'icon' => 'home'],
            ['label' => __('Notifications'), 'icon' => 'bell-alert'],
        ]" />

        {{-- Hero: positioning + at-a-glance rollups. --}}
        <x-profile-shell
            icon="heroicon-o-bell"
            :title="__('Notifications')"
            :description="$notificationsReady
                ? __('Deploys, monitoring alerts, SSL events, and security findings across everything you can access.')
                : __('Run the latest database migrations to enable the shared inbox.')"
        >
            <x-slot:actions>
            @if ($notificationsReady && $unreadCount > 0)
                <button
                    type="button"
                    wire:click="markAllAsRead"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-ink px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition-colors hover:bg-brand-forest"
                >
                    <x-heroicon-o-check class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Mark all read') }}
                </button>
            @endif
            @if ($notificationsReady && $totalCount > 0)
                <button
                    type="button"
                    wire:click="openConfirmActionModal('deleteAllRead', [], @js(__('Delete all read notifications?')), @js(__('Saved (starred) items are kept. This cannot be undone.')), @js(__('Delete all read')), true)"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 py-1.5 text-xs font-semibold text-rose-700 shadow-sm transition hover:bg-rose-50"
                >
                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Delete all read') }}
                </button>
            @endif
            </x-slot:actions>

            <x-slot:stats>
                <dl class="grid grid-cols-4 gap-2">
                    <div @class([
                        'rounded-xl border px-3 py-2',
                        'border-brand-gold/40 bg-brand-gold/8' => $unreadCount > 0,
                        'border-brand-ink/10 bg-white/80' => $unreadCount === 0,
                    ])>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Unread') }}</dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold leading-none tabular-nums text-brand-ink">{{ $unreadCount }}</dd>
                    </div>
                    <div @class([
                        'rounded-xl border px-3 py-2',
                        'border-amber-200 bg-amber-50/60' => $attentionCount > 0,
                        'border-brand-ink/10 bg-white/80' => $attentionCount === 0,
                    ])>
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Attention') }}</dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold leading-none tabular-nums {{ $attentionCount > 0 ? 'text-amber-700' : 'text-brand-ink' }}">{{ $attentionCount }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Saved') }}</dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold leading-none tabular-nums text-brand-ink">{{ $savedCount }}</dd>
                    </div>
                    <div class="rounded-xl border border-brand-ink/10 bg-white/80 px-3 py-2">
                        <dt class="text-2xs font-semibold uppercase tracking-wide text-brand-mist">{{ __('Total') }}</dt>
                        <dd class="mt-0.5 font-mono text-lg font-semibold leading-none tabular-nums text-brand-ink">{{ $totalCount }}</dd>
                    </div>
                </dl>
            </x-slot:stats>

        @if ($notificationsReady)
            {{-- Toolbar: tabs + filters. --}}
            <div class="flex flex-col gap-3 border-b border-brand-ink/10 px-3 py-3 sm:px-4 lg:flex-row lg:items-center lg:justify-between">
                <div class="inline-flex items-center gap-1 rounded-xl border border-brand-ink/10 bg-white p-1 shadow-sm">
                    @foreach (['unread' => __('Unread'), 'all' => __('All'), 'saved' => __('Saved')] as $key => $label)
                        <button
                            type="button"
                            wire:click="$set('filter', '{{ $key }}')"
                            @class([
                                'inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold transition',
                                'bg-brand-ink text-brand-cream shadow-sm' => $filter === $key,
                                'text-brand-moss hover:text-brand-ink' => $filter !== $key,
                            ])
                        >
                            @if ($key === 'saved')
                                <x-heroicon-s-star class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                            @endif
                            {{ $label }}
                            @php $tabCount = $key === 'unread' ? $unreadCount : ($key === 'saved' ? $savedCount : $totalCount); @endphp
                            @if ($tabCount > 0)
                                <span @class([
                                    'rounded-full px-1.5 py-0.5 text-2xs font-semibold tabular-nums',
                                    'bg-brand-cream/20 text-brand-cream' => $filter === $key,
                                    'bg-brand-sand/60 text-brand-moss' => $filter !== $key,
                                ])>{{ $tabCount }}</span>
                            @endif
                        </button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    @if ($categories->isNotEmpty())
                        <select wire:model.live="categoryFilter" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                            <option value="">{{ __('All categories') }}</option>
                            @foreach ($categories as $cat)
                                <option value="{{ $cat }}">{{ \Illuminate\Support\Str::headline(str_replace('_', ' ', $cat)) }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select wire:model.live="severityFilter" class="rounded-lg border border-brand-ink/15 bg-white px-3 py-2 text-sm text-brand-ink shadow-sm focus:border-brand-sage focus:ring-brand-sage">
                        <option value="">{{ __('All severities') }}</option>
                        @foreach (['critical' => __('Critical'), 'error' => __('Error'), 'danger' => __('Danger'), 'warning' => __('Warning'), 'info' => __('Info'), 'success' => __('Success')] as $sevKey => $sevLabel)
                            <option value="{{ $sevKey }}">{{ $sevLabel }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Bulk action bar — appears once items are selected. --}}
            @if (count($selected) > 0)
                <div class="flex flex-wrap items-center gap-2 border-b border-brand-ink/10 bg-brand-sand/30 px-3 py-2.5 sm:px-4">
                    <span class="text-sm font-semibold text-brand-ink">{{ trans_choice('{1}1 selected|[2,*]:count selected', count($selected), ['count' => count($selected)]) }}</span>
                    <span class="mx-1 h-4 w-px bg-brand-ink/15"></span>
                    <button type="button" wire:click="markSelectedRead" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink hover:bg-white/70">
                        <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /> {{ __('Mark read') }}
                    </button>
                    <button type="button" wire:click="saveSelected" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink hover:bg-white/70">
                        <x-heroicon-o-star class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /> {{ __('Save') }}
                    </button>
                    <button type="button" wire:click="unsaveSelected" class="inline-flex items-center gap-1.5 rounded-lg border border-brand-ink/15 bg-white px-2.5 py-1.5 text-xs font-semibold text-brand-ink hover:bg-white/70">
                        <x-heroicon-o-star class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /> {{ __('Unsave') }}
                    </button>
                    <button type="button" wire:click="openConfirmActionModal('deleteSelected', [], @js(__('Delete selected notifications?')), @js(__('Saved (starred) ones are kept. This cannot be undone.')), @js(__('Delete')), true)" class="inline-flex items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-2.5 py-1 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                        <x-heroicon-o-trash class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /> {{ __('Delete') }}
                    </button>
                    <button type="button" wire:click="$set('selected', [])" class="ml-auto text-xs font-semibold text-brand-moss hover:text-brand-ink">{{ __('Clear selection') }}</button>
                </div>
            @endif
        @endif

        {{-- Feed. --}}
        <div class="space-y-3 px-3 py-3 sm:px-4">
            @forelse ($items as $item)
                @php
                    $meta = is_array($item->event?->metadata ?? null) ? $item->event->metadata : [];
                    $itemSeverity = $item->event?->severity ?? ($meta['severity'] ?? null);
                    $itemResolved = strtolower((string) ($meta['insight_state'] ?? '')) === 'resolved';
                    $isSaved = $item->saved_at !== null;
                @endphp
                <div class="flex items-start gap-3" wire:key="inbox-{{ $item->id }}">
                    <input
                        type="checkbox"
                        wire:model.live="selected"
                        value="{{ $item->id }}"
                        class="mt-6 h-4 w-4 shrink-0 rounded border-brand-ink/25 text-brand-forest focus:ring-brand-sage"
                        aria-label="{{ __('Select notification') }}"
                    />
                    <x-notification-card
                        class="flex-1"
                        :severity="$itemSeverity"
                        :is-resolved="$itemResolved"
                        :unread="! $item->read_at"
                        :title="$item->title"
                        :body="$item->body"
                        :category="$item->event?->category"
                        :time="$item->created_at?->diffForHumans()"
                    >
                        <x-slot name="actions">
                            <button
                                type="button"
                                wire:click="toggleSaved('{{ $item->id }}')"
                                title="{{ $isSaved ? __('Saved — click to unsave') : __('Save to remember') }}"
                                class="rounded-lg border px-2 py-1.5 text-xs font-medium transition {{ $isSaved ? 'border-amber-300 bg-amber-50 text-amber-700 hover:bg-amber-100' : 'border-brand-ink/15 bg-white text-brand-moss hover:bg-brand-sand/40 hover:text-brand-ink' }}"
                            >
                                <x-dynamic-component :component="$isSaved ? 'heroicon-s-star' : 'heroicon-o-star'" class="h-4 w-4 shrink-0" aria-hidden="true" />
                            </button>
                            @php
                                $ctaLabel = $item->ctaLabel();
                                $ctaIsDownload = $ctaLabel !== null && str_contains(strtolower((string) $item->title.' '.(string) $item->body), 'download');
                            @endphp
                            @if ($ctaLabel)
                                <button
                                    type="button"
                                    wire:click="openItem('{{ $item->id }}')"
                                    class="inline-flex items-center gap-1.5 rounded-lg bg-brand-forest px-3 py-1.5 text-xs font-semibold text-brand-cream shadow-sm transition hover:bg-brand-ink"
                                >
                                    <x-dynamic-component :component="$ctaIsDownload ? 'heroicon-o-arrow-down-tray' : 'heroicon-o-arrow-up-right'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                    {{ $ctaLabel }}
                                </button>
                            @endif
                            @if (! $item->read_at)
                                <button
                                    type="button"
                                    wire:click="markAsRead('{{ $item->id }}')"
                                    class="rounded-lg border border-brand-ink/15 bg-white px-3 py-1.5 text-xs font-medium text-brand-ink hover:bg-brand-sand/40"
                                >
                                    {{ __('Mark read') }}
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="openConfirmActionModal('deleteItem', [@js((string) $item->id)], @js($isSaved ? __('Delete this saved notification?') : __('Delete this notification?')), @js(__('This cannot be undone.')), @js(__('Delete')), true)"
                                title="{{ __('Delete') }}"
                                class="rounded-lg border border-rose-200 bg-white px-2 py-1.5 text-xs font-medium text-rose-700 hover:bg-rose-50"
                            >
                                <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                            </button>
                        </x-slot>
                    </x-notification-card>
                </div>
            @empty
                <div class="dply-card flex flex-col items-center gap-3 px-6 py-14 text-center">
                    <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                        <x-heroicon-o-bell-slash class="h-6 w-6" aria-hidden="true" />
                    </span>
                    @if (! $notificationsReady)
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Notifications not ready') }}</p>
                        <p class="max-w-sm text-sm text-brand-moss">{{ __('They’ll appear here after the latest database migrations are applied.') }}</p>
                    @elseif ($filter === 'saved')
                        <p class="text-sm font-semibold text-brand-ink">{{ __('No saved notifications') }}</p>
                        <p class="max-w-sm text-sm text-brand-moss">{{ __('Star a notification to keep it here — saved items survive “clear all” and auto-cleanup.') }}</p>
                    @elseif ($filter === 'unread')
                        <p class="text-sm font-semibold text-brand-ink">{{ __('You’re all caught up') }}</p>
                        <p class="max-w-sm text-sm text-brand-moss">{{ __('No unread notifications. Switch to “All” to see your history.') }}</p>
                        <button type="button" wire:click="$set('filter', 'all')" class="mt-1 text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('View all notifications') }}</button>
                    @else
                        <p class="text-sm font-semibold text-brand-ink">{{ __('No notifications yet') }}</p>
                        <p class="max-w-sm text-sm text-brand-moss">{{ __('Deploys, monitoring alerts, and SSL events will show up here.') }}</p>
                    @endif
                </div>
            @endforelse
        </div>
        </x-profile-shell>
    </div>

    @include('livewire.partials.confirm-action-modal')
</div>
