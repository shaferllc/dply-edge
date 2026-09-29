<div>
<section class="rounded-2xl border border-brand-ink/10 bg-white p-6 shadow-sm">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div class="min-w-0">
            <h3 class="text-base font-semibold text-brand-ink">{{ $heading }}</h3>
            <p class="mt-1 text-sm text-brand-moss">
                {{ __('Every resource can now publish into one shared notification stream while still routing copies to subscribed destinations.') }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 lg:shrink-0 lg:justify-end">
            @if ($manageUrl)
                <x-secondary-button size="sm" href="{{ $manageUrl }}" wire:navigate>
                    <x-heroicon-o-adjustments-horizontal class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Manage routing') }}
                </x-secondary-button>
            @endif
            @if ($tablesReady)
                <x-secondary-button size="sm" href="{{ route('notifications.index') }}" wire:navigate>
                    <x-heroicon-o-inbox class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Open inbox') }}
                </x-secondary-button>
            @endif
            @if ($items->isNotEmpty())
                <button type="button" wire:click="openClearConfirm" class="inline-flex items-center justify-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-red-700 shadow-sm transition-colors hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50">
                    <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                    {{ __('Clear all') }}
                </button>
            @endif
        </div>
    </div>

    <div class="mt-4 space-y-3">
        @forelse ($items as $item)
            @php
                $meta = is_array($item->metadata ?? null) ? $item->metadata : [];
                $itemSeverity = $item->severity ?? ($meta['severity'] ?? null);
                $itemResolved = strtolower((string) ($meta['insight_state'] ?? '')) === 'resolved';
            @endphp
            <x-notification-card
                :severity="$itemSeverity"
                :is-resolved="$itemResolved"
                :title="$item->title"
                :body="$item->body"
                :category="$item->category"
                :time="$item->created_at?->diffForHumans()"
                :url="$item->url"
            />
        @empty
            <div class="rounded-xl border border-dashed border-brand-ink/15 bg-brand-sand/10 p-4 text-sm text-brand-moss">
                {{ $tablesReady
                    ? __('No notifications have been published for this resource yet.')
                    : __('Notifications will appear here after the latest database migrations are applied.') }}
            </div>
        @endforelse
    </div>
</section>

@if ($showClearConfirm)
    <div
        class="fixed inset-0 z-50 overflow-y-auto"
        role="dialog"
        aria-modal="true"
        aria-labelledby="resource-clear-confirm-title"
        x-data
        x-trap="true"
        x-on:keydown.escape.window="$wire.closeClearConfirm()"
    >
        <div class="fixed inset-0 bg-brand-ink/50 backdrop-blur-sm" wire:click="closeClearConfirm"></div>
        <div class="relative flex min-h-full items-center justify-center px-4 py-10 sm:px-6">
            <div class="relative w-full max-w-md dply-modal-panel" wire:click.stop>
                <div class="border-b border-brand-ink/10 px-6 py-4 sm:px-7">
                    <h2 id="resource-clear-confirm-title" class="text-lg font-semibold text-brand-ink">{{ __('Clear notifications') }}</h2>
                </div>
                <div class="space-y-3 px-6 py-5 sm:px-7">
                    <p class="text-sm leading-relaxed text-brand-moss">
                        {{ __('Wipe the notifications shown on this panel? They will stay in the shared inbox at /notifications, just hidden from this resource view.') }}
                    </p>
                </div>
                <div class="flex flex-col-reverse gap-2 border-t border-brand-ink/10 px-6 py-4 sm:flex-row sm:justify-end sm:gap-3 sm:px-7">
                    <button
                        type="button"
                        wire:click="closeClearConfirm"
                        class="inline-flex items-center justify-center rounded-lg border border-brand-ink/15 bg-white px-4 py-2.5 text-sm font-semibold text-brand-ink shadow-sm hover:bg-brand-sand/50"
                    >
                        {{ __('Cancel') }}
                    </button>
                    <button
                        type="button"
                        wire:click="clearAll"
                        wire:loading.attr="disabled"
                        class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-red-700 disabled:opacity-50"
                    >
                        <x-heroicon-o-trash class="h-4 w-4 shrink-0" aria-hidden="true" />
                        <span wire:loading.remove wire:target="clearAll">{{ __('Clear all') }}</span>
                        <span wire:loading wire:target="clearAll">{{ __('Clearing…') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
@endif
</div>
