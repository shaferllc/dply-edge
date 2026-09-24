@php
    $notificationMenuActive = request()->routeIs('notifications.*');
    // Severity → leading icon + colour treatment.
    $notificationTones = [
        'danger' => ['icon' => 'x-circle', 'wrap' => 'bg-red-50 text-red-600 ring-red-200', 'dot' => 'bg-red-500'],
        'warning' => ['icon' => 'exclamation-triangle', 'wrap' => 'bg-amber-50 text-amber-600 ring-amber-200', 'dot' => 'bg-amber-500'],
        'success' => ['icon' => 'check-circle', 'wrap' => 'bg-brand-sage/15 text-brand-forest ring-brand-sage/25', 'dot' => 'bg-brand-sage'],
        'info' => ['icon' => 'information-circle', 'wrap' => 'bg-sky-50 text-sky-600 ring-sky-200', 'dot' => 'bg-sky-500'],
    ];
@endphp

<div class="flex shrink-0 items-center border-l border-brand-ink/10 ps-1.5 lg:ps-2" aria-label="{{ __('Notifications') }}">
    <x-dropdown align="right" width="24rem" contentClasses="p-0 overflow-hidden">
        <x-slot name="trigger">
            <button
                type="button"
                {{-- First open fetches the panel contents; see Bell::render(). --}}
                @unless ($loaded) wire:click="load" @endunless
                class="group inline-flex shrink-0 items-center gap-1 whitespace-nowrap px-2 py-2 border-b-2 text-sm font-medium leading-5 transition duration-150 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-gold/40 rounded-t {{ $notificationMenuActive ? 'border-brand-gold text-brand-ink' : 'border-transparent text-brand-moss hover:text-brand-ink hover:border-brand-sage/40' }}"
                aria-haspopup="menu"
            >
                <span class="relative inline-flex">
                    <x-heroicon-o-bell class="h-5 w-5 shrink-0 opacity-90" />
                    @if ($unreadCount > 0)
                        <span class="absolute -right-1.5 -top-1.5 inline-flex h-4 min-w-4 items-center justify-center rounded-full bg-brand-gold px-1 text-xs font-bold leading-none text-edge-void ring-2 ring-brand-cream">
                            {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                        </span>
                    @endif
                </span>
                <span class="sr-only">{{ __('Notifications') }}</span>
                <x-heroicon-m-chevron-down class="h-3.5 w-3.5 shrink-0 opacity-70" />
            </button>
        </x-slot>
        <x-slot name="content">
            {{-- Header: title, unread pill, inbox link. --}}
            <div class="flex items-center justify-between gap-3 border-b border-brand-ink/10 bg-brand-sand/25 px-4 py-3">
                <div class="flex items-center gap-2">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-white text-brand-ink ring-1 ring-brand-ink/10">
                        <x-heroicon-o-bell class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div>
                        <p class="text-sm font-semibold text-brand-ink">{{ __('Notifications') }}</p>
                        <p class="text-xs text-brand-moss">
                            @if ($unreadCount > 0)
                                <span class="font-semibold text-brand-ink">{{ $unreadCount }}</span> {{ __('unread') }}
                            @else
                                {{ __('You’re all caught up') }}
                            @endif
                        </p>
                    </div>
                </div>
                @if ($ready)
                    <a href="{{ route('notifications.index') }}" class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-brand-moss shadow-sm ring-1 ring-brand-ink/10 transition hover:bg-brand-cream hover:text-brand-ink">
                        {{ __('Inbox') }}
                        <x-heroicon-m-arrow-up-right class="h-3.5 w-3.5 shrink-0 opacity-80" aria-hidden="true" />
                    </a>
                @endif
            </div>

            {{-- Filters (category chips + alerts toggle) + Clear all. Shown
                 whenever there are unread items so the filter can be changed even
                 when the current filter has emptied the list. --}}
            @if ($loaded && $unreadCount > 0)
                @php
                    $chipBase = 'inline-flex shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold transition';
                    $chipOn = 'bg-brand-ink text-brand-cream';
                    $chipOff = 'bg-brand-sand/50 text-brand-moss hover:bg-brand-sand/80 hover:text-brand-ink';
                @endphp
                {{-- Stop clicks here from bubbling to the dropdown panel's
                     @click="close()" — filtering/clearing must keep the bell open. --}}
                <div class="flex items-center gap-2 border-b border-brand-ink/5 bg-white px-3 py-1.5" x-on:click.stop>
                    <div class="flex min-w-0 flex-1 items-center gap-1 overflow-x-auto pb-0.5">
                        <button type="button" wire:click="resetFilters" class="{{ $chipBase }} {{ ($categoryFilters === [] && ! $alertsOnly) ? $chipOn : $chipOff }}">{{ __('All') }}</button>
                        <button type="button" wire:click="toggleAlertsOnly" class="{{ $chipBase }} {{ $alertsOnly ? 'bg-amber-500 text-white' : $chipOff }}">
                            <x-heroicon-m-exclamation-triangle class="h-3 w-3 shrink-0" aria-hidden="true" />
                            {{ __('Alerts') }}
                        </button>
                        @foreach ($categories as $cat)
                            @php $catOn = in_array($cat, $categoryFilters, true); @endphp
                            <button type="button" wire:click="toggleCategory('{{ $cat }}')" class="{{ $chipBase }} {{ $catOn ? $chipOn : $chipOff }}">
                                @if ($catOn)
                                    <x-heroicon-m-check class="h-3 w-3 shrink-0" aria-hidden="true" />
                                @endif
                                {{ strtoupper(str_replace('_', ' ', $cat)) }}
                            </button>
                        @endforeach
                    </div>
                    <button type="button" wire:click="markAllAsRead" class="inline-flex shrink-0 items-center gap-1.5 text-xs font-semibold text-brand-moss transition hover:text-brand-ink">
                        <x-heroicon-o-check class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        {{ __('Clear all') }}
                    </button>
                </div>
            @endif

            {{-- Unread items. --}}
            <div class="max-h-[26rem] overflow-y-auto">
                @unless ($loaded)
                    {{-- First open: the contents are in flight. --}}
                    <div class="space-y-2 px-4 py-5" aria-busy="true">
                        @foreach (range(1, min(3, max(1, (int) $unreadCount))) as $skeletonRow)
                            <div class="flex items-start gap-3">
                                <div class="h-8 w-8 shrink-0 animate-pulse rounded-lg bg-brand-sand/60"></div>
                                <div class="min-w-0 flex-1 space-y-1.5">
                                    <div class="h-3 w-2/3 animate-pulse rounded bg-brand-sand/60"></div>
                                    <div class="h-2.5 w-1/3 animate-pulse rounded bg-brand-sand/40"></div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                @forelse ($items as $notificationItem)
                    @php
                        $event = $notificationItem->event;
                        $isResolved = str_contains(strtolower((string) $notificationItem->title), 'resolved');
                        $tone = $isResolved ? 'success' : match ($event?->severity) {
                            'critical', 'error', 'danger' => 'danger',
                            'warning' => 'warning',
                            'success', 'ok' => 'success',
                            default => 'info',
                        };
                        $visual = $notificationTones[$tone];
                        $category = $event?->category;
                        $isSaved = $notificationItem->saved_at !== null;
                    @endphp
                    <div class="group relative flex items-start gap-2 border-b border-brand-ink/5 px-3 py-3 last:border-b-0 transition hover:bg-brand-sand/30">
                        <span class="absolute inset-y-0 left-0 w-0.5 bg-brand-gold" aria-hidden="true"></span>
                        <button type="button" wire:click="openItem('{{ $notificationItem->id }}')" class="flex min-w-0 flex-1 gap-3 pl-1 text-left">
                            <span class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg ring-1 {{ $visual['wrap'] }}" aria-hidden="true">
                                <x-dynamic-component :component="'heroicon-o-'.$visual['icon']" class="h-[1.05rem] w-[1.05rem]" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="line-clamp-2 block text-sm font-semibold leading-snug text-brand-ink">{{ $notificationItem->title }}</span>
                                @if ($notificationItem->body)
                                    <span class="mt-1 line-clamp-2 block text-xs leading-relaxed text-brand-moss">{{ $notificationItem->body }}</span>
                                @endif
                                <span class="mt-1.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-brand-mist">
                                    @if ($category)
                                        <span class="inline-flex items-center rounded-md bg-brand-ink/[0.045] px-1.5 py-0.5 font-semibold uppercase tracking-wide text-brand-moss ring-1 ring-brand-ink/[0.06]">{{ str_replace('_', ' ', $category) }}</span>
                                    @endif
                                    @if ($notificationItem->created_at)
                                        <span class="inline-flex items-center gap-1">
                                            <x-heroicon-m-clock class="h-3 w-3 shrink-0" aria-hidden="true" />
                                            {{ $notificationItem->created_at->diffForHumans(short: true) }}
                                        </span>
                                    @endif
                                </span>
                                @php
                                    $ctaLabel = $notificationItem->ctaLabel();
                                    $ctaIsDownload = $ctaLabel !== null && str_contains(strtolower((string) $notificationItem->title.' '.(string) $notificationItem->body), 'download');
                                @endphp
                                @if ($ctaLabel)
                                    {{-- Visual button affordance — the row's openItem handles the click. --}}
                                    <span class="mt-2 inline-flex w-fit items-center gap-1.5 rounded-lg bg-brand-forest px-2.5 py-1 text-xs font-semibold text-brand-cream shadow-sm transition group-hover:bg-brand-ink">
                                        <x-dynamic-component :component="$ctaIsDownload ? 'heroicon-m-arrow-down-tray' : 'heroicon-m-arrow-up-right'" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                        {{ $ctaLabel }}
                                    </span>
                                @endif
                            </span>
                        </button>
                        <button
                            type="button"
                            wire:click="toggleSaved('{{ $notificationItem->id }}')"
                            x-on:click.stop
                            title="{{ $isSaved ? __('Saved — click to unsave') : __('Save to remember') }}"
                            class="mt-0.5 shrink-0 rounded-lg p-1.5 transition {{ $isSaved ? 'text-amber-500 hover:bg-amber-50' : 'text-brand-mist hover:bg-brand-sand/50 hover:text-brand-moss' }}"
                        >
                            <x-dynamic-component :component="$isSaved ? 'heroicon-s-star' : 'heroicon-o-star'" class="h-4 w-4 shrink-0" aria-hidden="true" />
                        </button>
                    </div>
                @empty
                    @if ($ready && $unreadCount > 0)
                        {{-- Unread exist, but the active filter matched none. --}}
                        <div class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sand/45 text-brand-mist ring-1 ring-brand-ink/10">
                                <x-heroicon-o-funnel class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="text-sm font-medium text-brand-ink">{{ __('Nothing matches this filter') }}</p>
                            <button type="button" wire:click="resetFilters" x-on:click.stop class="text-xs font-semibold text-brand-sage hover:text-brand-ink">{{ __('Show all unread') }}</button>
                        </div>
                    @else
                        <div class="flex flex-col items-center gap-2 px-4 py-10 text-center">
                            <span class="flex h-11 w-11 items-center justify-center rounded-xl bg-brand-sage/12 text-brand-forest ring-1 ring-brand-sage/25">
                                <x-heroicon-o-check class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <p class="text-sm font-medium text-brand-ink">
                                {{ $ready ? __('You’re all caught up') : __('Notifications not ready') }}
                            </p>
                            <p class="max-w-[16rem] text-xs text-brand-moss">
                                {{ $ready
                                    ? __('No unread notifications. Read history and saved items live in the inbox.')
                                    : __('They’ll appear here once the latest database migrations are applied.') }}
                            </p>
                        </div>
                    @endif
                @endforelse
                @endunless
            </div>

            {{-- Footer. --}}
            @if ($ready)
                <a href="{{ route('notifications.index') }}" class="flex items-center justify-center gap-1.5 border-t border-brand-ink/10 bg-white px-4 py-2.5 text-xs font-semibold text-brand-moss transition hover:bg-brand-sand/30 hover:text-brand-ink">
                    {{ __('View all notifications') }}
                    <x-heroicon-m-arrow-right class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                </a>
            @endif
        </x-slot>
    </x-dropdown>
</div>
