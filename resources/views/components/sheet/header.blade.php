@props([
    /** Small label above the title: what this sheet belongs to. */
    'eyebrow' => null,
    'title',
    /** Livewire expression that clears this sheet's state when it closes, e.g. "$set('valkeyHost', '')". */
    'closeWire' => null,
])

{{-- Top of a sheet. ← shows when the sheet sits on another one and goes back
     to it; ✕ closes every sheet. Reads `index` / `name` from <x-sheet>'s scope. --}}
<header class="sticky top-0 z-10 flex items-start gap-2 border-b border-brand-ink/10 bg-white/95 px-5 pb-3 pt-4 backdrop-blur dark:border-brand-mist/15 dark:bg-zinc-900/95">
    <button
        type="button"
        x-show="index > 0"
        x-on:click="$store.sheets.close(name)"
        @if ($closeWire) wire:click="{{ $closeWire }}" @endif
        class="-ms-1.5 mt-0.5 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink"
        aria-label="{{ __('Back') }}"
    >
        <x-heroicon-o-arrow-left class="h-4 w-4" aria-hidden="true" />
    </button>
    <div class="min-w-0 flex-1">
        @if ($eyebrow)
            <p class="truncate font-mono text-2xs text-brand-mist">{{ $eyebrow }}</p>
        @endif
        <h2 class="text-base font-semibold tracking-tight text-brand-ink">{{ $title }}</h2>
        @if (trim((string) $slot) !== '')
            <div class="mt-0.5 text-xs text-brand-moss">{{ $slot }}</div>
        @endif
    </div>
    {{ $actions ?? '' }}
    <button
        type="button"
        x-on:click="$store.sheets.clear()"
        @if ($closeWire) wire:click="{{ $closeWire }}" @endif
        class="-me-1.5 mt-0.5 rounded-lg p-1 text-brand-mist hover:bg-brand-sand/40 hover:text-brand-ink"
        aria-label="{{ __('Close') }}"
        title="{{ __('Close') }}"
    >
        <x-heroicon-o-x-mark class="h-4 w-4" aria-hidden="true" />
    </button>
</header>
